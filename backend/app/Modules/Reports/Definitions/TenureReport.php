<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;

/** Working employees today by tenure: < 1 year, 1–3, 3–5, 5+ years. */
final class TenureReport extends AbstractReport
{
    private const array BUCKETS = ['<1' => 1, '1-3' => 3, '3-5' => 5, '5+' => PHP_INT_MAX];

    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'tenure';
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
        return [['key' => 'bucket', 'type' => 'string'], ['key' => 'employees', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'bucket', 'value' => 'employees'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $counts = array_fill_keys(array_keys(self::BUCKETS), 0);
        foreach ($this->data->employees($ctx->employeeIds(), self::branch($filters)) as $e) {
            if (! self::workingOn($e['hired_at'], $e['fired_at'], $ctx->now)) {
                continue;
            }
            $years = Carbon::parse($e['hired_at'])->diffInYears($ctx->now);
            foreach (self::BUCKETS as $bucket => $below) {
                if ($years < $below) {
                    $counts[$bucket]++;
                    break;
                }
            }
        }

        return array_map(static fn (string $b, int $n): array => ['bucket' => $b, 'employees' => $n], array_keys($counts), array_values($counts));
    }
}
