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

    /**
     * Meetings (touchpoints of channel "meeting" with meta.start) that start within [$from, $to] and involve the
     * user: they scheduled it (author) or they are an interviewer of its application. Candidate visibility is
     * checked by the caller. Sorted by start.
     *
     * @param  list<int>  $interviewApplicationIds
     * @return list<array{id: int, candidate_id: int, candidate_name: string, start: Carbon, end: Carbon|null, title: string|null, meeting_type: string|null}>
     */
    public function meetingsInvolving(int $userId, array $interviewApplicationIds, Carbon $from, Carbon $to): array;

    /**
     * Applications in scope whose route started (first stage change) since $since: the furthest non-closed stage
     * position they ever reached and whether they are still active.
     *
     * @return list<array{pipeline_id: int, max_position: int, active: bool}>
     */
    public function reachedStages(Scope $scope, Carbon $since): array;

    /**
     * Non-closed stages of every pipeline, ordered by pipeline and position.
     *
     * @return list<array{pipeline_id: int, name: string, kind: string, position: int}>
     */
    public function routeStages(): array;

    /**
     * Applications in scope that first entered a "hire"-kind stage (the offer and further) within [$since, $until]:
     * their start (first stage change) and that first entry.
     *
     * @return list<array{started_at: Carbon, offer_at: Carbon}>
     */
    public function offerPaths(Scope $scope, Carbon $since, Carbon $until): array;
}
