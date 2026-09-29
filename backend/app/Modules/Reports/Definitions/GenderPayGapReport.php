<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Gender pay gap: median current pay per gender, compared only within the same currency and pay period (no FX).
 * Salary and gender are HR-only, so the report is for admins; a group of fewer than MIN_GROUP people is hidden
 * (and so is the gap that would need it), so no individual pay can be inferred.
 */
final class GenderPayGapReport extends AbstractReport
{
    public const int MIN_GROUP = 5;

    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'gender_pay_gap';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function filters(): array
    {
        return [self::FILTER_BRANCH];
    }

    public function columns(): array
    {
        return [
            ['key' => 'currency', 'type' => 'string'],
            ['key' => 'period', 'type' => 'string'],
            ['key' => 'gender', 'type' => 'string'],
            ['key' => 'employees', 'type' => 'number', 'total' => 'none'],
            ['key' => 'median', 'type' => 'number', 'total' => 'none'],
            ['key' => 'gap_pct', 'type' => 'number', 'total' => 'none'],
        ];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->isAdmin();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var array<string, array<string, list<float>>> $groups */
        $groups = [];
        foreach ($this->data->currentPayByGender(self::branch($filters), $ctx->now) as $p) {
            $groups[$p['currency'].'|'.$p['period']][$p['gender']][] = $p['amount'];
        }
        ksort($groups);
        $rows = [];
        foreach ($groups as $key => $byGender) {
            [$currency, $period] = explode('|', $key);
            $medians = [];
            foreach ($byGender as $gender => $amounts) {
                if (count($amounts) >= self::MIN_GROUP) {
                    $medians[$gender] = self::median($amounts);
                }
            }
            ksort($medians);
            $male = $medians['male'] ?? null;
            foreach ($medians as $gender => $median) {
                $rows[] = [
                    'currency' => $currency,
                    'period' => $period,
                    'gender' => $gender,
                    'employees' => count($byGender[$gender]),
                    'median' => round($median, 2),
                    // Gap vs the male median (the usual definition); only when both groups are big enough.
                    'gap_pct' => $male !== null && $gender !== 'male' && isset($medians['female']) ? round(($male - $median) / $male * 100, 1) : null,
                ];
            }
        }

        return $rows;
    }

    /** @param  list<float>  $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
