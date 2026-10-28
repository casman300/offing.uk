<?php

namespace App\Features;

/**
 * Settings → Sign-in & devices lists every browser you're signed in on,
 * with one button to sign out everywhere else (spec §15). Sign-ins are
 * recorded for everyone either way, so the list is complete when it ships.
 */
class SignInDevices extends StagedFeature
{
    public string $name = 'sign-in-devices';

    public string $description = 'Sign-in & devices: see every browser you’re signed in on, and sign out everywhere else (Phase 0).';

    public Rollout $stage = Rollout::Admins;
}
