<?php

namespace App\Features;

/**
 * Birthday reminders by email (#17, #18). Only meaningful alongside
 * BirthdayEngine — the scanner and settings require both.
 */
class Reminders extends StagedFeature
{
    public string $name = 'reminders';

    public string $description = 'Email reminders before and on birthdays (Phase 1a).';

    public Rollout $stage = Rollout::Admins;
}
