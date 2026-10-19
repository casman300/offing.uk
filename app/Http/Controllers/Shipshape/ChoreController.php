<?php

namespace App\Http\Controllers\Shipshape;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shipshape\Concerns\FindsChores;
use App\Models\Chore;
use App\Models\ChoreList;
use App\Support\Shipshape\Board;
use App\Support\Shipshape\ChoreSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adding and editing chores (spec §25). Every chore repeats; a fixed one
 * may be left without a date ("needs a date") but a floating one always
 * has one. Assignment only exists on crew lists — a private list is you.
 */
class ChoreController extends Controller
{
    use FindsChores;

    /** Suggestions for the free-text area label — never a fixed list. */
    private const AREAS = ['Kitchen', 'Bathroom', 'Bedroom', 'Whole house', 'Outside', 'Car', 'Admin', 'Home', 'Safety'];

    public function create(Request $request, int $list): Response
    {
        $user = $request->user();
        $model = $this->editableList($user, $list);

        return $this->form($model, $user, null);
    }

    public function store(Request $request, int $list): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableList($user, $list);

        $chore = DB::transaction(function () use ($request, $model, $user) {
            $chore = $model->chores()->make(['created_by_user_id' => $user->id]);
            $this->fill($chore, $request, $model);

            return $chore;
        });

        return to_route('shipshape.lists.show', $model)->with('status', "Added “{$chore->title}”.");
    }

    public function edit(Request $request, int $chore): Response
    {
        $user = $request->user();
        $model = $this->editableChore($user, $chore);

        return $this->form($model->list, $user, $model->load('rotaMembers'));
    }

    public function update(Request $request, int $chore): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableChore($user, $chore);

        DB::transaction(fn () => $this->fill($model, $request, $model->list));

        return to_route('shipshape.lists.show', $model->list)->with('status', "Saved “{$model->title}”.");
    }

    public function destroy(Request $request, int $chore): RedirectResponse
    {
        $model = $this->editableChore($request->user(), $chore);
        $model->delete();

        // Not back(): from the edit page that's the deleted chore, now a 404.
        return to_route('shipshape.lists.show', $model->chore_list_id)->with('status', "Deleted “{$model->title}” — its history stays in the log.");
    }

    public function restore(Request $request, int $chore): RedirectResponse
    {
        $model = $this->editableChore($request->user(), $chore, withTrashed: true);
        $model->restore();

        return back()->with('status', "“{$model->title}” is back.");
    }

    private function form(ChoreList $list, $user, ?Chore $chore): Response
    {
        return Inertia::render('shipshape/chore-form', [
            'today' => $user->today()->toDateString(),
            'list' => Board::listSummary($list, $user),
            'areas' => self::AREAS,
            'chore' => $chore === null ? null : [
                'id' => $chore->id,
                'title' => $chore->title,
                'area' => $chore->area,
                'notes' => $chore->notes,
                'mode' => $chore->mode,
                'every' => $chore->every,
                'unit' => $chore->unit,
                'due_on' => $chore->due_on?->toDateString(),
                'lead_days' => $chore->lead_days,
                'assign' => $chore->assign,
                'assignee_member_id' => $chore->assignee_member_id,
                'rota' => $chore->rotaMembers->pluck('id')->values(),
            ],
        ]);
    }

    private function fill(Chore $chore, Request $request, ChoreList $list): void
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mode' => ['required', Rule::in([Chore::FLOATING, Chore::FIXED])],
            'every' => ['required', 'integer', 'between:1,365'],
            'unit' => ['required', Rule::in(Chore::UNITS)],
            'due_on' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf($request->input('mode') === Chore::FLOATING)],
            'lead_days' => ['nullable', 'integer', 'between:1,120'],
            'assign' => ['required', Rule::in([Chore::ANYONE, Chore::MEMBER, Chore::ROTA])],
            'assignee_member_id' => ['nullable', 'integer'],
            'rota' => ['array'],
            'rota.*' => ['integer', 'distinct'],
        ], [
            'due_on.required' => 'When is it next due?',
        ]);

        $members = $list->assignableMembers()->keyBy('id');
        $assign = $list->isCrewList() ? $data['assign'] : Chore::ANYONE;
        $rota = collect($data['rota'] ?? [])->map(fn ($id) => (int) $id);

        if ($assign === Chore::MEMBER && ! $members->has((int) ($data['assignee_member_id'] ?? 0))) {
            throw ValidationException::withMessages(['assignee_member_id' => 'Pick who it’s for.']);
        }

        if ($assign === Chore::ROTA && ($rota->count() < 2 || $rota->contains(fn ($id) => ! $members->has($id)))) {
            throw ValidationException::withMessages(['rota' => 'A rota needs at least two people from the crew.']);
        }

        $fixed = $data['mode'] === Chore::FIXED;
        $due = $data['due_on'] ?? null;

        $chore->fill([
            'title' => trim($data['title']),
            'area' => filled($data['area'] ?? null) ? trim($data['area']) : null,
            'notes' => $data['notes'] ?? null,
            'mode' => $data['mode'],
            'every' => $data['every'],
            'unit' => $data['unit'],
            'rrule' => $fixed ? ChoreSchedule::rrule($data['every'], $data['unit']) : null,
            // A fixed chore's pattern is anchored on its next due date.
            'anchor_on' => $fixed ? $due : null,
            'due_on' => $due,
            'lead_days' => $fixed ? ($data['lead_days'] ?? null) : null,
            'assign' => $assign,
            'assignee_member_id' => $assign === Chore::MEMBER ? (int) $data['assignee_member_id'] : null,
        ]);

        $previousRota = $chore->exists ? $chore->rotaMembers()->pluck('crew_members.id') : collect();
        $newRota = $assign === Chore::ROTA ? $rota->values() : collect();

        if ($previousRota->all() !== $newRota->all()) {
            // A different line-up starts from the top.
            $chore->rota_position = 0;
        }

        $chore->version = ($chore->version ?? 0) + 1;
        $chore->save();

        if ($previousRota->all() !== $newRota->all()) {
            $chore->rotaMembers()->sync($newRota->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());
        }
    }
}
