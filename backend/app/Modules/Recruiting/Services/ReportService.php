<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ReportRepository;
use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Recruiting\Support\ChannelMath;
use App\Modules\Recruiting\Support\RejectionBreakdown;

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

    /**
     * Rejections of the range by reason (rows) plus two slices from the stage history (tz4): by_stage — the stage the
     * candidate was rejected from, by_recruiter — the author of the rejecting step; only users with a recruiting role
     * are named. Every slice sums to totals.total.
     *
     * @return array<string, mixed>
     */
    public function rejectReasons(User $actor, DateRange $range): array
    {
        $scope = $this->scope->for($actor);
        $rows = $this->reports->rejectReasons($scope, $range);
        $breakdown = $this->reports->rejectionBreakdown($scope, $range);
        $userIds = array_values(array_unique(array_filter(array_column($breakdown, 'user_id'), static fn (?int $id): bool => $id !== null)));

        return [
            'range' => $range->toArray(),
            'rows' => $rows,
            'by_stage' => RejectionBreakdown::byStage($breakdown),
            'by_recruiter' => RejectionBreakdown::byRecruiter($breakdown, $this->reports->recruitingUserIds($userIds)),
            'totals' => ['total' => array_sum(array_column($rows, 'count'))],
        ];
    }

    /**
     * Channel effectiveness (tz3): candidates of the period by channel, applications, advanced (reached select/hire),
     * hires, conversion; cost and cost per hire only for dictionary managers (costs are company-wide numbers, the
     * candidate counts are scoped). Math: Support/ChannelMath.
     *
     * @return list<array{channel: string|null, code: string|null, type: string|null, candidates: int, applications: int, advanced: int, hired: int, conversion_pct: float, cost: float|null, cost_per_hire: float|null}>
     */
    public function channels(User $actor, DateRange $range): array
    {
        $showCost = $this->scope->canManage($actor);
        $costs = $showCost ? ChannelMath::prorate($this->reports->channelCosts($range), $range->from, $range->to) : [];

        return array_map(static function (array $r) use ($showCost, $costs): array {
            $cost = $showCost && $r['channel_id'] !== null ? ($costs[$r['channel_id']] ?? null) : null;

            return [
                'channel' => $r['name'],
                'code' => $r['code'],
                'type' => $r['type'],
                'candidates' => $r['candidates'],
                'applications' => $r['applications'],
                'advanced' => $r['advanced'],
                'hired' => $r['hired'],
                'conversion_pct' => ChannelMath::conversion($r['hired'], $r['candidates']),
                'cost' => $cost,
                'cost_per_hire' => ChannelMath::costPerHire($cost, $r['hired']),
            ];
        }, $this->reports->channels($this->scope->for($actor), $range));
    }

    /**
     * Vacancy card block "Where applicants came from" (tz3): applications by channel and how added, with the share.
     *
     * @return list<array{channel_id: int|null, name: string|null, added_via: string|null, count: int, share_pct: float}>
     */
    public function vacancySources(int $vacancyId): array
    {
        $rows = $this->reports->vacancySources($vacancyId);
        $total = array_sum(array_column($rows, 'count'));

        return array_map(static fn (array $r): array => $r + ['share_pct' => ChannelMath::conversion($r['count'], $total)], $rows);
    }
}
