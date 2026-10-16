<?php

namespace App\Features;

use App\Models\User;

/**
 * Phase 1a — people + birthdays (#12, #13). Dark by default; switch on per
 * user with `php artisan offing:feature birthday-engine you@example.com --on`.
 */
class BirthdayEngine
{
    /**
     * Stable stored name, independent of the class name.
     */
    public string $name = 'birthday-engine';

    public function resolve(User $user): bool
    {
        return false;
    }
}
