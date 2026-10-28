<?php

namespace App\Http\Controllers\Admin;

use App\Features\Features;
use App\Features\Rollout;
use App\Features\StagedFeature;
use App\Features\StageOverrides;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * /admin/features — set each flag's rollout stage, override it per user,
 * or clear a flag's overrides so everyone follows the stage again.
 *
 * Pennant's `features` table holds overrides only (OverridesOnlyDriver), so
 * Features::stored() reads exactly the overrides; a user with no row gets
 * the stage's answer.
 */
class FeatureFlagController extends Controller
{
    public function index(Request $request): Response
    {
        $features = Features::all();
        $search = trim((string) $request->query('q', ''));
        $everyone = User::query()->get();
        $overrides = Features::stored(array_keys($features), $everyone->all());

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('email')
            ->limit(25)
            ->get();

        return Inertia::render('admin/features', [
            'q' => $search,
            'stages' => collect(Rollout::cases())->map(fn (Rollout $stage) => ['value' => $stage->value, 'label' => $stage->label()])->all(),
            'userCount' => $everyone->count(),
            // Not `features`: that's the shared prop the sidebar reads.
            'flags' => collect($features)->map(function (StagedFeature $feature) use ($everyone, $overrides) {
                $stored = $overrides[$feature->name] ?? [];

                return [
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'stage' => $feature->currentStage()->value,
                    'stage_label' => $feature->currentStage()->label(),
                    'percentage' => $feature->currentPercentage(),
                    'stage_overridden' => $feature->stageOverridden(),
                    'declared_stage_label' => $feature->stage->label(),
                    'declared_percentage' => $feature->percentage,
                    'overrides_on' => count(array_filter($stored)),
                    'overrides_off' => count($stored) - count(array_filter($stored)),
                    'enabled' => $everyone->filter(fn (User $user) => $stored[$user->id] ?? $feature->resolve($user))->count(),
                ];
            })->values(),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'is_beta_tester' => $user->is_beta_tester,
                'flags' => collect($features)->map(fn (StagedFeature $feature) => [
                    // null = no override; the stage decides.
                    'stored' => $overrides[$feature->name][$user->id] ?? null,
                    'stage_default' => $feature->resolve($user),
                ])->all(),
            ])->values(),
        ]);
    }

    public function stage(Request $request, string $feature): RedirectResponse
    {
        $flag = $this->findOrFail($feature);
        $validated = $request->validate([
            'stage' => ['required', Rule::in([...array_column(Rollout::cases(), 'value'), 'default'])],
            'percentage' => ['required_if:stage,percentage', 'nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $from = ['stage' => $flag->currentStage()->value, 'percentage' => $flag->currentPercentage()];

        if ($validated['stage'] === 'default') {
            app(StageOverrides::class)->forget($flag->name);
        } else {
            $stage = Rollout::from($validated['stage']);
            app(StageOverrides::class)->set($flag->name, $stage, $stage === Rollout::Percentage ? (int) $validated['percentage'] : 0);
        }

        $to = ['stage' => $flag->currentStage()->value, 'percentage' => $flag->currentPercentage()];

        AuditLog::record($request->user(), 'feature.stage_changed', null, ['feature' => $flag->name, 'from' => $from, 'to' => $to, 'via' => 'admin'], $request->ip());

        $label = $flag->currentStage()->label().($flag->currentStage() === Rollout::Percentage ? " ({$flag->currentPercentage()}%)" : '');

        return back()->with('status', "{$flag->name} is now {$label}.");
    }

    public function update(Request $request, string $feature, User $user): RedirectResponse
    {
        $flag = $this->findOrFail($feature);
        /** @var 'on'|'off'|'default' $value */
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

        return back()->with('status', "{$flag->name} overrides cleared — everyone follows its stage ({$flag->currentStage()->label()}).");
    }

    private function findOrFail(string $name): StagedFeature
    {
        $feature = Features::find($name);
        abort_if($feature === null, 404);

        return $feature;
    }
}
