<?php

declare(strict_types=1);

namespace App\Modules\Directory\Database\Factories;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic values only: the repository is public, real company dictionaries never go into code.
 *
 * @extends Factory<Department>
 */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Department '.fake()->unique()->bothify('??-###'),
            'status' => DirectoryStatus::Active,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DirectoryStatus::Disabled]);
    }
}
