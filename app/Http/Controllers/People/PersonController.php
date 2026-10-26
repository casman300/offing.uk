<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\PersonRequest;
use App\Models\AuditLog;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People + birthdays (#12, #13). Every lookup goes through the signed-in
 * user's own `people()` relation, so someone else's person is a 404 — never
 * a 403 that confirms the id exists.
 */
class PersonController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = $user->today();

        return Inertia::render('people/index', [
            'today' => $today->toDateString(),
            'people' => $user->people()->get()
                ->map(fn (Person $person) => $person->toPageArray($today))
                ->values(),
            'recentlyDeleted' => $user->people()->onlyTrashed()
                ->latest('deleted_at')
                ->limit(20)
                ->get()
                ->map(fn (Person $person) => $person->toPageArray($today))
                ->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('people/create', [
            'today' => $request->user()->today()->toDateString(),
        ]);
    }

    public function store(PersonRequest $request): RedirectResponse
    {
        $person = $request->user()->people()->create($request->personAttributes());

        return to_route('people.show', $person)->with('status', 'person-created');
    }

    public function show(Request $request, int $person): Response
    {
        $user = $request->user();
        $today = $user->today();

        return Inertia::render('people/show', [
            'today' => $today->toDateString(),
            'person' => $user->people()->findOrFail($person)->toPageArray($today),
        ]);
    }

    public function update(PersonRequest $request, int $person): RedirectResponse
    {
        $model = $request->user()->people()->findOrFail($person);
        $model->update($request->personAttributes());

        return to_route('people.show', $model)->with('status', 'person-updated');
    }

    public function destroy(Request $request, int $person): RedirectResponse
    {
        $model = $request->user()->people()->findOrFail($person);
        $model->delete();

        AuditLog::record($request->user(), 'person.deleted', $model, ['name' => $model->name], $request->ip());

        return to_route('people.index')->with('deleted', ['id' => $model->id, 'name' => $model->name]);
    }

    public function restore(Request $request, int $person): RedirectResponse
    {
        $model = $request->user()->people()->onlyTrashed()->findOrFail($person);
        $model->restore();

        AuditLog::record($request->user(), 'person.restored', $model, ['name' => $model->name], $request->ip());

        return to_route('people.show', $model)->with('status', 'person-restored');
    }
}
