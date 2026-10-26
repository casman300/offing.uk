<?php

namespace App\Http\Controllers\Shipshape;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Shipshape\Concerns\FindsChores;
use App\Support\Shipshape\Starters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Want a head start?" (spec §25) — shown when a list is made and from
 * "Browse suggestions" later. Everything not already on the list is
 * pre-ticked; anything already there is shown as such and never re-added.
 */
class StarterController extends Controller
{
    use FindsChores;

    public function show(Request $request, int $list): Response
    {
        $model = $this->editableList($request->user(), $list);
        $existing = $model->chores()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();

        return Inertia::render('shipshape/starters', [
            'list' => ['id' => $model->id, 'name' => $model->name, 'owner' => $model->ownerLabel(), 'is_crew' => $model->isCrewList()],
            'fresh' => $model->chores()->withTrashed()->doesntExist(),
            'groups' => collect(Starters::groups())->map(fn ($group) => [
                ...$group,
                'items' => collect($group['items'])->map(fn ($item) => $item + [
                    'present' => in_array(mb_strtolower($item['title']), $existing, true),
                ])->values()->all(),
            ])->values(),
        ]);
    }

    public function store(Request $request, int $list): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableList($user, $list);

        $data = $request->validate([
            'keys' => ['array'],
            'keys.*' => ['string', Rule::in(array_keys(Starters::byKey()))],
            'dates' => ['array'],
            'dates.*' => ['nullable', 'date_format:Y-m-d'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
        ]);

        $added = Starters::add($model, $user, $data['keys'] ?? [], $data['dates'] ?? [], $data['weekday'] ?? null, $user->today());

        $message = $added === 0 ? "“{$model->name}” is ready — add your first chore." : "Added {$added} ".($added === 1 ? 'chore' : 'chores')." to “{$model->name}”.";

        return to_route('shipshape.board')->with('status', $message);
    }
}
