<?php

namespace App\Mcp;

use App\Mcp\Tools\GetBriefing;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Connect Claude (spec §12): one OAuth MCP server for Claude, ChatGPT and any
 * other MCP client, hosted or self-hosted. Few, broad tools; the briefing
 * comes first.
 */
#[Name('Offing')]
#[Version('0.1.0')]
class OffingServer extends Server
{
    protected string $instructions = <<<'MARKDOWN'
        Offing is the user's shared calendar, address book and household-chores app ("What's in the offing").
        Call `get_briefing` once at the start of a conversation: it returns who the user is, their timezone and
        today's date, their access level, their crews, people, upcoming birthdays and chores due — usually
        everything needed in one call.

        Vocabulary:
        - People: the user's own private address book (friends, family). "(person 12)" is a person's id.
        - Crew: a shared space such as a household or club. Roles: Captain (runs it), Crew (members with
          accounts), Passenger (members without accounts, like children).
        - Shipshape: recurring household chores. Every chore repeats; there are no one-off tasks or to-dos.
        - Events have two independent flags: private (details hidden) and busy/free. Never guess at private details.

        Use UK date style ("Sat 25 Oct"). Only state what the tools returned; if something isn't there, say so
        rather than inventing it.
    MARKDOWN;

    protected array $tools = [
        GetBriefing::class,
    ];
}
