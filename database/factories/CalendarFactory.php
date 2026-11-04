<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calendar>
 */
class CalendarFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => (new User)->getMorphClass(),
            'owner_id' => User::factory(),
            'name' => 'Personal',
            'slug' => Calendar::DEFAULT_SLUG,
            'colour' => null,
        ];
    }
}
