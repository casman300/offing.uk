<?php

use App\Features\BirthdayEngine;
use App\Features\Crews;
use App\Features\Shipshape;
use App\Models\AuditLog;
use App\Models\Chore;
use App\Models\ChoreEntry;
use App\Models\ChoreList;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Tuesday 20 October 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/London'));
});

function deckhand(array $attributes = []): User
{
    $user = User::factory()->create(['timezone' => 'Europe/London'] + $attributes);
    Feature::for($user)->activate([Shipshape::class, Crews::class]);

    return $user;
}

/** Home crew: $captain (captain), $mate (crew), Alfie (passenger), and a shared list. */
function household(): array
{
    $captain = deckhand(['name' => 'Charlie']);
    $mate = deckhand(['name' => 'Sam']);
    $crew = Crew::create(['name' => 'Home']);
    $crew->members()->create(['user_id' => $captain->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);
    $crew->members()->create(['user_id' => $mate->id, 'role' => CrewMember::CREW, 'joined_at' => now()->addSecond()]);
    $alfie = $crew->members()->create(['person_id' => $crew->people()->create(['name' => 'Alfie'])->id, 'role' => CrewMember::PASSENGER, 'joined_at' => now()->addSeconds(2)]);

    $list = ChoreList::make(['name' => 'Home chores']);
    $list->owner()->associate($crew)->save();

    return [$captain, $mate, $crew, $list, $alfie];
}

function addChore(ChoreList $list, array $attributes = []): Chore
{
    return $list->chores()->create(array_merge(['title' => 'Hoover', 'mode' => Chore::FLOATING, 'every' => 1, 'unit' => 'week', 'due_on' => '2026-10-20'], $attributes))->fresh();
}

describe('access', function () {
    it('is behind the shipshape flag', function () {
        $this->actingAs(User::factory()->create())->get('/shipshape')->assertNotFound();
        $this->actingAs(deckhand())->get('/shipshape')->assertOk();
    });

    it('shows a crew list to everyone aboard and nobody else', function () {
        [$captain, $mate, , $list] = household();
        addChore($list);

        foreach ([$captain, $mate] as $user) {
            $this->actingAs($user)->get('/shipshape')->assertInertia(fn (Assert $page) => $page->has('chores', 1)->where('chores.0.group', 'today'));
        }

        $stranger = deckhand();
        $this->actingAs($stranger)->get('/shipshape')->assertInertia(fn (Assert $page) => $page->has('chores', 0));
        $this->actingAs($stranger)->get("/shipshape/lists/{$list->id}")->assertNotFound();
        $this->actingAs($stranger)->post('/shipshape/chores/'.Chore::sole()->id.'/log', ['kind' => 'done', 'version' => 0])->assertNotFound();
    });

    it('keeps a private list private', function () {
        $me = deckhand();
        $list = ChoreList::make(['name' => 'Mine']);
        $list->owner()->associate($me)->save();

        $this->actingAs(deckhand())->get("/shipshape/lists/{$list->id}")->assertNotFound();
        $this->actingAs($me)->get("/shipshape/lists/{$list->id}")->assertOk();
    });
});

describe('the edit switch', function () {
    it('is captains-only to change, audited', function () {
        [$captain, $mate, , $list] = household();

        $this->actingAs($mate)->patch("/shipshape/lists/{$list->id}", ['editors' => 'captains'])->assertForbidden();
        $this->actingAs($captain)->patch("/shipshape/lists/{$list->id}", ['editors' => 'captains'])->assertRedirect();

        expect($list->fresh()->editors)->toBe('captains')
            ->and(AuditLog::where('action', 'chore_list.editors_changed')->exists())->toBeTrue();
    });

    it('stops crew editing on a captains-only list, but never stops them ticking', function () {
        [, $mate, , $list] = household();
        $list->update(['editors' => ChoreList::EDITORS_CAPTAINS]);
        $chore = addChore($list);

        $this->actingAs($mate)->get("/shipshape/chores/{$chore->id}/edit")->assertForbidden();
        $this->actingAs($mate)->get("/shipshape/lists/{$list->id}/chores/create")->assertForbidden();
        $this->actingAs($mate)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $chore->version])->assertSessionHasNoErrors();

        expect($chore->entries()->count())->toBe(1);
    });
});

describe('logging', function () {
    it('logs for a passenger, and flashes what to undo', function () {
        [, $mate, , $list, $alfie] = household();
        $chore = addChore($list, ['title' => 'Feed the cat', 'every' => 1, 'unit' => 'day']);

        $this->actingAs($mate)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $chore->version, 'done_by' => $alfie->id])
            ->assertSessionHas('logged', fn ($logged) => $logged['title'] === 'Feed the cat' && $logged['by'] === 'Alfie');

        $entry = ChoreEntry::sole();
        expect($entry->done_by_member_id)->toBe($alfie->id)
            ->and($entry->logged_by_user_id)->toBe($mate->id)
            ->and($chore->fresh()->due_on->toDateString())->toBe('2026-10-21');
    });

    it("defaults to you, and won't log as someone from another crew", function () {
        [, $mate, , $list] = household();
        $chore = addChore($list);
        $outsider = Crew::create(['name' => 'Elsewhere'])->members()->create(['user_id' => deckhand()->id, 'role' => 'captain', 'joined_at' => now()]);

        $this->actingAs($mate)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $chore->version, 'done_by' => $outsider->id])->assertNotFound();
        $this->actingAs($mate)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $chore->version]);

        expect(ChoreEntry::sole()->done_by_name)->toBe('Sam');
    });

    it('turns a stale tick into a friendly error, not a second entry', function () {
        [$captain, $mate, , $list] = household();
        $chore = addChore($list);
        $version = $chore->version;

        $this->actingAs($captain)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $version]);
        $this->actingAs($mate)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $version])
            ->assertSessionHasErrors(['chore' => 'Charlie just updated “Hoover” — have a look before logging it again.']);

        expect(ChoreEntry::count())->toBe(1);
    });

    it('undoes from the toast — anyone aboard can', function () {
        [$captain, $mate, , $list] = household();
        $chore = addChore($list);
        $this->actingAs($captain)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $chore->version]);

        $this->actingAs($mate)->post('/shipshape/entries/'.ChoreEntry::sole()->id.'/undo')->assertSessionHas('status');

        expect($chore->fresh()->due_on->toDateString())->toBe('2026-10-20')
            ->and(ChoreEntry::sole()->void_reason)->toBe('undo');
    });
});

describe('chores', function () {
    it('needs at least two crew for a rota, and members from this crew', function () {
        [$captain, , , $list, $alfie] = household();

        $base = ['title' => 'Bins', 'mode' => 'floating', 'every' => 1, 'unit' => 'week', 'due_on' => '2026-10-20', 'assign' => 'rota'];

        $this->actingAs($captain)->post("/shipshape/lists/{$list->id}/chores", $base + ['rota' => [$alfie->id]])->assertSessionHasErrors('rota');
        $this->actingAs($captain)->post("/shipshape/lists/{$list->id}/chores", $base + ['rota' => [$alfie->id, 999999]])->assertSessionHasErrors('rota');

        $members = $list->assignableMembers()->pluck('id')->all();
        $this->actingAs($captain)->post("/shipshape/lists/{$list->id}/chores", $base + ['rota' => array_reverse($members)])->assertSessionHasNoErrors();

        expect(Chore::sole()->rotaMembers->pluck('id')->all())->toBe(array_reverse($members));
    });

    it('lets a fixed chore wait for a date, but a floating one needs one', function () {
        $me = deckhand();
        $list = ChoreList::make(['name' => 'Mine']);
        $list->owner()->associate($me)->save();

        $this->actingAs($me)->post("/shipshape/lists/{$list->id}/chores", ['title' => 'MOT', 'mode' => 'fixed', 'every' => 1, 'unit' => 'year', 'assign' => 'anyone'])->assertSessionHasNoErrors();
        $this->actingAs($me)->post("/shipshape/lists/{$list->id}/chores", ['title' => 'Oven', 'mode' => 'floating', 'every' => 3, 'unit' => 'month', 'assign' => 'anyone'])->assertSessionHasErrors('due_on');

        $this->actingAs($me)->get('/shipshape')->assertInertia(fn (Assert $page) => $page->where('chores.0.group', 'nodate'));
    });

    it('forces "anyone" on a private list', function () {
        $me = deckhand();
        $list = ChoreList::make(['name' => 'Mine']);
        $list->owner()->associate($me)->save();

        $this->actingAs($me)->post("/shipshape/lists/{$list->id}/chores", ['title' => 'Plants', 'mode' => 'floating', 'every' => 1, 'unit' => 'week', 'due_on' => '2026-10-20', 'assign' => 'rota', 'rota' => [1, 2]])->assertSessionHasNoErrors();

        expect(Chore::sole()->assign)->toBe(Chore::ANYONE);
    });

    it('soft-deletes and restores, keeping history', function () {
        [$captain, , , $list] = household();
        $chore = addChore($list);
        $this->actingAs($captain)->post("/shipshape/chores/{$chore->id}/log", ['kind' => 'done', 'version' => $chore->version]);

        $this->actingAs($captain)->delete("/shipshape/chores/{$chore->id}")->assertRedirect("/shipshape/lists/{$list->id}");
        expect(Chore::count())->toBe(0)->and(ChoreEntry::count())->toBe(1);

        $this->actingAs($captain)->post("/shipshape/chores/{$chore->id}/restore");
        expect(Chore::count())->toBe(1);
    });
});

describe('lists and starters', function () {
    it('makes a crew list (audited) and lands on the picker', function () {
        [$captain, , $crew] = household();

        $this->actingAs($captain)->post('/shipshape/lists', ['name' => 'Garden', 'owner' => (string) $crew->id])
            ->assertRedirect('/shipshape/lists/'.ChoreList::latest('id')->first()->id.'/starters');

        expect(AuditLog::where('action', 'chore_list.created')->exists())->toBeTrue();
    });

    it("won't make a list for a crew you're not aboard", function () {
        [, , $crew] = household();

        $this->actingAs(deckhand())->post('/shipshape/lists', ['name' => 'Sneaky', 'owner' => (string) $crew->id])->assertNotFound();
    });

    it('adds picked starters: staggered, bin day, needs-a-date, never twice', function () {
        $me = deckhand();
        $list = ChoreList::make(['name' => 'Mine']);
        $list->owner()->associate($me)->save();

        $this->actingAs($me)->post("/shipshape/lists/{$list->id}/starters", [
            'keys' => ['hoover', 'bathroom', 'bedding', 'bins', 'mot', 'car-tax', 'oven', 'gutters'],
            'dates' => ['car-tax' => '2027-03-01'],
            'weekday' => 4, // Thursday
        ])->assertRedirect('/shipshape');

        $chores = Chore::all()->keyBy('title');
        expect($chores)->toHaveCount(8)
            ->and($chores['Clean the oven & hob']->due_on->toDateString())->not->toBe('2026-10-20')
            ->and($chores['Gutters & drains']->due_on->toDateString())->not->toBe('2026-10-20')
            ->and($chores['Hoover & mop floors']->due_on->toDateString())->toBe('2026-10-20')
            ->and($chores['Clean the bathroom']->due_on->toDateString())->not->toBe('2026-10-20')
            ->and($chores['Bins & recycling out']->due_on->toDateString())->toBe('2026-10-22')
            ->and($chores['Bins & recycling out']->mode)->toBe(Chore::FIXED)
            ->and($chores['Car MOT']->due_on)->toBeNull()
            ->and($chores['Car tax']->due_on->toDateString())->toBe('2027-03-01')
            ->and($chores['Car tax']->lead_days)->toBe(14);

        $this->actingAs($me)->post("/shipshape/lists/{$list->id}/starters", ['keys' => ['hoover']]);
        expect(Chore::count())->toBe(8);

        $this->actingAs($me)->get("/shipshape/lists/{$list->id}/starters")->assertInertia(fn (Assert $page) => $page
            ->where('fresh', false)
            ->where('groups.1.items.0.present', true));
    });
});

describe('the dashboard', function () {
    it("shows today's chores and birthdays coming up, each only with its flag", function () {
        [$captain, , , $list] = household();
        Feature::for($captain)->activate(BirthdayEngine::class);
        addChore($list, ['title' => 'Today']);
        addChore($list, ['title' => 'Late', 'due_on' => '2026-10-15']);
        addChore($list, ['title' => 'Later', 'due_on' => '2026-11-20']);
        Person::factory()->for($captain, 'owner')->birthday(25, 10, 1990)->create(['name' => 'Sue']);
        Person::factory()->for($captain, 'owner')->birthday(25, 12)->create(['name' => 'Far off']);

        $this->actingAs($captain)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('chores', 2)
            ->where('chores.0.title', 'Late')
            ->has('birthdays', 1)
            ->where('birthdays.0.name', 'Sue')
            ->where('birthdays.0.turns', 36));

        $plain = User::factory()->create();
        $this->actingAs($plain)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('chores', null)->where('birthdays', null));
    });
});
