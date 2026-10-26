<?php

namespace App\Features;

/**
 * Barometer (#34, #35): a private CBT-style anxiety log. Health data —
 * owner-only, never shared or in feeds; AI assistants only via its own
 * consent switch (spec §12). Spec §26.
 */
class Barometer extends StagedFeature
{
    public string $name = 'barometer';

    public string $description = 'Private anxiety log: readings, list and PDF/CSV export (opt-in per person).';

    public Rollout $stage = Rollout::Admins;
}
