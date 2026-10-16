<?php

use App\Features\BirthdayEngine;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function engineUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Feature::for($user)->activate(BirthdayEngine::class);

    return $user;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
});

describe('feature flag', function () {
    it('hides every people route while the flag is off', function () {
        $user = User::factory()->create();
        $person = Person::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->get('/people')->assertNotFound();
        $this->actingAs($user)->get('/people/create')->assertNotFound();
        $this->actingAs($user)->post('/people', ['name' => 'Sue'])->assertNotFound();
        $this->actingAs($user)->get("/people/{$person->id}")->assertNotFound();
        $this->actingAs($user)->delete("/people/{$person->id}")->assertNotFound();

        expect($person->fresh()->trashed())->toBeFalse();
    });

    it('is off by default and shared with the frontend', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('features.birthdayEngine', false));

        Feature::for($user)->activate(BirthdayEngine::class);
        Feature::flushCache();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('features.birthdayEngine', true));
    });

    it('redirects guests to login', function () {
        $this->get('/people')->assertRedirect('/login');
    });
});

describe('list', function () {
    it('shows only my people, with birthday maths in my timezone', function () {
        $me = engineUser();
        Person::factory()->for($me, 'owner')->birthday(2, 11, 1983)->create(['name' => 'Sue']);
        Person::factory()->for($me, 'owner')->withoutBirthday()->create(['name' => 'Grandad']);
        Person::factory()->for(User::factory(), 'owner')->create(['name' => 'Not mine']);

        $this->actingAs($me)->get('/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('people/index')
                ->where('today', '2026-10-16')
                ->has('people', 2)
                ->where('people.0.name', 'Sue')
                ->where('people.0.days_until', 17)
                ->where('people.0.turns', 43)
                ->where('people.0.age', 42)
                ->where('people.0.next_birthday', '2026-11-02')
                ->where('people.1.name', 'Grandad')
                ->where('people.1.days_until', null)
                ->has('recentlyDeleted', 0));
    });

    it('uses the viewer timezone to decide what today is', function () {
        // 23:30 on 15 Oct in Los Angeles is already 16 Oct in London.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:30', 'America/Los_Angeles'));
        $la = engineUser(['timezone' => 'America/Los_Angeles']);
        Person::factory()->for($la, 'owner')->birthday(16, 10, 2000)->create();

        $this->actingAs($la)->get('/people')
            ->assertInertia(fn (Assert $page) => $page
                ->where('today', '2026-10-15')
                ->where('people.0.days_until', 1)
                ->where('people.0.age', 25));
    });

    it('shows year-only people with an approximate age', function () {
        $me = engineUser();
        Person::factory()->for($me, 'owner')->create(['birth_day' => null, 'birth_month' => null, 'birth_year' => 1950]);

        $this->actingAs($me)->get('/people')
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.0.age', 76)
                ->where('people.0.age_is_approximate', true)
                ->where('people.0.days_until', null));
    });
});

describe('create', function () {
    it('creates a person with a full birthday', function () {
        $me = engineUser();

        $response = $this->actingAs($me)->post('/people', [
            'name' => '  Auntie Sue ',
            'birth_day' => 2,
            'birth_month' => 10,
            'birth_year' => 1983,
            'notes' => 'Likes wild swimming',
        ]);

        $person = $me->people()->sole();
        $response->assertSessionHasNoErrors()->assertRedirect("/people/{$person->id}");

        expect($person->only(['name', 'birth_day', 'birth_month', 'birth_year', 'notes']))->toBe([
            'name' => 'Auntie Sue',
            'birth_day' => 2,
            'birth_month' => 10,
            'birth_year' => 1983,
            'notes' => 'Likes wild swimming',
        ])->and($person->linked_user_id)->toBeNull();
    });

    it('creates people with no birthday, no year, or only a year', function (array $birthday) {
        $me = engineUser();

        $this->actingAs($me)->post('/people', ['name' => 'Someone', ...$birthday])
            ->assertSessionHasNoErrors();

        expect($me->people()->sole()->only(['birth_day', 'birth_month', 'birth_year']))
            ->toBe(array_merge(['birth_day' => null, 'birth_month' => null, 'birth_year' => null], $birthday));
    })->with([
        'no birthday' => [[]],
        'day and month' => [['birth_day' => 29, 'birth_month' => 2]],
        'year only' => [['birth_year' => 1950]],
        'born today' => [['birth_day' => 16, 'birth_month' => 10, 'birth_year' => 2026]],
    ]);

    it('rejects bad birthdays', function (array $input, string $field) {
        $me = engineUser();

        $this->actingAs($me)->post('/people', ['name' => 'Someone', ...$input])
            ->assertSessionHasErrors($field);

        expect($me->people()->count())->toBe(0);
    })->with([
        'day without month' => [['birth_day' => 2], 'birth_month'],
        'month without day' => [['birth_month' => 2], 'birth_day'],
        '31 April' => [['birth_day' => 31, 'birth_month' => 4], 'birth_day'],
        '30 February' => [['birth_day' => 30, 'birth_month' => 2], 'birth_day'],
        '29 Feb in a non-leap year' => [['birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2001], 'birth_day'],
        'day 32' => [['birth_day' => 32, 'birth_month' => 1], 'birth_day'],
        'month 13' => [['birth_day' => 1, 'birth_month' => 13], 'birth_month'],
        'year before 1900' => [['birth_year' => 1899], 'birth_year'],
        'year after this year' => [['birth_year' => 2027], 'birth_year'],
        'later this year' => [['birth_day' => 17, 'birth_month' => 10, 'birth_year' => 2026], 'birth_year'],
        'typo year' => [['birth_day' => 1, 'birth_month' => 1, 'birth_year' => 983], 'birth_year'],
    ]);

    it('requires a name', function () {
        $this->actingAs(engineUser())->post('/people', ['name' => '   '])
            ->assertSessionHasErrors('name');
    });
});

describe('show and update', function () {
    it('shows my person', function () {
        $me = engineUser();
        $sue = Person::factory()->for($me, 'owner')->birthday(16, 10, 1983)->create(['name' => 'Sue']);

        $this->actingAs($me)->get("/people/{$sue->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('people/show')
                ->where('person.name', 'Sue')
                ->where('person.days_until', 0)
                ->where('person.age', 43)
                ->where('person.turns', 43));
    });

    it('updates my person, including clearing the birthday', function () {
        $me = engineUser();
        $sue = Person::factory()->for($me, 'owner')->birthday(2, 10, 1983)->create();

        $this->actingAs($me)->patch("/people/{$sue->id}", ['name' => 'Sue Smith', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/people/{$sue->id}");

        expect($sue->fresh()->only(['name', 'birth_day', 'birth_month', 'birth_year']))
            ->toBe(['name' => 'Sue Smith', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null]);
    });

    it('404s on other people\'s people, without touching them', function () {
        $me = engineUser();
        $theirs = Person::factory()->for(engineUser(), 'owner')->create(['name' => 'Theirs']);

        $this->actingAs($me)->get("/people/{$theirs->id}")->assertNotFound();
        $this->actingAs($me)->patch("/people/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($me)->delete("/people/{$theirs->id}")->assertNotFound();

        $theirs->delete();
        $this->actingAs($me)->post("/people/{$theirs->id}/restore")->assertNotFound();

        expect($theirs->fresh()->name)->toBe('Theirs')
            ->and($theirs->fresh()->trashed())->toBeTrue();
    });
});

describe('delete and restore', function () {
    it('soft-deletes, audits, and offers undo', function () {
        $me = engineUser();
        $sue = Person::factory()->for($me, 'owner')->create(['name' => 'Sue']);

        $this->actingAs($me)->delete("/people/{$sue->id}")
            ->assertRedirect('/people')
            ->assertSessionHas('deleted', ['id' => $sue->id, 'name' => 'Sue']);

        expect($sue->fresh()->trashed())->toBeTrue();

        $log = AuditLog::where('action', 'person.deleted')->sole();
        expect($log->actor_id)->toBe($me->id)
            ->and($log->subject_type)->toBe($sue->getMorphClass())
            ->and($log->subject_id)->toBe($sue->id)
            ->and($log->meta)->toBe(['name' => 'Sue']);

        $this->actingAs($me)->get('/people')
            ->assertInertia(fn (Assert $page) => $page
                ->has('people', 0)
                ->has('recentlyDeleted', 1)
                ->where('recentlyDeleted.0.name', 'Sue'));

        $this->actingAs($me)->get("/people/{$sue->id}")->assertNotFound();
    });

    it('restores, audits, and returns to the person', function () {
        $me = engineUser();
        $sue = Person::factory()->for($me, 'owner')->create(['name' => 'Sue']);
        $sue->delete();

        $this->actingAs($me)->post("/people/{$sue->id}/restore")
            ->assertRedirect("/people/{$sue->id}");

        expect($sue->fresh()->trashed())->toBeFalse()
            ->and(AuditLog::where('action', 'person.restored')->where('subject_id', $sue->id)->exists())->toBeTrue();
    });

    it('cannot restore someone who isn\'t deleted', function () {
        $me = engineUser();
        $sue = Person::factory()->for($me, 'owner')->create();

        $this->actingAs($me)->post("/people/{$sue->id}/restore")->assertNotFound();
    });
});

describe('offing:feature command', function () {
    it('switches the flag for one user and audits it', function () {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('offing:feature', ['feature' => 'birthday-engine', 'email' => 'ME@example.com', '--on' => true])
            ->expectsOutput('birthday-engine is on for me@example.com.')
            ->assertSuccessful();

        Feature::flushCache();
        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeTrue()
            ->and(AuditLog::where('action', 'feature.activated')->where('subject_id', $user->id)->exists())->toBeTrue();

        $this->artisan('offing:feature', ['feature' => 'birthday-engine', 'email' => 'me@example.com', '--off' => true])
            ->assertSuccessful();

        Feature::flushCache();
        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse();
    });

    it('rejects unknown features and users', function () {
        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('offing:feature', ['feature' => 'nope', 'email' => 'me@example.com'])->assertFailed();
        $this->artisan('offing:feature', ['feature' => 'birthday-engine', 'email' => 'who@example.com'])->assertFailed();
    });
});
