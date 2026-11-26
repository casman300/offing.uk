<?php

namespace App\Features;

/**
 * Phase 1b — the calendar itself (spec §23): calendars and events, private
 * or shared with a crew, with month/week/agenda views. Crew calendars need
 * `crews` as well; private calendars work without it.
 */
class Calendar extends StagedFeature
{
    public string $name = 'calendar';

    public string $description = 'Calendar: events and recurring events on private or crew calendars, with month/week/agenda views (Phase 1b).';

    public Rollout $stage = Rollout::Admins;
}
