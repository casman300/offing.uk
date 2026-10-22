<?php

namespace App\Mcp\Tools;

use App\Features\Shipshape;
use App\Mcp\Text;
use App\Mcp\Tools\Concerns\ActsForGrant;
use App\Models\Chore;
use App\Models\ChoreEntry;
use App\Models\CrewMember;
use App\Models\User;
use App\Support\ConnectClaude\AccessLevel;
use App\Support\Shipshape\Board;
use App\Support\Shipshape\Logbook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Pennant\Feature;

/**
 * "I've done the bins" (spec §12, §25): tick off or skip a Shipshape chore
 * through the same Logbook as the app, or undo an entry. Logging needs
 * "Read and add new"; undoing needs "Read, add and change". Creating or
 * changing chores and lists stays in the app.
 *
 * Like the app, the log is its own history (entries record `via: ai`), so
 * this isn't written to audit_log beyond the per-call tool audit.
 */
#[Name('log_chore')]
#[Title('Log chore')]
#[Description("Mark a Shipshape chore done (or skipped) by chore_id, or undo a log entry by undo_entry_id. Done moves the chore's next due date and, on a rota, passes the turn on. done_on defaults to today; done_by_member_id credits a crewmate (member ids come from find with crew_id or chore_id). The result gives the entry id, so you can undo a mistake straight away.")]
#[IsDestructive]
class LogChore extends Tool
{
    use ActsForGrant;

    /** A repeat of the same log this soon is a retry, not a second chore done. */
    public const DUPLICATE_SECONDS = 120;

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && Feature::for($user)->active(Shipshape::class) && $this->allows(AccessLevel::WriteNew);
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'chore_id' => ['required_without:undo_entry_id', 'prohibits:undo_entry_id', 'nullable', 'integer'],
            'undo_entry_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in([ChoreEntry::DONE, ChoreEntry::SKIP])],
            'done_on' => ['nullable', 'date_format:Y-m-d'],
            'done_by_member_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'chore_id.required_without' => 'Give chore_id to log a chore, or undo_entry_id to undo an entry.',
            'chore_id.prohibits' => 'Give chore_id or undo_entry_id, not both.',
        ]);

        return isset($data['undo_entry_id'])
            ? $this->undo($user, (int) $data['undo_entry_id'])
            : $this->log($user, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chore_id' => $schema->integer()->description('The chore to log, e.g. 4 from "(chore 4)".'),
            'kind' => $schema->string()->enum([ChoreEntry::DONE, ChoreEntry::SKIP])->description('done (default) or skip.'),
            'done_on' => $schema->string()->description('YYYY-MM-DD, today or earlier. Defaults to today.'),
            'done_by_member_id' => $schema->integer()->description('Crew lists only: who did it, if not the user.'),
            'reason' => $schema->string()->max(200)->description('Skips only: why, e.g. "away".'),
            'undo_entry_id' => $schema->integer()->description('Instead of logging: the entry to undo, e.g. 55 from "(entry 55)".'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(User $user, array $data): Response
    {
        $chore = Chore::query()->with('list')->find((int) $data['chore_id']);

        if ($chore === null || ! $chore->list?->viewableBy($user)) {
            return Response::error("No chore {$data['chore_id']} on the user's lists — check the ids in get_briefing or find.");
        }

        $kind = $data['kind'] ?? ChoreEntry::DONE;
        $doneOn = isset($data['done_on']) ? CarbonImmutable::parse($data['done_on']) : $user->today();
        $me = $chore->list->membershipOf($user);
        $doneBy = $me;

        if (isset($data['done_by_member_id']) && (int) $data['done_by_member_id'] !== $me?->id) {
            $doneBy = $chore->list->isCrewList()
                ? CrewMember::query()->active()->where('crew_id', $chore->list->owner_id)->find((int) $data['done_by_member_id'])
                : null;

            if ($doneBy === null) {
                return Response::error("Member {$data['done_by_member_id']} isn't aboard the crew that owns “{$chore->title}”. Use find with chore_id to see who can be credited.");
            }
        }

        $repeat = $this->recentTwin($chore, $user, $kind, $doneOn);

        if ($repeat !== null) {
            return Response::text("Already logged a moment ago (entry {$repeat->id}) — not logged twice. ".$this->nextDue($chore->fresh(), $user));
        }

        $entry = Logbook::record(
            $chore,
            $user,
            $kind,
            $doneOn,
            $doneBy,
            $chore->version,
            $kind === ChoreEntry::SKIP ? ($data['reason'] ?? null) : null,
            via: 'ai',
        );

        $what = $kind === ChoreEntry::DONE ? 'done' : 'skipped';
        $by = $doneBy !== null && $doneBy->user_id !== $user->id ? " by {$entry->done_by_name}" : '';

        return Response::text("Logged “{$chore->title}” {$what}{$by} on ".Text::date($entry->done_on)." (entry {$entry->id}). ".$this->nextDue($chore->fresh(), $user));
    }

    private function undo(User $user, int $entryId): Response
    {
        if (! $this->allows(AccessLevel::Edit)) {
            return $this->notAllowed(AccessLevel::Edit, 'undo chore log entries');
        }

        $entry = ChoreEntry::query()->find($entryId);
        $chore = $entry ? Chore::withTrashed()->with('list')->find($entry->chore_id) : null;

        if ($chore === null || ! $chore->list?->viewableBy($user)) {
            return Response::error("No log entry {$entryId} on the user's lists.");
        }

        if ($entry->voided_at !== null) {
            return Response::text("Entry {$entryId} was already undone.");
        }

        Logbook::void($entry, $user, 'undo');

        return Response::text("Undone: “{$entry->chore_title}” on ".Text::date($entry->done_on)." (entry {$entryId}). ".$this->nextDue($chore->fresh(), $user));
    }

    /**
     * The same log from this user in the last couple of minutes: a client
     * retrying a call, not the bins going out twice.
     */
    private function recentTwin(Chore $chore, User $user, string $kind, CarbonImmutable $doneOn): ?ChoreEntry
    {
        return $chore->entries()
            ->whereNull('voided_at')
            ->where('logged_by_user_id', $user->id)
            ->where('kind', $kind)
            ->whereDate('done_on', $doneOn->toDateString())
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_SECONDS))
            ->latest('id')
            ->first();
    }

    private function nextDue(?Chore $chore, User $user): string
    {
        if ($chore === null || $chore->trashed()) {
            return '';
        }

        $row = Board::chore($chore->load(['assignee.user:id,name', 'assignee.person:id,name', 'rotaMembers.user:id,name', 'rotaMembers.person:id,name']), $user, $user->today());

        return 'Now: '.ltrim(Text::chore($row, $chore->list->name), '- ').'.';
    }
}
