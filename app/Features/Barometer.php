<?php

namespace App\Features;

/**
 * Barometer (#34, #35): a private CBT-style anxiety log. Health data —
 * owner-only, never shared, never in feeds or MCP. Spec §25.
 */
class Barometer extends StagedFeature
{
    public string $name = 'barometer';

    public string $description = 'Private anxiety log: readings, list and PDF/CSV export (opt-in per person).';

    public Rollout $stage = Rollout::Admins;
}
