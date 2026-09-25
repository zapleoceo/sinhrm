<?php

declare(strict_types=1);

namespace App\Modules\Directory\Database\Factories;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic values only: the repository is public, real company dictionaries never go into code.
 *
 * @extends Factory<City>
 */
final class CityFactory extends Factory
{
    protected $model = City::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'external_id' => null,
            'name' => 'City '.fake()->unique()->bothify('??-###'),
            'status' => DirectoryStatus::Active,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => DirectoryStatus::Disabled]);
    }
}
