<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Database\Eloquent\Builder;

/** Candidate ownership never expands application visibility. */
final class ApplicationVisibility
{
    /** @return Builder<Application> */
    public static function query(Scope $scope): Builder
    {
        return Application::query()->when(! $scope->isUnrestricted(), function (Builder $query) use ($scope): void {
            $query->where(function (Builder $visible) use ($scope): void {
                $visible->whereHas('vacancy', fn (Builder $vacancy) => $vacancy
                    ->whereIn('branch_id', $scope->branchIds ?? [])
                    ->orWhereIn('id', $scope->managedVacancyIds))
                    ->orWhereIn('applications.id', $scope->interviewApplicationIds);
            });
        });
    }
}
