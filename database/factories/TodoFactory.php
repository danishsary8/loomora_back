<?php

namespace Database\Factories;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'category' => fake()->randomElement(Todo::CATEGORIES),
            'due_date' => null,
        ];
    }

    public function due(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }

    public function done(): static
    {
        return $this->state(fn () => ['done_at' => now()]);
    }
}
