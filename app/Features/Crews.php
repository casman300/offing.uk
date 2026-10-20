<?php

namespace App\Features;

/**
 * Phase 1a+ — crews, the shared spaces (spec §7). Shipshape's shared lists
 * build on this.
 */
class Crews extends StagedFeature
{
    public string $name = 'crews';

    public string $description = 'Crews: shared spaces with captain, crew and passenger roles (Phase 1a+).';

    public Rollout $stage = Rollout::Admins;
}
