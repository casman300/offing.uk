<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now('UTC')->addDays(fake()->numberBetween(1, 30))->setTime(fake()->numberBetween(8, 20), 0);

        return [
            'calendar_id' => Calendar::factory(),
            'uid' => Str::uuid()->toString().'@offing.test',
            'title' => fake()->sentence(3),
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'tzid' => User::DEFAULT_TIMEZONE,
            'all_day' => false,
            'visibility' => Event::VISIBILITY_NORMAL,
            'transparency' => Event::TRANSPARENCY_BUSY,
        ];
    }

    /** On this user's Personal calendar. */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['calendar_id' => $user->defaultCalendar()->id]);
    }

    /** Local wall-clock times in $tz. */
    public function at(string $start, string $end, string $tz = User::DEFAULT_TIMEZONE): static
    {
        return $this->state(fn () => [
            'starts_at' => CarbonImmutable::parse($start, $tz)->utc(),
            'ends_at' => CarbonImmutable::parse($end, $tz)->utc(),
            'tzid' => $tz,
            'all_day' => false,
        ]);
    }

    /** Inclusive first and last day. */
    public function allDay(string $first, ?string $last = null): static
    {
        return $this->state(fn () => [
            'starts_at' => CarbonImmutable::createFromFormat('!Y-m-d', $first, 'UTC'),
            'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d', $last ?? $first, 'UTC')->addDay(),
            'all_day' => true,
        ]);
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => Event::VISIBILITY_PRIVATE]);
    }

    public function free(): static
    {
        return $this->state(fn () => ['transparency' => Event::TRANSPARENCY_FREE]);
    }
}
