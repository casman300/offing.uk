<?php

use App\Features\Barometer;
use App\Http\Controllers\Barometer\ExportController;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));

    $this->me = User::factory()->create(['name' => 'Charlie']);
    Feature::for($this->me)->activate(Barometer::class);
});

function exportUrl(array $query): string
{
    return '/barometer/export?'.http_build_query([...['from' => '2026-10-01', 'to' => '2026-10-16'], ...$query]);
}

describe('csv', function () {
    it('exports my readings in range, oldest first, with a BOM and local times', function () {
        BarometerReading::factory()->for($this->me)->at('2026-10-16 10:15')->create([
            'situation' => 'Bus, crowded', 'thoughts' => 'Everyone is "looking"', 'sensations' => null,
            'rating_before' => 8, 'coping_action' => 'Deep breaths', 'self_talk' => 'I can handle this', 'rating_after' => 5,
        ]);
        BarometerReading::factory()->for($this->me)->quick()->at('2026-10-02 08:00')->create(['rating_before' => 0]);
        BarometerReading::factory()->for($this->me)->at('2026-09-30 23:00')->create(); // before the range
        BarometerReading::factory()->for(User::factory())->at('2026-10-10 09:00')->create(); // not mine

        $response = $this->actingAs($this->me)->get(exportUrl(['format' => 'csv']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('anxiety-log-2026-10-01-to-2026-10-16.csv');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        $csv = $response->streamedContent();
        expect($csv)->toStartWith("\u{FEFF}");

        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), explode("\n", trim(substr($csv, 3))));
        expect($rows)->toBe([
            ['Date', 'Time', 'Situation', 'Thoughts', 'Physical sensations', 'Rating', 'What did I do?', 'What did I say to myself?', 'Second rating'],
            ['2026-10-02', '08:00', '', '', '', '0', '', '', ''],
            ['2026-10-16', '10:15', 'Bus, crowded', 'Everyone is "looking"', '', '8', 'Deep breaths', 'I can handle this', '5'],
        ]);
    });

    it('leaves out the columns I choose', function () {
        BarometerReading::factory()->for($this->me)->at('2026-10-16 10:15')->create(['thoughts' => 'private', 'rating_after' => 2]);

        $csv = $this->actingAs($this->me)->get(exportUrl(['format' => 'csv', 'omit' => ['thoughts', 'rating_after']]))->streamedContent();

        expect($csv)->not->toContain('private')
            ->and(str_getcsv(explode("\n", substr($csv, 3))[0], escape: ''))
            ->toBe(['Date', 'Time', 'Situation', 'Physical sensations', 'Rating', 'What did I do?', 'What did I say to myself?']);
    });

    it('guards against formula injection', function (string $input, string $expected) {
        expect(ExportController::csvCell($input))->toBe($expected);
    })->with([
        ['=HYPERLINK("http://evil")', '\'=HYPERLINK("http://evil")'],
        ['+1', "'+1"],
        ['-panic', "'-panic"],
        ['@SUM(A1)', "'@SUM(A1)"],
        ["\tx", "'\tx"],
        ['Fine - really', 'Fine - really'],
    ]);
});

describe('pdf', function () {
    it('downloads a PDF and audits the export without content', function () {
        BarometerReading::factory()->for($this->me)->at('2026-10-16 10:15')->create(['thoughts' => 'secret thought']);

        $response = $this->actingAs($this->me)->get(exportUrl(['format' => 'pdf', 'omit' => ['self_talk']]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('anxiety-log-2026-10-01-to-2026-10-16.pdf');
        expect($response->getContent())->toStartWith('%PDF-')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        $log = AuditLog::where('action', 'barometer.exported')->sole();
        expect($log->actor_id)->toBe($this->me->id)
            ->and($log->meta)->toBe(['format' => 'pdf', 'from' => '2026-10-01', 'to' => '2026-10-16', 'count' => 1, 'omitted' => ['self_talk']])
            ->and(json_encode($log->toArray()))->not->toContain('secret');
    });

    it('lays the printout out like the paper form, escaping my text', function () {
        $reading = BarometerReading::factory()->for($this->me)->at('2026-10-16 10:15')->create([
            'situation' => '<b>Bus</b>', 'rating_before' => 8, 'rating_after' => 5,
        ]);

        $html = view('barometer.export', [
            'name' => 'Charlie',
            'from' => CarbonImmutable::parse('2026-10-01'),
            'to' => CarbonImmutable::parse('2026-10-16'),
            'timezone' => 'Europe/London',
            'columns' => array_diff_key(BarometerReading::COLUMNS, ['thoughts' => true]),
            'readings' => collect([$reading]),
        ])->render();

        expect($html)->toContain('<h1>Anxiety log</h1>')
            ->toContain('Charlie · 1 October 2026 to 16 October 2026')
            ->toContain('Fri 16 Oct')
            ->toContain('10:15')
            ->toContain('&lt;b&gt;Bus&lt;/b&gt;')
            ->toContain('Second rating')
            ->toContain('Made with Offing · offing.uk')
            ->not->toContain('<b>Bus</b>')
            ->not->toContain('>Thoughts<');
    });

    it('renders an empty range', function () {
        $this->actingAs($this->me)->get(exportUrl(['format' => 'pdf']))->assertOk();
    });
});

describe('validation', function () {
    it('never redirects "back" to the previous export — that would download it again', function () {
        $this->actingAs($this->me)->get(exportUrl(['format' => 'pdf']))->assertOk();

        $this->actingAs($this->me)
            ->get(exportUrl(['format' => 'csv', 'from' => '2024-01-01']))
            ->assertRedirect('/barometer')
            ->assertSessionHasErrors(['to' => 'Export up to a year at a time.']);

        expect(AuditLog::where('action', 'barometer.exported')->count())->toBe(1);
    });

    it('rejects bad input, back to the Barometer page', function (array $query) {
        $this->actingAs($this->me)->get(exportUrl($query))
            ->assertRedirect('/barometer')
            ->assertSessionHasErrors();

        expect(AuditLog::where('action', 'barometer.exported')->count())->toBe(0);
    })->with([
        'garbled date' => [['format' => 'csv', 'from' => '1 Oct']],
        'no format' => [['format' => '']],
        'unknown format' => [['format' => 'xlsx']],
        'backwards range' => [['format' => 'csv', 'from' => '2026-10-16', 'to' => '2026-10-01']],
        'more than a year' => [['format' => 'csv', 'from' => '2025-10-01', 'to' => '2026-10-16']],
        'cannot omit the first rating' => [['format' => 'csv', 'omit' => ['rating_before']]],
    ]);
});
