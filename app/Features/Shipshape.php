<?php

namespace App\Features;

/**
 * Phase 1a+ — Shipshape, household chores (spec §25). Shared lists need
 * `crews` as well; private lists work without it.
 */
class Shipshape extends StagedFeature
{
    public string $name = 'shipshape';

    public string $description = 'Shipshape: recurring household chores, private or shared with a crew (Phase 1a+).';

    public Rollout $stage = Rollout::Admins;
}
