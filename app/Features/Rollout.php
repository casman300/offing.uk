<?php

namespace App\Features;

/**
 * Who a feature is on for by default — declared in code per feature, so the
 * default is reviewed in a PR rather than remembered. Per-user overrides
 * from /admin/features (or `offing:feature`) win over the stage.
 */
enum Rollout: string
{
    /** Nobody — dark. */
    case Off = 'off';

    /** Only ADMIN_EMAILS users: the default for anything new. */
    case Admins = 'admins';

    /** A stable slice of users (see StagedFeature::$percentage). */
    case Percentage = 'percentage';

    /** Everyone. */
    case Everyone = 'everyone';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Admins => 'Admins only',
            self::Percentage => 'Percentage',
            self::Everyone => 'Everyone',
        };
    }
}
