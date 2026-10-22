<?php

namespace App\Http\Controllers\Shipshape;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shipshape\Concerns\FindsChores;
use App\Models\AuditLog;
use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Support\Shipshape\Board;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shipshape lists (spec §25): the sharing unit, owned by you or a crew.
 * A crew list's creation, edit-switch changes and deletion are audited —
 * they're sharing changes. Changing the switch is captains only (§7).
 */
class ChoreListController extends Controller
{
    use FindsChores;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $lists = Board::visibleLists($user)->loadCount('chores');

        return Inertia::render('shipshape/lists/index', [
            'lists' => $lists->map(fn (ChoreList $list) => [
                'id' => $list->id,
                'name' => $list->name,
                'owner' => $list->ownerLabel(),
                'is_crew' => $list->isCrewList(),
                'chore_count' => (int) $list->getAttribute('chores_count'),
            ])->values(),
            'crews' => $user->crewMemberships()->whereHas('crew')->with('crew:id,name')->get()
                ->map(fn (CrewMember $m) => ['id' => $m->crew->id, 'name' => $m->crew->name])
                ->sortBy('name')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'owner' => ['required', 'string'],
        ]);

        $list = new ChoreList(['name' => trim($data['name']), 'created_by_user_id' => $user->id]);

        if ($data['owner'] === 'me') {
            $list->owner()->associate($user);
        } else {
            $crew = Crew::query()->findOrFail((int) $data['owner']);
            abort_if($crew->membershipOf($user) === null, 404);
            $list->owner()->associate($crew);
        }

        $list->save();

        if ($list->isCrewList()) {
            AuditLog::record($user, 'chore_list.created', $list, ['crew_id' => $list->owner_id, 'name' => $list->name], $request->ip());
        }

        return to_route('shipshape.starters.show', $list);
    }

    public function show(Request $request, int $list): Response
    {
        $user = $request->user();
        $model = $this->viewableList($user, $list);
        $today = $user->today();
        $me = $model->membershipOf($user);

        return Inertia::render('shipshape/lists/show', [
            'today' => $today->toDateString(),
            'list' => Board::listSummary($model, $user) + [
                'editors' => $model->editors,
                'can_change_editors' => $me?->isCaptain() ?? false,
            ],
            'chores' => Board::choresOn(collect([$model]))
                ->map(fn (Chore $chore) => Board::chore($chore, $user, $today))
                ->sortBy([['days', 'asc'], ['title', 'asc']])
                ->values(),
            'deleted' => $model->chores()->onlyTrashed()->latest('deleted_at')->limit(10)->get(['id', 'title'])
                ->map(fn (Chore $chore) => ['id' => $chore->id, 'title' => $chore->title])->values(),
        ]);
    }

    public function update(Request $request, int $list): RedirectResponse
    {
        $user = $request->user();
        $model = $this->viewableList($user, $list);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'editors' => ['sometimes', Rule::in([ChoreList::EDITORS_CREW, ChoreList::EDITORS_CAPTAINS])],
        ]);

        if (array_key_exists('editors', $data) && $data['editors'] !== $model->editors) {
            abort_unless($model->membershipOf($user)?->isCaptain(), 403);
            AuditLog::record($user, 'chore_list.editors_changed', $model, ['from' => $model->editors, 'to' => $data['editors']], $request->ip());
            $model->editors = $data['editors'];
        }

        if (array_key_exists('name', $data)) {
            abort_unless($model->editableBy($user), 403);
            $model->name = trim($data['name']);
        }

        $model->save();

        return back()->with('status', 'Saved.');
    }

    public function destroy(Request $request, int $list): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableList($user, $list);

        $model->delete();

        if ($model->isCrewList()) {
            AuditLog::record($user, 'chore_list.deleted', $model, ['name' => $model->name], $request->ip());
        }

        return to_route('shipshape.board')->with('status', "Deleted “{$model->name}”.");
    }
}
