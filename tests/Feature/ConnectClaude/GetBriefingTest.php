<?php

use App\Features\BirthdayEngine;
use App\Features\Crews;
use App\Features\Shipshape;
use App\Mcp\OffingServer;
use App\Mcp\Tools\GetBriefing;
use App\Models\AiGrant;
use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Tuesday 20 October 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/London'));
});

function briefed(array $features): User
{
    $user = User::factory()->create(['name' => 'Charlie', 'timezone' => 'Europe/London']);
    Feature::for($user)->activate($features);

    return $user;
}

it('says who, when and how much access, even with nothing else on', function () {
    OffingServer::actingAs(briefed([]))->tool(GetBriefing::class)
        ->assertOk()
        ->assertSee(['Offing briefing for Charlie.', 'Today is Tuesday 20 October 2026, timezone Europe/London.', 'Read only', 'Private events: hidden.'])
        ->assertDontSee(['Birthdays', 'People', 'Crews', 'Shipshape']);
});

it('reads the access level from the grant', function () {
    $grant = new AiGrant(['level' => 'edit', 'include_private' => true]);
    app()->instance(AiGrant::class, $grant);

    OffingServer::actingAs(briefed([]))->tool(GetBriefing::class)
        ->assertSee(['Read, add and change', 'Private events: included.']);
});

it('lists birthdays coming up and people with their ids', function () {
    $user = briefed([BirthdayEngine::class]);
    $sue = $user->people()->create(['name' => 'Sue', 'birth_day' => 25, 'birth_month' => 10, 'birth_year' => 1983]);
    $tom = $user->people()->create(['name' => 'Tom', 'birth_day' => 21, 'birth_month' => 10]);
    $user->people()->create(['name' => 'Ada', 'birth_day' => 1, 'birth_month' => 3]);
    User::factory()->create()->people()->create(['name' => 'Not mine', 'birth_day' => 22, 'birth_month' => 10]);

    OffingServer::actingAs($user)->tool(GetBriefing::class)
        ->assertSee([
            "- Tom (person {$tom->id}): Wed 21 Oct, tomorrow",
            "- Sue (person {$sue->id}): Sun 25 Oct, in 5 days, turns 43",
            'People (3, name and person id): Ada (',
        ])
        ->assertDontSee(['Not mine', 'Ada (person']);
});

it('lists your crews and your role', function () {
    $user = briefed([Crews::class]);
    $crew = Crew::create(['name' => 'Home']);
    $crew->members()->create(['user_id' => $user->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['person_id' => $crew->people()->create(['name' => 'Alfie'])->id, 'role' => CrewMember::PASSENGER, 'joined_at' => now()]);

    OffingServer::actingAs($user)->tool(GetBriefing::class)
        ->assertSee("- Home (crew {$crew->id}): you are captain, 2 aboard");
});

it('lists chores overdue, today and coming up, but not later ones', function () {
    $user = briefed([Shipshape::class]);
    $list = ChoreList::make(['name' => 'Mine']);
    $list->owner()->associate($user)->save();
    $chore = fn (string $title, string $due) => $list->chores()->create(['title' => $title, 'mode' => Chore::FLOATING, 'every' => 1, 'unit' => 'week', 'due_on' => $due]);
    $bins = $chore('Bins', '2026-10-18');
    $chore('Dust', '2026-10-19');
    $chore('Hoover', '2026-10-20');
    $chore('Windows', '2026-10-22');
    $chore('Gutters', '2026-12-01');

    OffingServer::actingAs($user)->tool(GetBriefing::class)
        ->assertSee([
            "Overdue:\n- Bins (chore {$bins->id}, Mine): due Sun 18 Oct, 2 days late",
            'Dust (chore',
            'due Mon 19 Oct, 1 day late',
            "Due today:\n- Hoover",
            "Coming up:\n- Windows",
        ])
        ->assertDontSee('Gutters');
});
