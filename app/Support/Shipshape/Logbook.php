<?php

namespace App\Support\Shipshape;

use App\Models\Chore;
use App\Models\ChoreEntry;
use App\Models\CrewMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing to the ship's log (spec §25): done, skipped, and voided.
 *
 * Every change to a chore's schedule goes through here so the rules live in
 * one place: one live due date, the rota advancing only on a completion
 * (from whoever's turn it was, not whoever did it), stale ticks refused by
 * version, and voiding the latest live entry restoring what it replaced.
 */
final class Logbook
{
    /**
     * @throws ValidationException when the chore changed since the page was
     *                             loaded, the date is in the future, or a
     *                             fixed chore has no date yet
     */
    public static function record(
        Chore $chore,
        User $loggedBy,
        string $kind,
        CarbonImmutable $doneOn,
        ?CrewMember $doneBy,
        int $expectedVersion,
        ?string $reason = null,
        string $via = 'app',
    ): ChoreEntry {
        // Chores are dates: compare as plain dates, never across timezones.
        $today = ChoreSchedule::date($loggedBy->today());
        $doneOn = ChoreSchedule::date($doneOn);

        if ($doneOn->greaterThan($today)) {
            throw ValidationException::withMessages(['done_on' => "That's in the future — log it once it's done."]);
        }

        return DB::transaction(function () use ($chore, $loggedBy, $kind, $doneOn, $doneBy, $expectedVersion, $reason, $via, $today) {
            $chore = Chore::query()->lockForUpdate()->findOrFail($chore->id);

            if ($chore->version !== $expectedVersion) {
                $latest = $chore->entries()->whereNull('voided_at')->latest('id')->first();
                $who = $latest->done_by_name ?? 'Someone';

                throw ValidationException::withMessages(['chore' => "{$who} just updated “{$chore->title}” — have a look before logging it again."]);
            }

            if ($chore->isFixed() && $chore->anchor_on === null) {
                throw ValidationException::withMessages(['chore' => "“{$chore->title}” needs a date before it can be logged."]);
            }

            $before = [
                'due_on' => $chore->due_on?->toDateString(),
                'rota_position' => $chore->rota_position,
            ];

            $entry = $chore->entries()->create([
                'kind' => $kind,
                'done_on' => $doneOn->toDateString(),
                'done_by_member_id' => $doneBy?->id,
                'done_by_name' => $doneBy?->displayName() ?? $loggedBy->name,
                'chore_title' => $chore->title,
                'logged_by_user_id' => $loggedBy->id,
                'via' => $via,
                'reason' => $reason,
                'before' => $before,
            ]);

            $chore->due_on = ChoreSchedule::nextDue($chore, $kind, $doneOn, $today);

            if ($kind === ChoreEntry::DONE && $chore->assign === Chore::ROTA) {
                $size = $chore->rotaMembers()->count();
                $chore->rota_position = $size > 0 ? ($chore->rota_position + 1) % $size : 0;
            }

            $chore->version++;
            $chore->save();

            return $entry;
        });
    }

    /**
     * Strike an entry. If it's the chore's most recent live entry, the chore
     * goes back to how it was before it; an older one only leaves history
     * and stats, since later entries already superseded its effect.
     */
    public static function void(ChoreEntry $entry, User $by, string $reason = 'mistake'): void
    {
        DB::transaction(function () use ($entry, $by, $reason) {
            $entry = ChoreEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->voided_at !== null) {
                return;
            }

            $chore = Chore::withTrashed()->lockForUpdate()->findOrFail($entry->chore_id);
            $latest = $chore->entries()->whereNull('voided_at')->latest('id')->first();

            $entry->forceFill(['voided_at' => now(), 'voided_by_user_id' => $by->id, 'void_reason' => $reason])->save();

            if ($latest?->is($entry)) {
                $chore->due_on = $entry->before['due_on'] ?? null;
                $chore->rota_position = (int) ($entry->before['rota_position'] ?? 0);
                $chore->version++;
                $chore->save();
            }
        });
    }
}
