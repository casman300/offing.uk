<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-80 years', '-1 year');

        return [
            'owner_user_id' => User::factory(),
            'name' => fake()->name(),
            'birth_day' => (int) $date->format('j'),
            'birth_month' => (int) $date->format('n'),
            'birth_year' => (int) $date->format('Y'),
            'notes' => null,
        ];
    }

    public function withoutYear(): static
    {
        return $this->state(fn () => ['birth_year' => null]);
    }

    public function withoutBirthday(): static
    {
        return $this->state(fn () => ['birth_day' => null, 'birth_month' => null, 'birth_year' => null]);
    }

    public function birthday(int $day, int $month, ?int $year = null): static
    {
        return $this->state(fn () => ['birth_day' => $day, 'birth_month' => $month, 'birth_year' => $year]);
    }
}
