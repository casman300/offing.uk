<?php

namespace App\Features;

/**
 * Phase 1b, first slice — calendars and one-off events with an agenda view.
 */
class Calendar extends StagedFeature
{
    public string $name = 'calendar';

    public string $description = 'Your own calendar: one-off and all-day events, agenda view (Phase 1b).';

    public Rollout $stage = Rollout::Admins;
}
