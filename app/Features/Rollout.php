<?php

namespace App\Features;

/**
 * Who a feature is on for by default. Each stage includes everyone in the
 * one before it: Off ⊂ Admins ⊂ Beta ⊂ Percentage ⊂ Everyone. The class
 * declares a starting stage; /admin/features can change it (audited), and
 * per-user overrides from there or `offing:feature` win over either.
 */
enum Rollout: string
{
    /** Nobody — dark. */
    case Off = 'off';

    /** Only ADMIN_EMAILS users: the default for anything new. */
    case Admins = 'admins';

    /** Admins plus users ticked as beta testers on /admin/users. */
    case Beta = 'beta';

    /** Admins, beta testers and a stable slice of everyone else. */
    case Percentage = 'percentage';

    /** Everyone. */
    case Everyone = 'everyone';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Admins => 'Admins only',
            self::Beta => 'Beta testers',
            self::Percentage => 'Percentage',
            self::Everyone => 'Everyone',
        };
    }
}
