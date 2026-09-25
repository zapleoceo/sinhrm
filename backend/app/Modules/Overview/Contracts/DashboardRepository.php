<?php

declare(strict_types=1);

namespace App\Modules\Overview\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use Illuminate\Support\Carbon;

/** Aggregates for the home page. Every figure is limited by the Recruiting scope (branches). */
interface DashboardRepository
{
    /**
     * active — active applications; stale — of them without a real touch since $staleBefore;
     * unmatched_inbox — messages without a candidate; new_today — applications created since $todayStart.
     *
     * @return array{active: int, stale: int, unmatched_inbox: int, new_today: int}
     */
    public function counts(Scope $scope, Carbon $staleBefore, Carbon $todayStart): array;

    /**
     * Active applications by their current stage, across all vacancies in scope (stages of different pipelines with
     * the same name and position are summed).
     *
     * @return list<array{stage_name: string, stage_kind: string, position: int, count: int}>
     */
    public function funnel(Scope $scope): array;

    /**
     * Real touches (not system) since $since by channel.
     *
     * @return list<array{channel: string, count: int}>
     */
    public function touchesByChannel(Scope $scope, Carbon $since): array;
}
