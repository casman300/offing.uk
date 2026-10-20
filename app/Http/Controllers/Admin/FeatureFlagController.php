<?php

namespace App\Http\Controllers\Admin;

use App\Features\Features;
use App\Features\StagedFeature;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * /admin/features — see every flag's rollout stage, override it per user,
 * or reset a flag so its stage is re-applied to everyone.
 *
 * Reads go straight to Pennant's `features` table (Features::stored())
 * rather than through Feature::active(), because Pennant stores whatever it
 * resolves: merely looking at a user here would otherwise pin their value.
 */
class FeatureFlagController extends Controller
{
    public function index(Request $request): Response
    {
        $features = Features::all();
        $search = trim((string) $request->query('q', ''));
        $userCount = User::count();

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('email')
            ->limit(25)
            ->get();

        $stored = Features::stored(array_keys($features), $users->all());

        return Inertia::render('admin/features', [
            'q' => $search,
            // Not `features`: that's the shared prop the sidebar reads.
            'flags' => collect($features)->map(function (StagedFeature $feature) use ($userCount) {
                $counts = DB::table('features')
                    ->where('name', $feature->name)
                    ->where('scope', 'like', (new User)->getMorphClass().'|%')
                    ->selectRaw('value, count(*) as total')
                    ->groupBy('value')
                    ->pluck('total', 'value');

                $on = (int) ($counts['true'] ?? 0);
                $off = (int) ($counts['false'] ?? 0);

                return [
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'stage' => $feature->stage->value,
                    'stage_label' => $feature->stage->label(),
                    'percentage' => $feature->percentage,
                    'on' => $on,
                    'off' => $off,
                    'unchecked' => max(0, $userCount - $on - $off),
                ];
            })->values(),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'flags' => collect($features)->map(fn (StagedFeature $feature) => [
                    // null = not checked yet; Pennant will resolve it from the stage.
                    'stored' => $stored[$feature->name][$user->id] ?? null,
                    'stage_default' => $feature->resolve($user),
                ]),
            ])->values(),
        ]);
    }

    public function update(Request $request, string $feature, User $user): RedirectResponse
    {
        $flag = $this->findOrFail($feature);
        $value = $request->validate(['value' => ['required', Rule::in(['on', 'off', 'default'])]])['value'];

        match ($value) {
            'on' => Feature::for($user)->activate($flag::class),
            'off' => Feature::for($user)->deactivate($flag::class),
            'default' => Feature::for($user)->forget($flag::class),
        };

        $action = match ($value) {
            'on' => 'feature.activated',
            'off' => 'feature.deactivated',
            'default' => 'feature.reset',
        };

        AuditLog::record($request->user(), $action, $user, ['feature' => $flag->name, 'via' => 'admin'], $request->ip());

        return back()->with('status', "{$flag->name} set to {$value} for {$user->email}.");
    }

    public function reset(Request $request, string $feature): RedirectResponse
    {
        $flag = $this->findOrFail($feature);

        Feature::purge($flag::class);

        AuditLog::record($request->user(), 'feature.purged', null, ['feature' => $flag->name, 'via' => 'admin'], $request->ip());

        return back()->with('status', "{$flag->name} reset — everyone gets the {$flag->stage->label()} default again.");
    }

    private function findOrFail(string $name): StagedFeature
    {
        $feature = Features::find($name);
        abort_if($feature === null, 404);

        return $feature;
    }
}
