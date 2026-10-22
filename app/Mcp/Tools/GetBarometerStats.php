<?php

namespace App\Mcp\Tools;

use App\Features\Barometer;
use App\Mcp\Text;
use App\Mcp\Tools\Concerns\ActsForGrant;
use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
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

    public const MAX_DAYS = 366;

    /** Local hour ranges, [from, to). Night wraps past midnight. */
    private const TIMES_OF_DAY = [
        'morning (5am–noon)' => [5, 12],
        'afternoon (noon–5pm)' => [12, 17],
        'evening (5–10pm)' => [17, 22],
        'night (10pm–5am)' => [22, 5],
    ];

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

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return Response::error('That range is too long — ask for a year at most.');
        }

        // Numbers and dates only: the encrypted text columns are never selected.
        $readings = $user->barometerReadings()
            ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'occurred_at', 'tzid', 'local_date', 'rating_before', 'rating_after']);

        $range = Text::date($from).' – '.Text::date($to).' ('.$this->plural((int) $from->diffInDays($to) + 1, 'day').')';
        $lines = ["Barometer summary, {$range}. These numbers describe the user's log; they don't diagnose anything."];

        if ($readings->isEmpty()) {
            $lines[] = 'No readings in this range.';

            return Response::text(implode("\n", $lines));
        }

        $untimed = $readings->whereNull('occurred_at')->count();
        $lines[] = 'Readings: '.$readings->count().($untimed > 0 ? " ({$untimed} without a time)" : '').'.';
        $lines[] = 'First rating: average '.$this->average($readings->pluck('rating_before')).', lowest '.$readings->min('rating_before').', highest '.$readings->max('rating_before').'.';
        $lines[] = $this->drop($readings);
        $lines[] = '';
        $lines[] = 'By week (Monday to Sunday):';

        foreach ($readings->groupBy(fn (BarometerReading $reading) => CarbonImmutable::parse($reading->local_date)->startOfWeek()->toDateString())->sortKeys() as $monday => $week) {
            $lines[] = '- w/c '.Text::date($monday).': '.$this->plural($week->count(), 'reading').', average '.$this->average($week->pluck('rating_before')).$this->weekDrop($week);
        }

        $lines[] = '';
        $lines[] = 'By time of day (readings with a time): '.$this->byTimeOfDay($readings->whereNotNull('occurred_at'));
        $lines[] = 'By weekday: '.$this->byWeekday($readings);

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

    /**
     * @param  Collection<int, BarometerReading>  $readings
     */
    private function drop(Collection $readings): string
    {
        $paired = $readings->whereNotNull('rating_after');

        if ($paired->isEmpty()) {
            return 'Second rating: none recorded.';
        }

        return 'Second rating: on '.$paired->count().' of '.$readings->count().'; on those, average '.$this->average($paired->pluck('rating_before'))
            .' then '.$this->average($paired->pluck('rating_after'))
            .' — an average change of '.$this->signed($paired->avg(fn (BarometerReading $reading) => (int) $reading->rating_after - $reading->rating_before)).'.';
    }

    /**
     * @param  Collection<int, BarometerReading>  $week
     */
    private function weekDrop(Collection $week): string
    {
        $paired = $week->whereNotNull('rating_after');

        return $paired->isEmpty() ? '' : ', average change '.$this->signed($paired->avg(fn (BarometerReading $reading) => (int) $reading->rating_after - $reading->rating_before)).' over '.$this->plural($paired->count(), 'second rating');
    }

    /**
     * @param  Collection<int, BarometerReading>  $timed
     */
    private function byTimeOfDay(Collection $timed): string
    {
        if ($timed->isEmpty()) {
            return 'none.';
        }

        return collect(self::TIMES_OF_DAY)->map(function (array $hours, string $label) use ($timed) {
            [$start, $end] = $hours;
            $in = $timed->filter(function (BarometerReading $reading) use ($start, $end) {
                // The hour where it happened (the reading's own timezone).
                $hour = (int) $reading->occurred_at?->setTimezone($reading->tzid)->format('G');

                return $start < $end ? $hour >= $start && $hour < $end : $hour >= $start || $hour < $end;
            });

            return "{$label} ".$in->count().($in->isNotEmpty() ? ' (average '.$this->average($in->pluck('rating_before')).')' : '');
        })->implode(' · ').'.';
    }

    /**
     * @param  Collection<int, BarometerReading>  $readings
     */
    private function byWeekday(Collection $readings): string
    {
        $counts = $readings->countBy(fn (BarometerReading $reading) => CarbonImmutable::parse($reading->local_date)->dayOfWeekIso);

        return collect(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
            ->map(fn (string $day, int $i) => "{$day} ".($counts[$i + 1] ?? 0))
            ->implode(' · ').'.';
    }

    /**
     * @param  Collection<int, int|null>  $values
     */
    private function average(Collection $values): string
    {
        return number_format((float) $values->avg(), 1);
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
