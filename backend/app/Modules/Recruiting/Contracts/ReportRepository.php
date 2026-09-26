<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Recruiting\DTO\Scope;

/** Aggregates for managers. Every query is limited by the scope (branches) and the date range. */
interface ReportRepository
{
    /**
     * Touches (not system) by author × channel × via_product, by occurred_at.
     *
     * @return list<array{author_id: int|null, author_name: string|null, channel: string, via_product: bool, count: int}>
     */
    public function touches(Scope $scope, DateRange $range): array;

    /**
     * Applications created in the range, by vacancy and their current stage.
     *
     * @return list<array{vacancy_id: int, vacancy_title: string, stage_id: int, stage_name: string, stage_kind: string, position: int, count: int}>
     */
    public function funnel(Scope $scope, DateRange $range, ?int $vacancyId): array;

    /**
     * Candidates created in the range by source, with how many of them got hired.
     *
     * @return list<array{source: string, candidates: int, hired: int}>
     */
    public function sources(Scope $scope, DateRange $range): array;

    /**
     * Applications rejected in the range (closed_at) by reason.
     *
     * @return list<array{reject_reason_id: int|null, name: string|null, count: int}>
     */
    public function rejectReasons(Scope $scope, DateRange $range): array;

    /**
     * Candidates created in the range by acquisition channel (null = no channel), with their applications, how many
     * applications reached a "select"/"hire" stage, and how many candidates got hired (tz3).
     *
     * @return list<array{channel_id: int|null, code: string|null, name: string|null, type: string|null, candidates: int, applications: int, advanced: int, hired: int}>
     */
    public function channels(Scope $scope, DateRange $range): array;

    /**
     * Channel cost rows whose period overlaps the range (prorated by the caller).
     *
     * @return list<array{channel_id: int, period_start: string, period_end: string, amount: float}>
     */
    public function channelCosts(DateRange $range): array;

    /**
     * Applications of one vacancy by the candidate's channel and "how added" (tz3 vacancy card block).
     *
     * @return list<array{channel_id: int|null, name: string|null, added_via: string|null, count: int}>
     */
    public function vacancySources(int $vacancyId): array;
}
