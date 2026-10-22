<?php

use App\Features\BirthdayEngine;
use App\Features\Crews;
use App\Features\Shipshape;
use App\Mcp\OffingServer;
use App\Mcp\Tools\Find;
use App\Mcp\Tools\LogChore;
use App\Mcp\Tools\SavePerson;
use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\Chore;
use App\Models\ChoreEntry;
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

function toolUser(array $features = [BirthdayEngine::class, Crews::class, Shipshape::class]): User
{
    $user = User::factory()->create(['name' => 'Charlie', 'timezone' => 'Europe/London']);
    Feature::for($user)->activate($features);

    return $user;
}

function grantLevel(string $level): void
{
    app()->instance(AiGrant::class, new AiGrant(['level' => $level, 'include_private' => false]));
}

function myList(User $user, string $name = 'Mine'): ChoreList
{
    $list = ChoreList::make(['name' => $name]);
    $list->owner()->associate($user)->save();

    return $list;
}

function weekly(ChoreList $list, string $title, ?string $due): Chore
{
    return $list->chores()->create(['title' => $title, 'mode' => Chore::FLOATING, 'every' => 1, 'unit' => 'week', 'due_on' => $due]);
}

function crewWith(User $user, string $name = 'Home'): array
{
    $crew = Crew::create(['name' => $name]);
    $me = $crew->members()->create(['user_id' => $user->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $alfie = $crew->members()->create(['person_id' => $crew->people()->create(['name' => 'Alfie'])->id, 'role' => CrewMember::PASSENGER, 'joined_at' => now()]);
    $list = ChoreList::make(['name' => "{$name} jobs"]);
    $list->owner()->associate($crew)->save();

    return [$crew, $me, $alfie, $list];
}

describe('find', function () {
    it('needs something to look for', function () {
        OffingServer::actingAs(toolUser())->tool(Find::class, [])
            ->assertHasErrors(['Give at least one of text']);
    });

    it('searches people, chores and crews by text, only your own', function () {
        $user = toolUser();
        $sue = $user->people()->create(['name' => 'Sue Brown', 'birth_day' => 25, 'birth_month' => 10]);
        $ann = $user->people()->create(['name' => 'Ann', 'notes' => 'Sue’s sister']);
        User::factory()->create()->people()->create(['name' => 'Sue Other']);
        $bins = weekly(myList($user), 'Sue’s bins', '2026-10-22');
        [$crew] = crewWith($user, 'Sue’s flat');

        OffingServer::actingAs($user)->tool(Find::class, ['text' => 'sue'])
            ->assertOk()
            ->assertSee([
                "- Sue Brown (person {$sue->id}), birthday 25 Oct",
                "- Ann (person {$ann->id}) — matched in notes",
                "- Sue’s bins (chore {$bins->id}, Mine): due Thu 22 Oct",
                "- Sue’s flat (crew {$crew->id})",
            ])
            ->assertDontSee('Sue Other');
    });

    it('finds birthdays and chores in a date range, across new year', function () {
        $user = toolUser();
        $user->people()->create(['name' => 'Nye', 'birth_day' => 31, 'birth_month' => 12, 'birth_year' => 1990]);
        $user->people()->create(['name' => 'Jan', 'birth_day' => 2, 'birth_month' => 1]);
        $user->people()->create(['name' => 'Later', 'birth_day' => 1, 'birth_month' => 3]);
        $list = myList($user);
        weekly($list, 'Gutters', '2027-01-01');
        weekly($list, 'Bins', '2026-10-21');

        OffingServer::actingAs($user)->tool(Find::class, ['from' => '2026-12-20', 'to' => '2027-01-10'])
            ->assertSee(['Birthdays Sun 20 Dec – Sun 10 Jan:', 'Thu 31 Dec 2026: Nye', 'turns 36', 'Sat 2 Jan 2027: Jan', 'Gutters'])
            ->assertDontSee(['Later', 'Bins']);
    });

    it('refuses very long ranges and backwards ones', function () {
        $user = toolUser();

        OffingServer::actingAs($user)->tool(Find::class, ['from' => '2026-01-01', 'to' => '2027-06-01'])->assertHasErrors(['too long']);
        OffingServer::actingAs($user)->tool(Find::class, ['from' => '2026-02-01', 'to' => '2026-01-01'])->assertHasErrors(['on or after']);
    });

    it('shows one person in full, but never someone else’s', function () {
        $user = toolUser();
        $sue = $user->people()->create(['name' => 'Sue', 'birth_day' => 25, 'birth_month' => 10, 'birth_year' => 1983, 'notes' => 'Likes gin']);
        $theirs = User::factory()->create()->people()->create(['name' => 'Secret']);

        OffingServer::actingAs($user)->tool(Find::class, ['person_id' => $sue->id])
            ->assertSee(["Sue (person {$sue->id})", 'Birthday: 25 October 1983. Next: Sun 25 Oct, in 5 days, turns 43.', 'Age: 42.', 'Notes: Likes gin']);

        OffingServer::actingAs($user)->tool(Find::class, ['person_id' => $theirs->id])
            ->assertSee("No person {$theirs->id}")
            ->assertDontSee('Secret');
    });

    it('shows a crew’s members with member ids, and its chores', function () {
        $user = toolUser();
        [$crew, $me, $alfie, $list] = crewWith($user);
        weekly($list, 'Hoover', '2026-10-20');
        weekly(myList($user), 'My own', '2026-10-20');

        OffingServer::actingAs($user)->tool(Find::class, ['crew_id' => $crew->id])
            ->assertSee(["Home (crew {$crew->id}) aboard:", "- Charlie (member {$me->id}): Captain — you", "- Alfie (member {$alfie->id}): Passenger", "Chores on Home's lists:", 'Hoover'])
            ->assertDontSee('My own');

        $other = Crew::create(['name' => 'Not mine']);
        OffingServer::actingAs($user)->tool(Find::class, ['crew_id' => $other->id])->assertHasErrors(["No crew {$other->id}"]);
    });

    it('shows a chore with its log and entry ids', function () {
        $user = toolUser();
        [, , $alfie, $list] = crewWith($user);
        $chore = weekly($list, 'Hoover', '2026-10-20');
        $entry = $chore->entries()->create(['kind' => 'done', 'done_on' => '2026-10-13', 'done_by_name' => 'Alfie', 'chore_title' => 'Hoover', 'via' => 'ai', 'before' => []]);

        OffingServer::actingAs($user)->tool(Find::class, ['chore_id' => $chore->id])
            ->assertSee(["Hoover (chore {$chore->id}, Home jobs): due Tue 20 Oct", "every week after it's last done", "Alfie (member {$alfie->id})", "- done Tue 13 Oct 2026 by Alfie (entry {$entry->id}, via an AI assistant)"]);
    });

    it('leaves out whatever is switched off for the user', function () {
        $user = toolUser([]);
        $user->people()->create(['name' => 'Sue']);
        weekly(myList($user), 'Sue’s bins', null);

        OffingServer::actingAs($user)->tool(Find::class, ['text' => 'Sue'])
            ->assertSee('Nothing found.')
            ->assertDontSee(['Sue (', 'bins']);
    });
});

describe('save_person', function () {
    it('isn’t offered to read-only connections or without birthdays on', function () {
        grantLevel('read');
        OffingServer::actingAs(toolUser())->tool(SavePerson::class, ['name' => 'Sue'])->assertHasErrors();

        grantLevel('write-new');
        OffingServer::actingAs(toolUser([]))->tool(SavePerson::class, ['name' => 'Sue'])->assertHasErrors();
    });

    it('adds a person with a birthday and audits it', function () {
        grantLevel('write-new');
        $user = toolUser();

        OffingServer::actingAs($user)->tool(SavePerson::class, ['name' => ' Sue ', 'birth_day' => 25, 'birth_month' => 10])
            ->assertOk()
            ->assertSee(['Added Sue (person ', 'Next birthday Sun 25 Oct, in 5 days.']);

        $sue = $user->people()->sole();
        expect($sue->only('name', 'birth_day', 'birth_month', 'birth_year'))->toBe(['name' => 'Sue', 'birth_day' => 25, 'birth_month' => 10, 'birth_year' => null])
            ->and(AuditLog::where('action', 'person.created')->sole()->meta)->toMatchArray(['name' => 'Sue', 'via' => 'ai']);
    });

    it('validates like the app does', function () {
        grantLevel('write-new');
        $user = toolUser();

        OffingServer::actingAs($user)->tool(SavePerson::class, ['name' => 'Feb', 'birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2023])
            ->assertHasErrors(['29 February only exists in leap years']);
        OffingServer::actingAs($user)->tool(SavePerson::class, ['name' => 'Half', 'birth_day' => 3])
            ->assertHasErrors(['Pick a month to go with the day.']);
        OffingServer::actingAs($user)->tool(SavePerson::class, ['birth_day' => 3, 'birth_month' => 4])
            ->assertHasErrors(['name']);

        expect($user->people()->count())->toBe(0);
    });

    it('flags a probable duplicate', function () {
        grantLevel('write-new');
        $user = toolUser();
        $sue = $user->people()->create(['name' => 'Sue']);

        OffingServer::actingAs($user)->tool(SavePerson::class, ['name' => 'Sue'])
            ->assertSee("there was already a Sue (person {$sue->id})");
    });

    it('needs edit access to change someone', function () {
        grantLevel('write-new');
        $user = toolUser();
        $sue = $user->people()->create(['name' => 'Sue']);

        OffingServer::actingAs($user)->tool(SavePerson::class, ['person_id' => $sue->id, 'name' => 'Susan'])
            ->assertHasErrors(['“Read and add new”', '“Read, add and change”', 'Settings → AI assistants']);

        expect($sue->fresh()->name)->toBe('Sue');
    });

    it('changes only the fields given, and clears ones passed as null', function () {
        grantLevel('edit');
        $user = toolUser();
        $sue = $user->people()->create(['name' => 'Sue', 'birth_day' => 25, 'birth_month' => 10, 'notes' => 'Likes gin']);

        OffingServer::actingAs($user)->tool(SavePerson::class, ['person_id' => $sue->id, 'birth_year' => 1983, 'notes' => null])
            ->assertSee(["Updated Sue (person {$sue->id}): birth year, notes."]);

        expect($sue->fresh()->only('name', 'birth_day', 'birth_month', 'birth_year', 'notes'))
            ->toBe(['name' => 'Sue', 'birth_day' => 25, 'birth_month' => 10, 'birth_year' => 1983, 'notes' => null])
            ->and(AuditLog::where('action', 'person.updated')->sole()->meta)->toMatchArray(['fields' => ['birth_year', 'notes'], 'before' => ['birth_year' => null]]);

        OffingServer::actingAs($user)->tool(SavePerson::class, ['person_id' => $sue->id, 'name' => 'Sue'])
            ->assertSee('Nothing to change');
    });

    it('can’t touch someone else’s person', function () {
        grantLevel('edit');
        $theirs = User::factory()->create()->people()->create(['name' => 'Secret']);

        OffingServer::actingAs(toolUser())->tool(SavePerson::class, ['person_id' => $theirs->id, 'name' => 'Mine now'])
            ->assertHasErrors(["No person {$theirs->id}"]);

        expect($theirs->fresh()->name)->toBe('Secret');
    });
});

describe('log_chore', function () {
    it('isn’t offered to read-only connections or without Shipshape on', function () {
        grantLevel('read');
        $user = toolUser();
        $chore = weekly(myList($user), 'Bins', '2026-10-20');
        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id])->assertHasErrors();

        grantLevel('write-new');
        OffingServer::actingAs(toolUser([]))->tool(LogChore::class, ['chore_id' => $chore->id])->assertHasErrors();

        expect($chore->entries()->count())->toBe(0);
    });

    it('logs a chore done, moves it on and marks the entry as from an AI assistant', function () {
        grantLevel('write-new');
        $user = toolUser();
        $chore = weekly(myList($user), 'Bins', '2026-10-18');

        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id])
            ->assertOk()
            ->assertSee(['Logged “Bins” done on Tue 20 Oct (entry ', "Now: Bins (chore {$chore->id}, Mine): due Tue 27 Oct."]);

        $entry = $chore->entries()->sole();
        expect($entry->via)->toBe('ai')
            ->and($entry->logged_by_user_id)->toBe($user->id)
            ->and($chore->fresh()->due_on->toDateString())->toBe('2026-10-27');
    });

    it('doesn’t log the same thing twice when a call is retried', function () {
        grantLevel('write-new');
        $user = toolUser();
        $chore = weekly(myList($user), 'Bins', '2026-10-18');

        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id]);
        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id])->assertSee('Already logged a moment ago');

        expect($chore->entries()->count())->toBe(1);

        $this->travel(5)->minutes();
        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id])->assertSee('Logged “Bins” done');
        expect($chore->entries()->count())->toBe(2);
    });

    it('credits a crewmate, but only one aboard that crew', function () {
        grantLevel('write-new');
        $user = toolUser();
        [, , $alfie, $list] = crewWith($user);
        [, , $stranger] = crewWith(User::factory()->create(), 'Elsewhere');
        $chore = weekly($list, 'Hoover', '2026-10-20');

        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id, 'done_by_member_id' => $stranger->id])
            ->assertHasErrors(["Member {$stranger->id} isn't aboard"]);

        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id, 'done_by_member_id' => $alfie->id, 'done_on' => '2026-10-19'])
            ->assertSee('Logged “Hoover” done by Alfie on Mon 19 Oct');

        expect($chore->entries()->sole()->done_by_member_id)->toBe($alfie->id);
    });

    it('skips with a reason, and refuses the future and other people’s chores', function () {
        grantLevel('write-new');
        $user = toolUser();
        $chore = weekly(myList($user), 'Bins', '2026-10-20');
        $theirs = weekly(myList(User::factory()->create()), 'Theirs', '2026-10-20');

        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id, 'done_on' => '2026-10-21'])->assertHasErrors(['in the future']);
        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $theirs->id])->assertHasErrors(["No chore {$theirs->id}"]);
        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id, 'kind' => 'skip', 'reason' => 'away'])->assertSee('Logged “Bins” skipped');

        expect($chore->entries()->sole()->reason)->toBe('away')
            ->and($theirs->entries()->count())->toBe(0);
    });

    it('needs edit access to undo, then puts the chore back', function () {
        $user = toolUser();
        $chore = weekly(myList($user), 'Bins', '2026-10-18');

        grantLevel('write-new');
        OffingServer::actingAs($user)->tool(LogChore::class, ['chore_id' => $chore->id]);
        $entry = $chore->entries()->sole();
        OffingServer::actingAs($user)->tool(LogChore::class, ['undo_entry_id' => $entry->id])->assertHasErrors(['“Read, add and change”']);

        grantLevel('edit');
        OffingServer::actingAs($user)->tool(LogChore::class, ['undo_entry_id' => $entry->id])
            ->assertSee(["Undone: “Bins” on Tue 20 Oct (entry {$entry->id}).", 'due Sun 18 Oct, 2 days late']);

        expect($entry->fresh()->voided_at)->not->toBeNull()
            ->and($chore->fresh()->due_on->toDateString())->toBe('2026-10-18');

        OffingServer::actingAs($user)->tool(LogChore::class, ['undo_entry_id' => $entry->id])->assertSee('already undone');
    });

    it('can’t undo an entry on someone else’s list', function () {
        grantLevel('edit');
        $chore = weekly(myList(User::factory()->create()), 'Theirs', '2026-10-20');
        $entry = $chore->entries()->create(['kind' => ChoreEntry::DONE, 'done_on' => '2026-10-19', 'done_by_name' => 'Them', 'chore_title' => 'Theirs', 'before' => []]);

        OffingServer::actingAs(toolUser())->tool(LogChore::class, ['undo_entry_id' => $entry->id])->assertHasErrors(["No log entry {$entry->id}"]);
        expect($entry->fresh()->voided_at)->toBeNull();
    });
});
