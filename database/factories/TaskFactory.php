<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\TaskJobQueue;
use App\TaskType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(),
            'task_type' => fake()->randomElement(TaskType::cases())->name,
            'deployment_id' => Deployment::factory(),
            'job_queue' => TaskJobQueue::Default,
        ];
    }
}
