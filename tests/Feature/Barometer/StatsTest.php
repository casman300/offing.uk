<?php

use App\Features\Barometer;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\User;
use App\Support\Barometer\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Thursday 22 October 2026, 14:20 in London.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 14:20', 'Europe/London'));

    $this->me = User::factory()->create(['name' => 'Charlie', 'timezone' => 'Europe/London']);
    Feature::for($this->me)->activate(Barometer::class);
});

/** Four of mine in 12–21 Oct: two paired, one untimed, one at night; plus noise that must not count. */
function seedStatsReadings(User $me): void
{
    $mine = BarometerReading::factory()->for($me);
    $mine->at('2026-10-12 08:30')->create(['rating_before' => 8, 'rating_after' => 5, 'thoughts' => 'SECRET-THOUGHT']); // Mon morning
    $mine->at('2026-10-14 19:00')->create(['rating_before' => 6, 'rating_after' => 2, 'situation' => 'SECRET-SITUATION']); // Wed evening
    $mine->untimed('2026-10-20')->create(['rating_before' => 4, 'rating_after' => null]); // Tue, no time
    $mine->at('2026-10-21 23:30')->create(['rating_before' => 7, 'rating_after' => null]); // Wed night
    $mine->at('2026-08-01 10:00')->create(['rating_before' => 10, 'rating_after' => 4]); // before the range

    $someoneElse = User::factory()->create();
    BarometerReading::factory()->for($someoneElse)->at('2026-10-15 10:00')->create(['rating_before' => 1, 'rating_after' => 0]);
}

function octoberStats(User $me): Stats
{
    return Stats::for($me, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-22'));
}

describe('the numbers', function () {
    it('counts and averages my readings in range only', function () {
        seedStatsReadings($this->me);

        $stats = octoberStats($this->me);

        expect($stats->days())->toBe(11)
            ->and($stats->count())->toBe(4)
            ->and($stats->untimed())->toBe(1)
            ->and($stats->firstRating())->toBe(['average' => 6.25, 'lowest' => 4, 'highest' => 8])
            // Only the two with a second rating: 8→5 and 6→2.
            ->and($stats->secondRating())->toBe(['paired' => 2, 'before' => 7.0, 'after' => 3.5, 'change' => -3.5]);
    });

    it('lists every week in the range, empty ones too', function () {
        seedStatsReadings($this->me);

        expect(octoberStats($this->me)->weeks())->toBe([
            ['start' => '2026-10-12', 'count' => 2, 'average' => 7.0, 'paired' => 2, 'change' => -3.5],
            ['start' => '2026-10-19', 'count' => 2, 'average' => 5.5, 'paired' => 0, 'change' => null],
        ]);

        $quiet = Stats::for($this->me, CarbonImmutable::parse('2026-09-28'), CarbonImmutable::parse('2026-10-13'));
        expect(array_column($quiet->weeks(), 'count', 'start'))->toBe(['2026-09-28' => 0, '2026-10-05' => 0, '2026-10-12' => 1]);
    });

    it('skips untimed readings for time of day but counts them by weekday', function () {
        seedStatsReadings($this->me);
        $stats = octoberStats($this->me);

        expect($stats->timesOfDay())->toBe([
            'morning' => ['count' => 1, 'average' => 8.0],
            'afternoon' => ['count' => 0, 'average' => null],
            'evening' => ['count' => 1, 'average' => 6.0],
            'night' => ['count' => 1, 'average' => 7.0],
        ])->and($stats->weekdays())->toBe([1 => 1, 2 => 1, 3 => 2, 4 => 0, 5 => 0, 6 => 0, 7 => 0]);

        $heatmap = $stats->heatmap();
        // Tuesday's only reading has no time: in the total, in no square.
        expect($heatmap[2]['total'])->toBe(1)
            ->and(array_sum(array_column($heatmap[2]['times'], 'count')))->toBe(0)
            ->and($heatmap[3]['total'])->toBe(2)
            ->and($heatmap[3]['times']['evening'])->toBe(['count' => 1, 'average' => 6.0])
            ->and($heatmap[3]['times']['night'])->toBe(['count' => 1, 'average' => 7.0])
            ->and($heatmap[1]['times']['morning'])->toBe(['count' => 1, 'average' => 8.0]);
    });

    it('goes by the reading’s own local day and hour, not UTC', function () {
        // 10pm Sunday in New York is 2am Monday UTC.
        BarometerReading::factory()->for($this->me)->at('2026-10-11 22:00', 'America/New_York')->create(['rating_before' => 5, 'rating_after' => null]);

        $monday = Stats::for($this->me, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-18'));
        expect($monday->count())->toBe(0);

        $sunday = Stats::for($this->me, CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-10-11'));
        expect($sunday->weekdays()[7])->toBe(1)
            ->and($sunday->heatmap()[7]['times']['night']['count'])->toBe(1)
            ->and($sunday->weeks())->toBe([['start' => '2026-10-05', 'count' => 1, 'average' => 5.0, 'paired' => 0, 'change' => null]]);
    });

    it('has nothing to say about second ratings when there are none', function () {
        BarometerReading::factory()->for($this->me)->quick()->at('2026-10-20 09:00')->create(['rating_before' => 3]);

        $stats = octoberStats($this->me);

        expect($stats->secondRating())->toBeNull()
            ->and($stats->toPageArray()['second'])->toBeNull()
            ->and($stats->toPageArray()['first'])->toBe(['average' => 3.0, 'lowest' => 3, 'highest' => 3]);
    });

    it('covers a year at most, forwards only', function () {
        expect(Stats::validRange(CarbonImmutable::parse('2025-10-22'), CarbonImmutable::parse('2026-10-22')))->toBeTrue()
            ->and(Stats::validRange(CarbonImmutable::parse('2025-10-21'), CarbonImmutable::parse('2026-10-22')))->toBeFalse()
            ->and(Stats::validRange(CarbonImmutable::parse('2026-10-22'), CarbonImmutable::parse('2026-10-21')))->toBeFalse();

        Stats::for($this->me, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2026-10-22'));
    })->throws(InvalidArgumentException::class);

    it('never reads the text columns', function () {
        seedStatsReadings($this->me);
        DB::enableQueryLog();

        $page = octoberStats($this->me)->toPageArray();

        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'barometer_readings'));
        expect($queries)->toHaveCount(1);

        foreach (BarometerReading::TEXT_COLUMNS as $column) {
            expect($queries->first())->not->toContain($column);
        }
        expect($queries->first())->not->toContain('*')
            ->and(json_encode($page))->not->toContain('SECRET');
    });
});

describe('the page', function () {
    it('shows the last 12 weeks by default', function () {
        seedStatsReadings($this->me);

        $this->actingAs($this->me)->get('/barometer/stats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('barometer/stats')
                ->where('today', '2026-10-22')
                ->where('stats.from', '2026-07-31')
                ->where('stats.to', '2026-10-22')
                ->where('stats.days', 84)
                // The 1 August reading is in the default range too.
                ->where('stats.count', 5)
                ->where('stats.first.highest', 10)
                ->where('stats.second', ['paired' => 3, 'before' => 8, 'after' => 3.7, 'change' => -4.3]) // JSON drops the .0
                ->has('stats.weeks', 13)
                ->has('stats.heatmap', 7)
                ->where('stats.heatmap.0.label', 'Mon')
                ->where('presets.1', ['label' => '12 weeks', 'from' => '2026-07-31', 'to' => '2026-10-22']));
    });

    it('takes a range, rounding to one decimal place', function () {
        seedStatsReadings($this->me);

        $this->actingAs($this->me)->get('/barometer/stats?from=2026-10-12&to=2026-10-22')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 4)
                ->where('stats.untimed', 1)
                ->where('stats.first.average', 6.3)
                ->where('stats.second.change', -3.5)
                ->where('stats.heatmap.1.total', 1)
                ->where('stats.heatmap.1.cells.0', ['time' => 'morning', 'count' => 0, 'average' => null]));
    });

    it('never counts or shows anyone else’s readings', function () {
        $other = User::factory()->create();
        Feature::for($other)->activate(Barometer::class);
        BarometerReading::factory()->for($other)->at('2026-10-20 10:00')->create(['rating_before' => 9, 'situation' => 'THEIRS']);

        $this->actingAs($this->me)->get('/barometer/stats')
            ->assertOk()
            ->assertDontSee('THEIRS')
            ->assertInertia(fn (Assert $page) => $page->where('stats.count', 0)->where('stats.first', null)->where('stats.second', null));
    });

    it('rejects a backwards or overlong range, back to the default view', function (array $query, string $message) {
        $this->actingAs($this->me)->get('/barometer/stats?'.http_build_query($query))
            ->assertRedirect('/barometer/stats')
            ->assertSessionHasErrors(['to' => $message]);
    })->with([
        'backwards' => [['from' => '2026-10-20', 'to' => '2026-10-01'], 'The end date must be on or after the start date.'],
        'over a year' => [['from' => '2025-10-01', 'to' => '2026-10-22'], 'Look back up to a year at a time.'],
        'only a start, over a year ago' => [['from' => '2025-01-01'], 'Look back up to a year at a time.'],
    ]);

    it('rejects a garbled date', function () {
        $this->actingAs($this->me)->get('/barometer/stats?from=1+Oct')
            ->assertRedirect('/barometer/stats')
            ->assertSessionHasErrors('from');
    });

    it('is a 404 while the flag is off, and needs signing in', function () {
        Feature::for($this->me)->deactivate(Barometer::class);

        $this->actingAs($this->me)->get('/barometer/stats')->assertNotFound();

        auth()->logout();
        $this->get('/barometer/stats')->assertRedirect('/login');
    });

    it('isn’t audited: it’s my own log', function () {
        $this->actingAs($this->me)->get('/barometer/stats')->assertOk();

        expect(AuditLog::count())->toBe(0);
    });
});

describe('the PDF summary page', function () {
    it('is off unless asked for', function () {
        seedStatsReadings($this->me);

        $this->actingAs($this->me)->get('/barometer/export/download?format=pdf&from=2026-10-12&to=2026-10-22')->assertOk();

        expect(AuditLog::where('action', 'barometer.exported')->sole()->meta)->not->toHaveKey('summary');
    });

    it('is audited like any export, numbers and no content', function () {
        seedStatsReadings($this->me);

        $response = $this->actingAs($this->me)->get('/barometer/export/download?format=pdf&from=2026-10-12&to=2026-10-22&summary=1');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF-');

        $log = AuditLog::where('action', 'barometer.exported')->sole();
        expect($log->meta)->toBe(['format' => 'pdf', 'from' => '2026-10-12', 'to' => '2026-10-22', 'count' => 4, 'omitted' => [], 'summary' => true])
            ->and(json_encode($log->toArray()))->not->toContain('SECRET');
    });

    it('doesn’t apply to a CSV', function () {
        $this->actingAs($this->me)->get('/barometer/export/download?format=csv&from=2026-10-12&to=2026-10-22&summary=1')->assertOk();

        expect(AuditLog::where('action', 'barometer.exported')->sole()->meta)->not->toHaveKey('summary');
    });

    it('describes the numbers before the readings', function () {
        seedStatsReadings($this->me);
        $from = CarbonImmutable::parse('2026-10-12');
        $to = CarbonImmutable::parse('2026-10-22');

        $render = fn (array $columns) => view('barometer.export', [
            'name' => 'Charlie',
            'from' => $from,
            'to' => $to,
            'timezone' => 'Europe/London',
            'columns' => $columns,
            'readings' => collect(),
            'summary' => Stats::for($this->me, $from, $to)->toPageArray(),
        ])->render();

        $html = $render(BarometerReading::COLUMNS);
        expect($html)->toContain('Anxiety log — summary')
            ->toContain('4 readings, first rating average 6.3')
            ->toContain('average 7.0,')
            ->toContain('then 3.5 — down 3.5 after what I did.')
            ->toContain('Mon 12 Oct 2026')
            ->toContain('1 reading without a time is counted under All only.')
            ->not->toContain('SECRET')
            ->not->toMatch('/improv|worse|better|severe/i');

        // Leaving the second rating out of the export leaves it out of the summary too.
        expect($render(array_diff_key(BarometerReading::COLUMNS, ['rating_after' => true])))
            ->toContain('Anxiety log — summary')
            ->not->toContain('after what I did')
            ->not->toContain('Average change');
    });
});
