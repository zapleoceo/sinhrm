<?php

declare(strict_types=1);

namespace App\Modules\Directory\Database\Factories;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic values only: the repository is public, real company dictionaries never go into code.
 *
 * @extends Factory<Position>
 */
final class PositionFactory extends Factory
{
    protected $model = Position::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'external_id' => null,
            'name' => 'Position '.fake()->unique()->bothify('??-###'),
            'status' => DirectoryStatus::Active,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DirectoryStatus::Disabled]);
    }
}
