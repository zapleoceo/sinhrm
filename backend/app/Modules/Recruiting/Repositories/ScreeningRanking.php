<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\DTO\CandidateFilter;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Support\ApplicationVisibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only scores: a newer pending/failed attempt invalidates the previous result. */
final class ScreeningRanking
{
    private static function completedLatest(): Builder
    {
        return DB::table('candidate_screenings as screening')
            ->where('screening.status', CandidateScreening::DONE)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('candidate_screenings as newer')
                ->whereColumn('newer.application_id', 'screening.application_id')
                ->whereColumn('newer.id', '>', 'screening.id'));
    }

    public static function applicationScore(): Builder
    {
        return self::completedLatest()->select('screening.score')
            ->whereColumn('screening.application_id', 'applications.id')->limit(1);
    }

    public static function candidateScore(CandidateFilter $filter, Scope $scope): Builder
    {
        $query = self::completedLatest()
            ->join('applications as ranked_application', 'ranked_application.id', '=', 'screening.application_id');

        ApplicationVisibility::constrain($query, $scope, 'ranked_application');

        return $query->selectRaw('max(screening.score)')
            ->whereColumn('ranked_application.candidate_id', 'candidates.id')
            ->where('ranked_application.status', $filter->status->value ?? 'active')
            ->when($filter->vacancyId, fn (Builder $q, int $id) => $q->where('ranked_application.vacancy_id', $id))
            ->when($filter->stageId, fn (Builder $q, int $id) => $q->where('ranked_application.stage_id', $id));
    }
}
