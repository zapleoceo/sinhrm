<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
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

    public function activeUsers(?string $query, int $limit): array
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->when($query, function ($q, string $term): void {
                $like = '%'.addcslashes(mb_strtolower($term), '%_'.chr(92)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    public function syncInterviewers(Application $application, array $userIds): Application
    {
        $now = now();
        $application->interviewers()->sync(array_fill_keys(array_values(array_unique($userIds)), ['created_at' => $now]));

        return $application->load('interviewers');
    }
}
