<?php

namespace App\Mcp\Tools;

use App\Features\Barometer;
use App\Mcp\Text;
use App\Mcp\Tools\Concerns\ActsForGrant;
use App\Models\User;
use App\Support\Barometer\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

/**
 * Barometer, stats out (spec §12, §26): numbers only — counts and averages
 * over a date range. Never a reading's details or text: the query doesn't
 * even select the text columns. Describes, never diagnoses.
 *
 * Only listed when the user has Barometer and the connection's Barometer
 * switch is on (any access level: it's read-only).
 */
#[Name('get_barometer_stats')]
#[Title('Get Barometer summary')]
#[Description(<<<'TEXT'
    Summary numbers from the user's private anxiety log (Barometer) over a date range — the last 30 days by default, up to a year: how many readings, average first rating (0 calm – 10 most anxious), average drop to the second rating, week by week, and by time of day and weekday. Never individual readings or anything the user wrote.
    Describe the numbers plainly; don't interpret severity or diagnose. If the user mentions risk to themselves, signpost Samaritans (116 123, free, UK, any time) and their GP or therapist.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetBarometerStats extends Tool
{
    use ActsForGrant;

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = Stats::MAX_DAYS;

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && Feature::for($user)->active(Barometer::class) && $this->barometerGranted();
    }

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = $user->today();

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $today;
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from->isAfter($to)) {
            return Response::error('`to` must be on or after `from`.');
        }

        if (! Stats::validRange($from, $to)) {
            return Response::error('That range is too long — ask for a year at most.');
        }

        // Numbers and dates only: the encrypted text columns are never selected.
        $stats = Stats::for($user, $from, $to);

        $range = Text::date($from).' – '.Text::date($to).' ('.$this->plural($stats->days(), 'day').')';
        $lines = ["Barometer summary, {$range}. These numbers describe the user's log; they don't diagnose anything."];

        $first = $stats->firstRating();

        if ($first === null) {
            $lines[] = 'No readings in this range.';

            return Response::text(implode("\n", $lines));
        }

        $untimed = $stats->untimed();
        $lines[] = 'Readings: '.$stats->count().($untimed > 0 ? " ({$untimed} without a time)" : '').'.';
        $lines[] = 'First rating: average '.$this->average($first['average']).', lowest '.$first['lowest'].', highest '.$first['highest'].'.';
        $lines[] = $this->drop($stats);
        $lines[] = '';
        $lines[] = 'By week (Monday to Sunday):';

        foreach ($stats->weeks() as $week) {
            if ($week['count'] === 0) {
                continue;
            }

            $lines[] = '- w/c '.Text::date($week['start']).': '.$this->plural($week['count'], 'reading').', average '.$this->average($week['average'])
                .($week['change'] === null ? '' : ', average change '.$this->signed($week['change']).' over '.$this->plural($week['paired'], 'second rating'));
        }

        $lines[] = '';
        $lines[] = 'By time of day (readings with a time): '.$this->byTimeOfDay($stats);
        $lines[] = 'By weekday: '.collect($stats->weekdays())->map(fn (int $count, int $day) => Stats::WEEKDAYS[$day].' '.$count)->implode(' · ').'.';

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('YYYY-MM-DD. Defaults to 30 days before `to`.'),
            'to' => $schema->string()->description('YYYY-MM-DD. Defaults to today.'),
        ];
    }

    private function drop(Stats $stats): string
    {
        $second = $stats->secondRating();

        if ($second === null) {
            return 'Second rating: none recorded.';
        }

        return 'Second rating: on '.$second['paired'].' of '.$stats->count().'; on those, average '.$this->average($second['before'])
            .' then '.$this->average($second['after'])
            .' — an average change of '.$this->signed($second['change']).'.';
    }

    private function byTimeOfDay(Stats $stats): string
    {
        if ($stats->count() === $stats->untimed()) {
            return 'none.';
        }

        return collect($stats->timesOfDay())->map(function (array $cell, string $key) {
            $time = Stats::TIMES_OF_DAY[$key];

            return strtolower($time['label'])." ({$time['hours']}) ".$cell['count'].($cell['average'] !== null ? ' (average '.$this->average($cell['average']).')' : '');
        })->implode(' · ').'.';
    }

    private function average(?float $value): string
    {
        return number_format((float) $value, 1);
    }

    private function signed(float|int|null $value): string
    {
        $value = round((float) $value, 1);

        return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value), 1);
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? "1 {$word}" : "{$count} {$word}s";
    }
}
