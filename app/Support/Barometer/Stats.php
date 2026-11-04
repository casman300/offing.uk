<?php

namespace App\Support\Barometer;

use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Barometer's look-back numbers (#37, spec §26): counts and averages over a
 * range of local dates. One source of truth for both the in-app stats page
 * (and the PDF's summary page) and the AI assistant's `get_barometer_stats`
 * (spec §12: "#37 builds the in-app stats on the same numbers").
 *
 * Numbers only: the query selects dates and ratings, never the encrypted
 * text columns. Days come from `local_date`, so there's no timezone maths
 * for weekly or weekday numbers; time of day uses the reading's own
 * timezone and skips readings whose time wasn't recorded.
 *
 * Describes, never diagnoses: nothing here labels a number as good or bad.
 */
final class Stats
{
    /** A year at most, the same limit as exports. */
    public const MAX_DAYS = 366;

    /** Everything the query reads. No text column, ever. */
    public const SELECTED_COLUMNS = ['id', 'occurred_at', 'tzid', 'local_date', 'rating_before', 'rating_after'];

    /**
     * Local hour ranges, [from, to). Night wraps past midnight and belongs
     * to the day it started on the clock (2am Tuesday is Tuesday's night).
     *
     * @var array<string, array{label: string, hours: string, from: int, to: int}>
     */
    public const TIMES_OF_DAY = [
        'morning' => ['label' => 'Morning', 'hours' => '5am–noon', 'from' => 5, 'to' => 12],
        'afternoon' => ['label' => 'Afternoon', 'hours' => 'noon–5pm', 'from' => 12, 'to' => 17],
        'evening' => ['label' => 'Evening', 'hours' => '5–10pm', 'from' => 17, 'to' => 22],
        'night' => ['label' => 'Night', 'hours' => '10pm–5am', 'from' => 22, 'to' => 5],
    ];

    /** ISO weekday (1 = Monday) => short name. */
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @param  Collection<int, BarometerReading>  $readings
     */
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        private readonly Collection $readings,
    ) {}

    /**
     * Whether from–to is a range we'll summarise: forwards and a year at most.
     */
    public static function validRange(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return ! $from->isAfter($to) && $from->diffInDays($to) < self::MAX_DAYS;
    }

    /**
     * The user's own readings with a local date from `$from` to `$to`
     * (inclusive). Callers check the range first, with their own wording.
     */
    public static function for(User $user, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if (! self::validRange($from, $to)) {
            throw new InvalidArgumentException('Barometer stats cover a forwards range of a year at most.');
        }

        $readings = $user->barometerReadings()
            ->whereBetween('local_date', [$from->toDateString(), $to->toDateString()])
            ->get(self::SELECTED_COLUMNS);

        return new self($from, $to, $readings);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function count(): int
    {
        return $this->readings->count();
    }

    /** Readings whose time wasn't recorded: they're left out of time of day. */
    public function untimed(): int
    {
        return $this->readings->whereNull('occurred_at')->count();
    }

    /**
     * @return array{average: float, lowest: int, highest: int}|null null with no readings
     */
    public function firstRating(): ?array
    {
        if ($this->readings->isEmpty()) {
            return null;
        }

        return [
            'average' => (float) $this->readings->avg('rating_before'),
            'lowest' => (int) $this->readings->min('rating_before'),
            'highest' => (int) $this->readings->max('rating_before'),
        ];
    }

    /**
     * First rating to second, over the readings that have both. `change` is
     * second minus first, so a drop is negative.
     *
     * @return array{paired: int, before: float, after: float, change: float}|null null with no second ratings
     */
    public function secondRating(): ?array
    {
        return self::pairedChange($this->readings);
    }

    /**
     * Every week (Monday to Sunday) that overlaps the range, oldest first,
     * including weeks with no readings.
     *
     * @return list<array{start: string, count: int, average: float|null, paired: int, change: float|null}>
     */
    public function weeks(): array
    {
        $byWeek = $this->readings->groupBy(fn (BarometerReading $reading) => CarbonImmutable::parse($reading->local_date)->startOfWeek()->toDateString());

        $weeks = [];

        for ($monday = $this->from->startOfWeek(); ! $monday->isAfter($this->to); $monday = $monday->addWeek()) {
            /** @var Collection<int, BarometerReading> $week */
            $week = $byWeek->get($monday->toDateString(), collect());
            $paired = self::pairedChange($week);

            $weeks[] = [
                'start' => $monday->toDateString(),
                'count' => $week->count(),
                'average' => $week->isEmpty() ? null : (float) $week->avg('rating_before'),
                'paired' => $paired['paired'] ?? 0,
                'change' => $paired['change'] ?? null,
            ];
        }

        return $weeks;
    }

    /**
     * Readings with a time, by time of day.
     *
     * @return array<string, array{count: int, average: float|null}>
     */
    public function timesOfDay(): array
    {
        $timed = $this->readings->whereNotNull('occurred_at');

        return collect(self::TIMES_OF_DAY)
            ->map(fn (array $time, string $key) => self::cell($timed->filter(fn (BarometerReading $reading) => self::timeOfDay($reading) === $key)))
            ->all();
    }

    /**
     * Every reading by weekday of its local date, timed or not.
     *
     * @return array<int, int> ISO weekday => count
     */
    public function weekdays(): array
    {
        $counts = $this->readings->countBy(fn (BarometerReading $reading) => CarbonImmutable::parse($reading->local_date)->dayOfWeekIso);

        return collect(self::WEEKDAYS)->map(fn (string $name, int $day) => (int) ($counts[$day] ?? 0))->all();
    }

    /**
     * Weekday × time of day for readings with a time; `total` counts every
     * reading on that weekday, timed or not.
     *
     * @return array<int, array{total: int, times: array<string, array{count: int, average: float|null}>}> ISO weekday => row
     */
    public function heatmap(): array
    {
        $weekdays = $this->weekdays();
        $timed = $this->readings->whereNotNull('occurred_at');

        return collect(self::WEEKDAYS)->map(fn (string $name, int $day) => [
            'total' => $weekdays[$day],
            'times' => collect(self::TIMES_OF_DAY)->map(fn (array $time, string $key) => self::cell($timed->filter(
                fn (BarometerReading $reading) => CarbonImmutable::parse($reading->local_date)->dayOfWeekIso === $day && self::timeOfDay($reading) === $key,
            )))->all(),
        ])->all();
    }

    /**
     * The stats page's props (and the PDF summary's), averages to one decimal place.
     *
     * @return array<string, mixed>
     */
    public function toPageArray(): array
    {
        $first = $this->firstRating();
        $second = $this->secondRating();

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'count' => $this->count(),
            'untimed' => $this->untimed(),
            'first' => $first === null ? null : [...$first, 'average' => self::round($first['average'])],
            'second' => $second === null ? null : [
                'paired' => $second['paired'],
                'before' => self::round($second['before']),
                'after' => self::round($second['after']),
                'change' => self::round($second['change']),
            ],
            'weeks' => array_map(fn (array $week) => [
                ...$week,
                'average' => self::round($week['average']),
                'change' => self::round($week['change']),
            ], $this->weeks()),
            'timesOfDay' => collect(self::TIMES_OF_DAY)
                ->map(fn (array $time, string $key) => ['key' => $key, 'label' => $time['label'], 'hours' => $time['hours']])
                ->values()
                ->all(),
            'heatmap' => collect($this->heatmap())->map(fn (array $row, int $day) => [
                'weekday' => $day,
                'label' => self::WEEKDAYS[$day],
                'total' => $row['total'],
                'cells' => collect($row['times'])->map(fn (array $cell, string $key) => [
                    'time' => $key,
                    'count' => $cell['count'],
                    'average' => self::round($cell['average']),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, BarometerReading>  $readings
     * @return array{paired: int, before: float, after: float, change: float}|null
     */
    private static function pairedChange(Collection $readings): ?array
    {
        $paired = $readings->whereNotNull('rating_after');

        if ($paired->isEmpty()) {
            return null;
        }

        return [
            'paired' => $paired->count(),
            'before' => (float) $paired->avg('rating_before'),
            'after' => (float) $paired->avg('rating_after'),
            'change' => (float) $paired->avg(fn (BarometerReading $reading) => (int) $reading->rating_after - $reading->rating_before),
        ];
    }

    /**
     * @param  Collection<int, BarometerReading>  $readings
     * @return array{count: int, average: float|null}
     */
    private static function cell(Collection $readings): array
    {
        return ['count' => $readings->count(), 'average' => $readings->isEmpty() ? null : (float) $readings->avg('rating_before')];
    }

    /** The time-of-day key where it happened (the reading's own timezone); null without a time. */
    private static function timeOfDay(BarometerReading $reading): ?string
    {
        if ($reading->occurred_at === null) {
            return null;
        }

        $hour = (int) $reading->occurred_at->setTimezone($reading->tzid)->format('G');

        foreach (self::TIMES_OF_DAY as $key => ['from' => $from, 'to' => $to]) {
            if ($from < $to ? $hour >= $from && $hour < $to : $hour >= $from || $hour < $to) {
                return $key;
            }
        }

        return null;
    }

    private static function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }
}
