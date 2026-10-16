<?php

namespace App\Features;

/**
 * Phase 1a — people + birthdays (#12, #13).
 */
class BirthdayEngine extends StagedFeature
{
    public string $name = 'birthday-engine';

    public string $description = 'People and birthdays (Phase 1a).';

    public Rollout $stage = Rollout::Admins;
}
