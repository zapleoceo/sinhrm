<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Database\Factories;

use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic people only (faker names, example.* e-mails, random numbers): the repository is public.
 *
 * @extends Factory<Candidate>
 */
final class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => '+38050'.fake()->unique()->numerify('#######'),
            'email' => mb_strtolower(fake()->unique()->safeEmail()),
            'telegram_username' => null,
            'source' => CandidateSource::Manual,
            'utm' => null,
            'tags' => null,
        ];
    }
}
