<?php

namespace App\Http\Controllers\Shipshape;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shipshape\Concerns\FindsChores;
use App\Models\ChoreEntry;
use App\Models\CrewMember;
use App\Support\Shipshape\Logbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ticking off, skipping and undoing (spec §25). Responding, not editing:
 * anyone who can see the list can do it, whatever its edit switch (§7).
 * The log is its own history, so these aren't also written to audit_log.
 */
class EntryController extends Controller
{
    use FindsChores;

    public function store(Request $request, int $chore): RedirectResponse
    {
        $user = $request->user();
        $model = $this->viewableChore($user, $chore);

        $data = $request->validate([
            'kind' => ['required', Rule::in([ChoreEntry::DONE, ChoreEntry::SKIP])],
            'done_on' => ['nullable', 'date_format:Y-m-d'],
            'done_by' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:200'],
            'version' => ['required', 'integer'],
        ]);

        $doneBy = $this->doneBy($model->list->membershipOf($user), $model->list->isCrewList() ? (int) $model->list->owner_id : null, $data['done_by'] ?? null);

        $entry = Logbook::record(
            $model,
            $user,
            $data['kind'],
            isset($data['done_on']) ? CarbonImmutable::parse($data['done_on']) : $user->today(),
            $doneBy,
            (int) $data['version'],
            $data['kind'] === ChoreEntry::SKIP ? ($data['reason'] ?? null) : null,
        );

        return back()->with('logged', ['entry_id' => $entry->id, 'title' => $model->title, 'kind' => $entry->kind, 'by' => $entry->done_by_name]);
    }

    public function undo(Request $request, int $entry): RedirectResponse
    {
        $user = $request->user();
        $model = ChoreEntry::query()->findOrFail($entry);
        $this->viewableChore($user, $model->chore_id, withTrashed: true);

        Logbook::void($model, $user, 'undo');

        return back()->with('status', "Undone — “{$model->chore_title}” is back how it was.");
    }

    /**
     * Who actually did it: on a crew list, any active member (a passenger
     * included — a crewmate logs it for them); by default, you. A private
     * list has no members — it's just you.
     */
    private function doneBy(?CrewMember $me, ?int $crewId, ?int $memberId): ?CrewMember
    {
        if ($crewId === null || $memberId === null || $memberId === $me?->id) {
            return $me;
        }

        return CrewMember::query()->active()->where('crew_id', $crewId)->findOrFail($memberId);
    }
}
