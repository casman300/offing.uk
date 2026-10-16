<?php

namespace App\Support\Reminders;

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Mail\BirthdayReminderMail;
use App\Models\OutboxMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Job three of the cron pipeline (spec §11, #17): sends due outbox rows.
 *
 * Rows sharing user + rule + send_at go out as one email ("Tomorrow: Sue
 * and Tom"). Everything is re-checked at send time — reminders still on,
 * flags still active, people not deleted since — and a failure retries
 * with OutboxMessage::BACKOFF before being marked failed.
 */
final class OutboxDrainer
{
    /**
     * @return array{sent: int, skipped: int, failed: int, retrying: int}
     */
    public function drain(CarbonImmutable $now, int $limit = 500): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retrying' => 0];

        OutboxMessage::query()
            ->where('status', OutboxMessage::PENDING)
            ->where('channel', 'email')
            ->where('send_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('send_at')
            ->limit($limit)
            ->get()
            ->groupBy(fn (OutboxMessage $m) => "{$m->user_id}|{$m->reminder_rule_id}|{$m->send_at->getTimestamp()}")
            ->each(function (Collection $batch) use ($now, &$totals) {
                $totals[$this->sendBatch($batch, $now)] += $batch->count();
            });

        return $totals;
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     */
    private function sendBatch(Collection $batch, CarbonImmutable $now): string
    {
        /** @var OutboxMessage $first */
        $first = $batch->first();
        $user = User::find($first->user_id);

        if ($user === null || ! $user->remindersOn()
            || ! Feature::for($user)->allAreActive([BirthdayEngine::class, Reminders::class])) {
            return $this->mark($batch, OutboxMessage::SKIPPED, 'Reminders were turned off before sending.');
        }

        $people = $user->people()->whereIn('id', $batch->pluck('payload.person_id'))->get()->keyBy('id');
        $entries = $batch
            ->filter(fn (OutboxMessage $m) => $people->has($m->payload['person_id']))
            ->map(fn (OutboxMessage $m) => [
                'person' => $people[$m->payload['person_id']],
                'date' => CarbonImmutable::parse($m->payload['date']),
                'turns' => $m->payload['turns'],
            ])
            ->sortBy(fn (array $e) => mb_strtolower($e['person']->name))
            ->values()
            ->all();

        if ($entries === []) {
            return $this->mark($batch, OutboxMessage::SKIPPED, 'Everyone in this reminder was deleted before sending.');
        }

        try {
            Mail::to($user->email)->send(new BirthdayReminderMail($user, $entries, (int) $first->payload['days_before']));
        } catch (Throwable $e) {
            return $this->failed($batch, $now, $e);
        }

        $batch->each->update(['status' => OutboxMessage::SENT, 'sent_at' => $now, 'last_error' => null, 'attempts' => $first->attempts + 1]);

        return 'sent';
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     */
    private function failed(Collection $batch, CarbonImmutable $now, Throwable $e): string
    {
        $attempts = $batch->first()->attempts + 1;
        $error = Str::limit(class_basename($e).': '.$e->getMessage(), 1000);
        $wait = OutboxMessage::BACKOFF[$attempts - 1] ?? null;

        if ($wait === null) {
            $batch->each->update(['status' => OutboxMessage::FAILED, 'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => null]);
            report($e);

            return 'failed';
        }

        $batch->each->update(['attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => $now->addMinutes($wait)]);

        return 'retrying';
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     */
    private function mark(Collection $batch, string $status, string $reason): string
    {
        $batch->each->update(['status' => $status, 'last_error' => $reason]);

        return $status;
    }
}
