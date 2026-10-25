<?php

namespace Database\Factories;

use App\Models\ScheduleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleItem>
 */
class ScheduleItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(2, true),
            'category' => fake()->randomElement(ScheduleItem::CATEGORIES),
            'start_time' => '08:00',
            'end_time' => '09:30',
            'days' => [0, 1, 2, 3, 4],
            'starts_on' => '2026-10-01',
            'ends_on' => null,
        ];
    }
}
