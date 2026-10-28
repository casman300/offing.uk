<?php

namespace App\Features;

use App\Models\User;

/**
 * Base for every Pennant feature in Offing. Subclasses declare a name, a
 * description and a starting rollout stage; an admin can move the stage on
 * /admin/features (StageOverrides). resolve() turns the current stage into
 * a per-user answer.
 *
 * Nothing resolved here is stored (OverridesOnlyDriver), so a stage change
 * reaches every user on their next request. Only explicit per-user
 * overrides live in Pennant's `features` table.
 */
abstract class StagedFeature
{
    /** Stable stored name — never derive it from the class name. */
    public string $name;

    public string $description;

    /** The starting stage, used until one is chosen on /admin/features. */
    public Rollout $stage;

    /** Only used with Rollout::Percentage. */
    public int $percentage = 0;

    /** The stage in force: the admin's choice, else the class's. */
    public function currentStage(): Rollout
    {
        return app(StageOverrides::class)->for($this->name)['stage'] ?? $this->stage;
    }

    public function currentPercentage(): int
    {
        return app(StageOverrides::class)->for($this->name)['percentage'] ?? $this->percentage;
    }

    /** Has an admin moved this flag off its declared stage? */
    public function stageOverridden(): bool
    {
        return app(StageOverrides::class)->for($this->name) !== null;
    }

    public function resolve(User $user): bool
    {
        return match ($this->currentStage()) {
            Rollout::Off => false,
            Rollout::Admins => $user->isAdmin(),
            Rollout::Beta => $user->isAdmin() || $user->is_beta_tester,
            Rollout::Everyone => true,
            // Hash rather than id % 100 so each feature gets a different slice.
            Rollout::Percentage => $user->isAdmin() || $user->is_beta_tester || crc32($this->name.'|'.$user->id) % 100 < $this->currentPercentage(),
        };
    }
}
