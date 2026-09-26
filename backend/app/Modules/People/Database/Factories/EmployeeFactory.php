<?php

declare(strict_types=1);

namespace App\Modules\People\Database\Factories;

use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Enums\EmploymentType;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic people only (reserved example.test domain): the repository is public.
 *
 * @extends Factory<Employee>
 */
final class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = fake()->unique()->bothify('emp-####??');

        return [
            'full_name' => 'Employee '.strtoupper($slug),
            'work_email' => $slug.'@example.test',
            'phone' => '+38050'.fake()->numerify('#######'),
            'hired_at' => '2025-01-15',
            'status' => EmployeeStatus::Active,
            'employment_type' => EmploymentType::FullTime,
        ];
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => ['status' => EmployeeStatus::Terminated, 'fired_at' => '2026-01-31']);
    }
}
