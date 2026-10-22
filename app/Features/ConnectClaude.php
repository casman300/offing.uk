<?php

namespace App\Features;

/**
 * Phase 1a+ — Connect Claude (spec §12): an OAuth MCP server so each user
 * can link their own Claude. Starts with the briefing; more tools follow.
 */
class ConnectClaude extends StagedFeature
{
    public string $name = 'connect-claude';

    public string $description = 'AI assistants: link your own Claude, ChatGPT or other MCP client to Offing over OAuth (Phase 1a+).';

    public Rollout $stage = Rollout::Admins;
}
