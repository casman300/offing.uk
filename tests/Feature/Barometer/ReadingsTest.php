<?php

use App\Features\Barometer;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function barometerUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Feature::for($user)->activate(Barometer::class);

    return $user;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
});

describe('feature flag', function () {
    it('hides every barometer route while the flag is off', function () {
        $user = User::factory()->create();
        $reading = BarometerReading::factory()->for($user)->create();

        $this->actingAs($user)->get('/barometer')->assertNotFound();
        $this->actingAs($user)->get('/barometer/create')->assertNotFound();
        $this->actingAs($user)->post('/barometer', ['date' => '2026-10-16', 'time' => '11:00', 'rating_before' => 5])->assertNotFound();
        $this->actingAs($user)->get("/barometer/{$reading->id}/edit")->assertNotFound();
        $this->actingAs($user)->delete("/barometer/{$reading->id}")->assertNotFound();
        $this->actingAs($user)->get('/barometer/export?format=csv&from=2026-10-01&to=2026-10-16')->assertNotFound();

        expect(BarometerReading::count())->toBe(1);
    });

    it('is off for non-admins by default and shared with the frontend', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('features.barometer', false));

        Feature::for($user)->activate(Barometer::class);
        Feature::flushCache();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('features.barometer', true));
    });

    it('redirects guests to login', function () {
        $this->get('/barometer')->assertRedirect('/login');
    });
});

describe('recording', function () {
    it('saves a quick reading with just a time and a rating', function () {
        $user = barometerUser();

        $this->actingAs($user)
            ->post('/barometer', ['date' => '2026-10-16', 'time' => '10:15', 'rating_before' => 8])
            ->assertRedirect('/barometer')
            ->assertSessionHas('status', 'reading-saved');

        $reading = $user->barometerReadings()->sole();
        expect($reading->rating_before)->toBe(8)
            ->and($reading->rating_after)->toBeNull()
            ->and($reading->situation)->toBeNull()
            // 10:15 BST is 09:15 UTC.
            ->and($reading->occurred_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-16 09:15')
            ->and($reading->tzid)->toBe('Europe/London')
            ->and($reading->local_date)->toBe('2026-10-16');
    });

    it('saves every header, trimming text and treating blanks as empty', function () {
        $user = barometerUser();

        $this->actingAs($user)->post('/barometer', [
            'date' => '2026-10-16', 'time' => '10:15',
            'situation' => '  Riding the bus, it was crowded  ',
            'thoughts' => 'Everyone is looking at me.',
            'sensations' => 'Heart pounding',
            'rating_before' => 8,
            'coping_action' => 'Deep breaths',
            'self_talk' => '   ',
            'rating_after' => 0,
        ])->assertRedirect('/barometer');

        $reading = $user->barometerReadings()->sole();
        expect($reading->situation)->toBe('Riding the bus, it was crowded')
            ->and($reading->self_talk)->toBeNull()
            ->and($reading->rating_after)->toBe(0);
    });

    it('encrypts the free text at rest but keeps ratings plain', function () {
        $user = barometerUser();

        $this->actingAs($user)->post('/barometer', [
            'date' => '2026-10-16', 'time' => '10:15',
            'thoughts' => 'I am going to panic',
            'rating_before' => 7,
        ]);

        $raw = DB::table('barometer_readings')->sole();
        expect($raw->thoughts)->not->toContain('panic')
            ->and((int) $raw->rating_before)->toBe(7)
            ->and($user->barometerReadings()->sole()->thoughts)->toBe('I am going to panic');
    });

    it('works out the local date in the user\'s own timezone', function () {
        $user = barometerUser(['timezone' => 'America/Los_Angeles']);
        // 12:00 London is 04:00 in Los Angeles on the same day; 23:30 LA on the 15th is the 16th in UTC.
        $this->actingAs($user)->post('/barometer', ['date' => '2026-10-15', 'time' => '23:30', 'rating_before' => 4]);

        $reading = $user->barometerReadings()->sole();
        expect($reading->tzid)->toBe('America/Los_Angeles')
            ->and($reading->local_date)->toBe('2026-10-15')
            ->and($reading->occurred_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-16 06:30');
    });

    it('validates ratings, time and length', function (array $input, string $field) {
        $user = barometerUser();

        $this->actingAs($user)
            ->post('/barometer', [...['date' => '2026-10-16', 'time' => '10:15', 'rating_before' => 5], ...$input])
            ->assertSessionHasErrors($field);

        expect(BarometerReading::count())->toBe(0);
    })->with([
        'missing rating' => [['rating_before' => null], 'rating_before'],
        'rating above 10' => [['rating_before' => 11], 'rating_before'],
        'negative rating' => [['rating_before' => -1], 'rating_before'],
        'second rating above 10' => [['rating_after' => 11], 'rating_after'],
        'missing day' => [['date' => ''], 'date'],
        'garbled day' => [['date' => 'yesterday'], 'date'],
        'garbled time' => [['time' => '25:99'], 'time'],
        'later today' => [['time' => '13:30'], 'time'],
        'a future day without a time' => [['date' => '2026-10-17', 'time' => null], 'date'],
        'too long' => [['thoughts' => str_repeat('a', 5001)], 'thoughts'],
    ]);

    it('backfills an old reading onto its own day', function () {
        $user = barometerUser();

        // A winter (GMT) time logged from summer (BST): the offset is the date's, not today's.
        $this->actingAs($user)
            ->post('/barometer', ['date' => '2026-01-20', 'time' => '21:40', 'rating_before' => 6])
            ->assertSessionHasNoErrors();

        $reading = $user->barometerReadings()->sole();
        expect($reading->local_date)->toBe('2026-01-20')
            ->and($reading->occurred_at->utc()->format('Y-m-d H:i'))->toBe('2026-01-20 21:40');

        $csv = $this->actingAs($user)->get('/barometer/export?format=csv&from=2026-01-01&to=2026-01-31')->streamedContent();
        expect($csv)->toContain('2026-01-20,21:40');
    });

    it('saves a remembered worry against its day without inventing a time', function () {
        $user = barometerUser();

        $this->actingAs($user)
            ->post('/barometer', ['date' => '2026-10-12', 'time' => '', 'rating_before' => 7, 'thoughts' => 'Kept replaying the meeting'])
            ->assertSessionHasNoErrors();

        $reading = $user->barometerReadings()->sole();
        expect($reading->occurred_at)->toBeNull()
            ->and($reading->local_date)->toBe('2026-10-12')
            ->and($reading->tzid)->toBe('Europe/London');
    });

    it('allows today without a time', function () {
        $this->actingAs(barometerUser())
            ->post('/barometer', ['date' => '2026-10-16', 'rating_before' => 2])
            ->assertSessionHasNoErrors();
    });

    it('allows a few minutes of clock skew', function () {
        $user = barometerUser();

        // Now is 12:00 BST; a phone a couple of minutes fast is fine.
        $this->actingAs($user)
            ->post('/barometer', ['date' => '2026-10-16', 'time' => '12:03', 'rating_before' => 5])
            ->assertSessionHasNoErrors();
    });
});

describe('list', function () {
    it('shows only my readings, newest first, in my timezone', function () {
        $me = barometerUser();
        BarometerReading::factory()->for($me)->at('2026-10-14 08:00')->create(['rating_before' => 3]);
        BarometerReading::factory()->for($me)->at('2026-10-16 10:15')->create(['rating_before' => 8, 'rating_after' => 5, 'situation' => 'Bus']);
        BarometerReading::factory()->for(User::factory())->create();

        $this->actingAs($me)->get('/barometer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('barometer/index')
                ->has('readings', 2)
                ->where('readings.0.date', '2026-10-16')
                ->where('readings.0.time', '10:15')
                ->where('readings.0.rating_before', 8)
                ->where('readings.0.rating_after', 5)
                ->where('readings.0.situation', 'Bus')
                ->where('readings.1.date', '2026-10-14')
                ->where('exportDefaults.from', '2026-09-17')
                ->where('exportDefaults.to', '2026-10-16')
                ->where('nextPageUrl', null));
    });

    it('lists untimed readings with their day, after that day\'s timed ones', function () {
        $me = barometerUser();
        BarometerReading::factory()->for($me)->untimed('2026-10-14')->create(['rating_before' => 7]);
        BarometerReading::factory()->for($me)->at('2026-10-14 08:00')->create(['rating_before' => 3]);
        BarometerReading::factory()->for($me)->at('2026-10-13 22:00')->create(['rating_before' => 5]);

        $this->actingAs($me)->get('/barometer')
            ->assertInertia(fn (Assert $page) => $page
                ->where('readings.0.date', '2026-10-14')
                ->where('readings.0.time', '08:00')
                ->where('readings.1.date', '2026-10-14')
                ->where('readings.1.time', null)
                ->where('readings.1.rating_before', 7)
                ->where('readings.2.date', '2026-10-13'));
    });

    it('pages after 50 readings', function () {
        $me = barometerUser();
        BarometerReading::factory()->for($me)->count(51)->create();

        $this->actingAs($me)->get('/barometer')
            ->assertInertia(fn (Assert $page) => $page->has('readings', 50)->where('nextPageUrl', fn ($url) => str_contains($url, 'page=2')));

        $this->actingAs($me)->get('/barometer?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('readings', 1));
    });
});

describe('editing and deleting', function () {
    it('adds the second rating later', function () {
        $me = barometerUser();
        $reading = BarometerReading::factory()->for($me)->quick()->at('2026-10-16 10:15')->create(['rating_before' => 8]);

        $this->actingAs($me)->get("/barometer/{$reading->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('barometer/edit')
                ->where('reading.date', '2026-10-16')
                ->where('reading.time', '10:15')
                ->where('reading.rating_after', null));

        $this->actingAs($me)
            ->patch("/barometer/{$reading->id}", ['date' => '2026-10-16', 'time' => '10:15', 'rating_before' => 8, 'rating_after' => 4])
            ->assertRedirect('/barometer');

        expect($reading->fresh()->rating_after)->toBe(4);
    });

    it('can drop the time from a reading, keeping its day', function () {
        $me = barometerUser();
        $reading = BarometerReading::factory()->for($me)->at('2026-10-14 10:15')->create(['rating_before' => 8]);

        $this->actingAs($me)
            ->patch("/barometer/{$reading->id}", ['date' => '2026-10-14', 'time' => '', 'rating_before' => 8])
            ->assertSessionHasNoErrors();

        $reading->refresh();
        expect($reading->occurred_at)->toBeNull()
            ->and($reading->local_date)->toBe('2026-10-14');

        $this->actingAs($me)->get("/barometer/{$reading->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('reading.date', '2026-10-14')->where('reading.time', null));
    });

    it('hard-deletes, and audits without content', function () {
        $me = barometerUser();
        $reading = BarometerReading::factory()->for($me)->at('2026-10-16 10:15')->create(['thoughts' => 'secret']);

        $this->actingAs($me)->delete("/barometer/{$reading->id}")->assertRedirect('/barometer');

        expect(DB::table('barometer_readings')->count())->toBe(0);

        $log = AuditLog::where('action', 'barometer.reading.deleted')->sole();
        expect($log->meta)->toBe(['local_date' => '2026-10-16'])
            ->and(json_encode($log->toArray()))->not->toContain('secret');
    });

    it('404s on someone else\'s reading', function () {
        $me = barometerUser();
        $theirs = BarometerReading::factory()->for(barometerUser())->create();

        $this->actingAs($me)->get("/barometer/{$theirs->id}/edit")->assertNotFound();
        $this->actingAs($me)->patch("/barometer/{$theirs->id}", ['date' => '2026-10-16', 'time' => '10:15', 'rating_before' => 1])->assertNotFound();
        $this->actingAs($me)->delete("/barometer/{$theirs->id}")->assertNotFound();

        expect($theirs->fresh())->not->toBeNull();
    });

    it('deletes readings with the account', function () {
        $me = barometerUser();
        BarometerReading::factory()->for($me)->count(2)->create();

        $me->delete();

        expect(BarometerReading::count())->toBe(0);
    });
});
