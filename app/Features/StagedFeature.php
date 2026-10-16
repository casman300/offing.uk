<?php

namespace App\Features;

use App\Models\User;

/**
 * Base for every Pennant feature in Offing. Subclasses declare a name,
 * a description and a rollout stage; resolve() turns that into a per-user
 * answer the first time Pennant checks it.
 *
 * Pennant stores that first answer. Changing $stage later only affects
 * users who haven't been checked yet — "Reset to default" on
 * /admin/features (Pennant's purge) re-applies the stage to everyone.
 */
abstract class StagedFeature
{
    /** Stable stored name — never derive it from the class name. */
    public string $name;

    public string $description;

    public Rollout $stage;

    /** Only used with Rollout::Percentage. */
    public int $percentage = 0;

    public function resolve(User $user): bool
    {
        return match ($this->stage) {
            Rollout::Off => false,
            Rollout::Admins => $user->isAdmin(),
            Rollout::Everyone => true,
            // Hash rather than id % 100 so each feature gets a different slice.
            Rollout::Percentage => $user->isAdmin() || crc32($this->name.'|'.$user->id) % 100 < $this->percentage,
        };
    }
}
