<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ReportRepository;
use App\Modules\Recruiting\DTO\DateRange;

/** Manager reports; every figure is limited by the actor's scope. Adds totals so the UI need not sum. */
final readonly class ReportService
{
    public function __construct(private ReportRepository $reports, private RecruitingScope $scope) {}

    /** @return array<string, mixed> */
    public function touches(User $actor, DateRange $range): array
    {
        $rows = $this->reports->touches($this->scope->for($actor), $range);
        $viaProduct = array_sum(array_map(static fn (array $r): int => $r['via_product'] ? $r['count'] : 0, $rows));
        $total = array_sum(array_column($rows, 'count'));

        return [
            'range' => $range->toArray(),
            'rows' => $rows,
            'totals' => ['total' => $total, 'via_product' => $viaProduct, 'captured' => $total - $viaProduct],
        ];
    }

    /** @return array<string, mixed> */
    public function funnel(User $actor, DateRange $range, ?int $vacancyId): array
    {
        $rows = $this->reports->funnel($this->scope->for($actor), $range, $vacancyId);

        return ['range' => $range->toArray(), 'rows' => $rows, 'totals' => ['total' => array_sum(array_column($rows, 'count'))]];
    }

    /** @return array<string, mixed> */
    public function sources(User $actor, DateRange $range): array
    {
        $rows = $this->reports->sources($this->scope->for($actor), $range);

        return [
            'range' => $range->toArray(),
            'rows' => $rows,
            'totals' => ['candidates' => array_sum(array_column($rows, 'candidates')), 'hired' => array_sum(array_column($rows, 'hired'))],
        ];
    }

    /** @return array<string, mixed> */
    public function rejectReasons(User $actor, DateRange $range): array
    {
        $rows = $this->reports->rejectReasons($this->scope->for($actor), $range);

        return ['range' => $range->toArray(), 'rows' => $rows, 'totals' => ['total' => array_sum(array_column($rows, 'count'))]];
    }
}
