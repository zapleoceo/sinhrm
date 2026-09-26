<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\HiringTeamRepository;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Support\Facades\DB;

final class EloquentHiringTeamRepository implements HiringTeamRepository
{
    public function managedVacancyIds(int $userId): array
    {
        return Vacancy::query()->where('hiring_manager_id', $userId)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    public function interviewApplicationIds(int $userId): array
    {
        return DB::table('application_interviewers')->where('user_id', $userId)->orderBy('application_id')->pluck('application_id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    public function syncInterviewers(Application $application, array $userIds): Application
    {
        $now = now();
        $application->interviewers()->sync(array_fill_keys(array_values(array_unique($userIds)), ['created_at' => $now]));

        return $application->load('interviewers');
    }
}
