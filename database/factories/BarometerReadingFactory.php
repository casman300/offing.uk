<?php

namespace Database\Factories;

use App\Models\BarometerReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BarometerReading>
 */
class BarometerReadingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'occurred_at' => now()->subHours(fake()->numberBetween(1, 240)),
            'tzid' => User::DEFAULT_TIMEZONE,
            'situation' => fake()->sentence(),
            'thoughts' => fake()->sentence(),
            'sensations' => fake()->sentence(),
            'rating_before' => fake()->numberBetween(4, 10),
            'coping_action' => fake()->sentence(),
            'self_talk' => fake()->sentence(),
            'rating_after' => fake()->numberBetween(0, 6),
        ];
    }

    /** Logged in a hurry: time and first rating only. */
    public function quick(): static
    {
        return $this->state(fn () => [
            'situation' => null,
            'thoughts' => null,
            'sensations' => null,
            'coping_action' => null,
            'self_talk' => null,
            'rating_after' => null,
        ]);
    }

    /** Remembered later: the day is known, the time isn't. */
    public function untimed(string $localDate, string $tzid = User::DEFAULT_TIMEZONE): static
    {
        return $this->state(fn () => [
            'occurred_at' => null,
            'local_date' => $localDate,
            'tzid' => $tzid,
        ]);
    }

    public function at(string $localTime, string $tzid = User::DEFAULT_TIMEZONE): static
    {
        return $this->state(fn () => [
            'occurred_at' => CarbonImmutable::parse($localTime, $tzid)->utc(),
            'tzid' => $tzid,
        ]);
    }
}
