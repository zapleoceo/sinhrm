<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Database\Factories;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Enums\VacancyStatus;
use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic values only (public repository). Uses the default pipeline seeded by the migration.
 *
 * @extends Factory<Vacancy>
 */
final class VacancyFactory extends Factory
{
    protected $model = Vacancy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => 'Vacancy '.fake()->unique()->bothify('??-###'),
            'branch_id' => Branch::factory(),
            'recruiter_id' => User::factory(),
            'pipeline_id' => static fn (): int => (int) Pipeline::query()->where('is_default', true)->value('id'),
            'status' => VacancyStatus::Open,
            'description' => null,
            'opened_at' => now(),
        ];
    }
}
