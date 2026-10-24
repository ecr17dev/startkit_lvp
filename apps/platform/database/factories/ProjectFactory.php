<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'code' => fake()->unique()->lexify(str_repeat('?', 10)),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(['planned', 'active', 'on_hold', 'closed']),
            'starts_on' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'due_on' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'budget' => fake()->randomFloat(2, 0, 1000),
            'is_billable' => fake()->boolean(),
        ];
    }
}
