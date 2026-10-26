<?php

use App\Features\Barometer;
use App\Mcp\OffingServer;
use App\Mcp\Tools\GetBarometerStats;
use App\Mcp\Tools\LogReading;
use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Thursday 22 October 2026, 14:20 in London.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 14:20', 'Europe/London'));
});

function barometerToolUser(): User
{
    $user = User::factory()->create(['name' => 'Charlie', 'timezone' => 'Europe/London']);
    Feature::for($user)->activate(Barometer::class);

    return $user;
}

function barometerGrant(string $level = 'write-new', bool $barometer = true): void
{
    app()->instance(AiGrant::class, new AiGrant(['level' => $level, 'include_private' => false, 'barometer' => $barometer]));
}

describe('log_reading', function () {
    it('isn’t offered without the Barometer switch, the flag, or write access', function () {
        $user = barometerToolUser();

        barometerGrant(barometer: false);
        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 5])->assertHasErrors();

        barometerGrant(level: 'read');
        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 5])->assertHasErrors();

        barometerGrant();
        Feature::for($user)->deactivate(Barometer::class);
        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 5])->assertHasErrors();

        expect(BarometerReading::count())->toBe(0);
    });

    it('logs a reading as now, saves the details, and never echoes them', function () {
        $user = barometerToolUser();
        barometerGrant();

        OffingServer::actingAs($user)->tool(LogReading::class, [
            'rating' => 7,
            'situation' => 'Crowded train',
            'thoughts' => 'Everyone is staring',
            'what_i_did' => 'Breathing',
        ])
            ->assertOk()
            ->assertSee(['Logged in Barometer: 7/10, Thu 22 Oct at 14:20.', 'Saved with it: situation, thoughts, what did i do?.'])
            ->assertDontSee(['Crowded', 'staring', 'Breathing']);

        $reading = $user->barometerReadings()->sole();
        expect($reading->rating_before)->toBe(7)
            ->and($reading->occurred_at?->utc()->format('Y-m-d H:i'))->toBe('2026-10-22 13:20')
            ->and($reading->local_date)->toBe('2026-10-22')
            ->and($reading->tzid)->toBe('Europe/London')
            ->and($reading->thoughts)->toBe('Everyone is staring')
            ->and($reading->coping_action)->toBe('Breathing');

        $log = AuditLog::where('action', 'barometer.reading.created')->sole();
        expect($log->meta)->toMatchArray(['local_date' => '2026-10-22', 'via' => 'ai'])
            ->and(json_encode($log->toArray()))->not->toContain('staring');
    });

    it('logs an earlier day without a time when only the date is given', function () {
        $user = barometerToolUser();
        barometerGrant();

        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 6, 'date' => '2026-10-19'])
            ->assertSee('6/10, Mon 19 Oct (time not recorded).');

        $reading = $user->barometerReadings()->sole();
        expect($reading->occurred_at)->toBeNull()->and($reading->local_date)->toBe('2026-10-19');
    });

    it('refuses the future and out-of-range ratings, like the app', function () {
        $user = barometerToolUser();
        barometerGrant();

        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 5, 'date' => '2026-10-23'])->assertHasErrors(['That day is in the future.']);
        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 5, 'time' => '18:00'])->assertHasErrors(['That time is in the future.']);
        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 11])->assertHasErrors();
        OffingServer::actingAs($user)->tool(LogReading::class, [])->assertHasErrors(['Give rating (0–10) for a new reading']);

        expect(BarometerReading::count())->toBe(0);
    });

    it('doesn’t log the same reading twice when a call is retried', function () {
        $user = barometerToolUser();
        barometerGrant();

        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 7])->assertSee('Logged in Barometer');
        OffingServer::actingAs($user)->tool(LogReading::class, ['rating' => 7])->assertSee('not logged twice');

        expect(BarometerReading::count())->toBe(1);
    });

    it('adds a second rating to today’s latest reading without one', function () {
        $user = barometerToolUser();
        barometerGrant();
        BarometerReading::factory()->for($user)->at('2026-10-22 09:00')->create(['rating_before' => 5, 'rating_after' => 4]);
        $latest = BarometerReading::factory()->for($user)->at('2026-10-22 13:50')->create(['rating_before' => 8, 'rating_after' => null]);
        BarometerReading::factory()->for($user)->at('2026-10-21 20:00')->create(['rating_after' => null]);

        OffingServer::actingAs($user)->tool(LogReading::class, ['second_rating' => 3])
            ->assertSee('Added a second rating of 3/10 to today’s 13:50 reading (first rating 8/10).');

        expect($latest->fresh()->rating_after)->toBe(3)
            ->and(AuditLog::where('action', 'barometer.reading.second_rating')->sole()->subject_id)->toBe($latest->id);
    });

    it('says so when there’s nothing from today to add a second rating to', function () {
        $user = barometerToolUser();
        barometerGrant();
        BarometerReading::factory()->for($user)->at('2026-10-21 20:00')->create(['rating_after' => null]);

        OffingServer::actingAs($user)->tool(LogReading::class, ['second_rating' => 3])->assertHasErrors(['no reading from today']);
    });
});

describe('get_barometer_stats', function () {
    it('isn’t offered without the Barometer switch or the flag', function () {
        $user = barometerToolUser();

        barometerGrant(level: 'edit', barometer: false);
        OffingServer::actingAs($user)->tool(GetBarometerStats::class, [])->assertHasErrors();

        barometerGrant(level: 'read');
        Feature::for($user)->deactivate(Barometer::class);
        OffingServer::actingAs($user)->tool(GetBarometerStats::class, [])->assertHasErrors();
    });

    it('works on a read-only connection with the switch on', function () {
        barometerGrant(level: 'read');

        OffingServer::actingAs(barometerToolUser())->tool(GetBarometerStats::class, [])
            ->assertOk()
            ->assertSee(['Barometer summary, Wed 23 Sep – Thu 22 Oct (30 days).', 'they don\'t diagnose anything', 'No readings in this range.']);
    });

    it('summarises numbers only — never a reading’s words', function () {
        $user = barometerToolUser();
        barometerGrant(level: 'read');
        $mine = BarometerReading::factory()->for($user);
        $mine->at('2026-10-12 08:30')->create(['rating_before' => 8, 'rating_after' => 5, 'thoughts' => 'SECRET-THOUGHT']); // Mon, morning
        $mine->at('2026-10-14 19:00')->create(['rating_before' => 6, 'rating_after' => 2, 'situation' => 'SECRET-SITUATION']); // Wed, evening
        $mine->untimed('2026-10-20')->create(['rating_before' => 4, 'rating_after' => null]); // Tue, no time
        $mine->at('2026-10-21 23:30')->create(['rating_before' => 7, 'rating_after' => null]); // Wed, night
        $mine->at('2026-08-01 10:00')->create(['rating_before' => 10]); // outside the range
        BarometerReading::factory()->for(barometerToolUser())->at('2026-10-15 10:00')->create(['rating_before' => 1]); // someone else's

        $response = OffingServer::actingAs($user)->tool(GetBarometerStats::class, []);

        $response->assertOk()->assertSee([
            'Readings: 4 (1 without a time).',
            'First rating: average 6.3, lowest 4, highest 8.',
            'Second rating: on 2 of 4; on those, average 7.0 then 3.5 — an average change of −3.5.',
            '- w/c Mon 12 Oct: 2 readings, average 7.0, average change −3.5 over 2 second ratings',
            '- w/c Mon 19 Oct: 2 readings, average 5.5',
            'By time of day (readings with a time): morning (5am–noon) 1 (average 8.0) · afternoon (noon–5pm) 0 · evening (5–10pm) 1 (average 6.0) · night (10pm–5am) 1 (average 7.0).',
            'By weekday: Mon 1 · Tue 1 · Wed 2 · Thu 0 · Fri 0 · Sat 0 · Sun 0.',
        ])->assertDontSee(['SECRET', '10.0', 'highest 10']);
    });

    it('takes a range, but not a backwards or overlong one', function () {
        $user = barometerToolUser();
        barometerGrant(level: 'read');
        BarometerReading::factory()->for($user)->at('2026-08-01 10:00')->create(['rating_before' => 9, 'rating_after' => null]);

        OffingServer::actingAs($user)->tool(GetBarometerStats::class, ['from' => '2026-08-01', 'to' => '2026-08-31'])
            ->assertSee(['Sat 1 Aug – Mon 31 Aug (31 days)', 'Readings: 1.', 'Second rating: none recorded.', 'By time of day (readings with a time): morning']);

        OffingServer::actingAs($user)->tool(GetBarometerStats::class, ['from' => '2026-10-10', 'to' => '2026-10-01'])->assertHasErrors(['on or after']);
        OffingServer::actingAs($user)->tool(GetBarometerStats::class, ['from' => '2025-01-01', 'to' => '2026-10-01'])->assertHasErrors(['too long']);
    });
});
