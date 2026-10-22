<?php

namespace App\Mcp;

use App\Features\Barometer;
use App\Mcp\Tools\Find;
use App\Mcp\Tools\GetBarometerStats;
use App\Mcp\Tools\GetBriefing;
use App\Mcp\Tools\LogChore;
use App\Mcp\Tools\LogReading;
use App\Mcp\Tools\SavePerson;
use App\Models\AiGrant;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Pennant\Feature;

/**
 * Connect Claude (spec §12): one OAuth MCP server for Claude, ChatGPT and any
 * other MCP client, hosted or self-hosted. Few, broad tools; the briefing
 * comes first.
 */
#[Name('Offing')]
#[Version('0.2.0')]
class OffingServer extends Server
{
    protected string $instructions = <<<'MARKDOWN'
        Offing is the user's shared calendar, address book and household-chores app ("What's in the offing").
        Call `get_briefing` once at the start of a conversation: it returns who the user is, their timezone and
        today's date, their access level, their crews, people, upcoming birthdays and chores due — usually
        everything needed in one call. Use `find` for anything more: a date range, a search, one person, crew or chore.

        Writing: `save_person` adds to or updates the user's own address book; `log_chore` marks a chore done or
        skipped, or undoes an entry. Which of these you have depends on the access the user gave this connection.
        Only write what the user asked for; if a name or chore is ambiguous, ask rather than pick. Every result
        carries ids, so a mistake can be put right straight away.

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
        Find::class,
        SavePerson::class,
        LogChore::class,
        GetBarometerStats::class,
        LogReading::class,
    ];

    /**
     * Barometer's guidance (spec §12) goes out only to connections the user
     * gave Barometer access; everyone else's assistant never hears of it.
     */
    private const BAROMETER_INSTRUCTIONS = <<<'MARKDOWN'

        Barometer (this connection has access): the user's private anxiety log — health data. `log_reading` adds a
        reading the user tells you about (a rating from 0 calm to 10 most anxious, optional details) or a second
        rating to today's latest reading; `get_barometer_stats` returns summary numbers only. You can't read
        individual readings or anything the user wrote, and there's no edit or delete: the full log, corrections and
        exports are in the Offing app. Never interpret severity or diagnose; describe the numbers plainly. If the user
        mentions risk to themselves, signpost Samaritans (116 123, free, UK, any time) and their GP or therapist.
    MARKDOWN;

    protected function boot(): void
    {
        $grant = app()->bound(AiGrant::class) ? app(AiGrant::class) : null;
        $user = $grant?->user;

        if ($grant?->barometer && $user !== null && Feature::for($user)->active(Barometer::class)) {
            $this->instructions .= self::BAROMETER_INSTRUCTIONS;
        }
    }
}
