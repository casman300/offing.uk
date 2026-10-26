<?php

namespace App\Support\Shipshape;

use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "Want a head start?" suggestions (spec §25). They live in code, not
 * the database, so improving them is a PR. Seeded per list, never per user,
 * so a partner joining duplicates nothing.
 *
 * `needs`: 'date' — a fixed renewal whose date only you know (left blank
 * it's added as "needs a date"); 'weekday' — bin day; null — nothing asked.
 */
final class Starters
{
    /**
     * @return list<array{key: string, label: string, items: list<array{key: string, title: string, area: string|null, mode: string, every: int, unit: string, needs: string|null, lead_days: int|null}>}>
     */
    public static function groups(): array
    {
        $float = fn (string $key, string $title, ?string $area, int $every, string $unit) => compact('key', 'title', 'area', 'every', 'unit') + ['mode' => Chore::FLOATING, 'needs' => null, 'lead_days' => null];
        $renewal = fn (string $key, string $title, string $area, ?int $lead) => ['key' => $key, 'title' => $title, 'area' => $area, 'every' => 1, 'unit' => 'year', 'mode' => Chore::FIXED, 'needs' => 'date', 'lead_days' => $lead];

        return [
            ['key' => 'daily', 'label' => 'Daily', 'items' => [
                $float('wash-up', 'Wash up / load the dishwasher', 'Kitchen', 1, 'day'),
                $float('sides', 'Wipe kitchen sides', 'Kitchen', 1, 'day'),
                $float('bed', 'Make the bed', 'Bedroom', 1, 'day'),
                $float('pets', 'Feed pets', null, 1, 'day'),
            ]],
            ['key' => 'weekly', 'label' => 'Weekly', 'items' => [
                $float('hoover', 'Hoover & mop floors', 'Whole house', 1, 'week'),
                $float('bathroom', 'Clean the bathroom', 'Bathroom', 1, 'week'),
                $float('bedding', 'Change bedding', 'Bedroom', 1, 'week'),
                ['key' => 'bins', 'title' => 'Bins & recycling out', 'area' => 'Outside', 'every' => 1, 'unit' => 'week', 'mode' => Chore::FIXED, 'needs' => 'weekday', 'lead_days' => null],
                $float('dust', 'Dust surfaces', 'Whole house', 1, 'week'),
                $float('laundry', 'Laundry', null, 1, 'week'),
                $float('fridge', 'Clear out the fridge', 'Kitchen', 1, 'week'),
            ]],
            ['key' => 'monthly', 'label' => 'Monthly deep clean', 'items' => [
                $float('oven', 'Clean the oven & hob', 'Kitchen', 1, 'month'),
                $float('microwave', 'Clean inside the microwave', 'Kitchen', 1, 'month'),
                $float('descale', 'Descale kettle & taps', 'Kitchen', 1, 'month'),
                $float('windows', 'Windows & mirrors', 'Whole house', 1, 'month'),
                $float('wash-bins', 'Wash the bins', 'Outside', 1, 'month'),
                ['key' => 'alarms', 'title' => 'Test smoke & CO alarms', 'area' => 'Safety', 'every' => 1, 'unit' => 'month', 'mode' => Chore::FIXED, 'needs' => null, 'lead_days' => null],
                $float('filters', 'Dishwasher & washing-machine filters', 'Kitchen', 1, 'month'),
            ]],
            ['key' => 'yearly', 'label' => 'Yearly & renewals', 'items' => [
                $renewal('car-tax', 'Car tax', 'Car', 14),
                $renewal('mot', 'Car MOT', 'Car', 30),
                $renewal('car-insurance', 'Car insurance renewal', 'Car', 21),
                $renewal('home-insurance', 'Home insurance renewal', 'Admin', 21),
                $renewal('boiler', 'Boiler service', 'Home', 14),
                $renewal('tv-licence', 'TV licence', 'Admin', 14),
                $float('gutters', 'Gutters & drains', 'Outside', 1, 'year'),
                $float('mattress', 'Rotate the mattress', 'Bedroom', 6, 'month'),
            ]],
        ];
    }

    /**
     * @return array<string, array{key: string, title: string, area: string|null, mode: string, every: int, unit: string, needs: string|null, lead_days: int|null}>
     */
    public static function byKey(): array
    {
        return collect(self::groups())->flatMap(fn ($group) => $group['items'])->keyBy('key')->all();
    }

    /**
     * Add the picked suggestions to a list.
     *
     * Floating ones are staggered across their first interval so a new list
     * doesn't put twenty chores on today — only dailies and one weekly start
     * today; monthly and rarer ones never do. Fixed: bin day lands on the next
     * chosen weekday; alarms on the 1st of next month; renewals on the date
     * given, or "needs a date" if it was left blank — never an invented one.
     *
     * @param  list<string>  $keys
     * @param  array<string, string|null>  $dates  key => Y-m-d
     * @param  int|null  $weekday  ISO weekday 1–7 for bin day
     * @return int how many were added
     */
    public static function add(ChoreList $list, User $by, array $keys, array $dates, ?int $weekday, CarbonImmutable $today): int
    {
        $catalogue = self::byKey();
        $picked = array_values(array_filter(array_unique($keys), fn ($key) => isset($catalogue[$key])));
        $existing = $list->chores()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
        $today = ChoreSchedule::date($today);

        $byInterval = collect($picked)->map(fn ($key) => $catalogue[$key])
            ->reject(fn ($item) => in_array(mb_strtolower($item['title']), $existing, true))
            ->groupBy(fn ($item) => $item['mode'].$item['every'].$item['unit']);

        return DB::transaction(function () use ($byInterval, $list, $by, $dates, $weekday, $today) {
            $added = 0;

            foreach ($byInterval as $items) {
                foreach ($items->values() as $i => $item) {
                    [$anchor, $due] = self::firstDates($item, $i, $items->count(), $dates[$item['key']] ?? null, $weekday, $today);

                    $list->chores()->create([
                        'title' => $item['title'],
                        'area' => $item['area'],
                        'mode' => $item['mode'],
                        'every' => $item['every'],
                        'unit' => $item['unit'],
                        'rrule' => $item['mode'] === Chore::FIXED ? ChoreSchedule::rrule($item['every'], $item['unit']) : null,
                        'anchor_on' => $anchor?->toDateString(),
                        'due_on' => $due?->toDateString(),
                        'lead_days' => $item['lead_days'],
                        'created_by_user_id' => $by->id,
                    ]);
                    $added++;
                }
            }

            return $added;
        });
    }

    /**
     * @param  array{key: string, mode: string, every: int, unit: string, needs: string|null}  $item
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} anchor, due
     */
    private static function firstDates(array $item, int $index, int $of, ?string $date, ?int $weekday, CarbonImmutable $today): array
    {
        if ($item['mode'] === Chore::FLOATING) {
            $span = (int) ChoreSchedule::addInterval($today, $item['every'], $item['unit'])->diffInDays($today, true);

            // Daily and weekly ones start today; rarer ones are spread
            // through their first interval so day one isn't the deep clean.
            $offset = $span > 7
                ? intdiv(($index + 1) * $span, $of + 1)
                : intdiv($index * $span, max(1, $of));

            return [null, $today->addDays($offset)];
        }

        if ($item['needs'] === 'weekday') {
            $day = $weekday ?? $today->isoWeekday();
            $first = $today->addDays(($day - $today->isoWeekday() + 7) % 7);

            return [$first, $first];
        }

        if ($item['needs'] === 'date') {
            $first = $date ? ChoreSchedule::date(CarbonImmutable::parse($date)) : null;

            return [$first, $first];
        }

        $first = $today->addMonthNoOverflow()->startOfMonth();

        return [$first, $first];
    }
}
