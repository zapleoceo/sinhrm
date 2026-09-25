<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Faker\Factory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Synthetic seed for preview/local DBs (POST /api/ops/migrate?fresh=1 runs migrate:fresh --seed; refused in production).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Faker is a dev dependency: deploys (composer --no-dev) have no factories' fake data.
        if (class_exists(Factory::class)) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // Recruiting demo: vacancies, ~40 candidates, touches on every channel, stale and unmatched items.
        if (! app()->isProduction()) {
            Artisan::call('recruiting:demo');
        }
    }
}
