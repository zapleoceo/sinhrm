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
}
