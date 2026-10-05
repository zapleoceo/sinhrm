<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Candidate ownership never expands application visibility. */
final class ApplicationVisibility
{
    /** @return Builder<Application> */
    public static function query(Scope $scope): Builder
    {
        $query = Application::query();
        self::constrain($query->getQuery(), $scope);

        return $query;
    }

    public static function constrain(QueryBuilder $query, Scope $scope, string $applicationTable = 'applications'): void
    {
        if ($scope->isUnrestricted()) {
            return;
        }

        $vacancies = DB::table('vacancies')->select('id')->where(function (QueryBuilder $visible) use ($scope): void {
            $visible->whereIn('branch_id', $scope->branchIds ?? [])
                ->orWhereIn('id', $scope->managedVacancyIds);
        });
        $query->where(function (QueryBuilder $visible) use ($scope, $applicationTable, $vacancies): void {
            $visible->whereIn($applicationTable.'.vacancy_id', $vacancies)
                ->orWhereIn($applicationTable.'.id', $scope->interviewApplicationIds);
        });
    }
}
