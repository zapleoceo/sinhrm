<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;
use Illuminate\Support\Carbon;

/** Working employees today by tenure: < 1 year, 1–3, 3–5, 5+ years. */
final class TenureReport extends AbstractBucketReport
{
    private const array BUCKETS = ['<1' => 1, '1-3' => 3, '3-5' => 5, '5+' => PHP_INT_MAX];

    public function key(): string
    {
        return 'tenure';
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
            $bucket = self::bucketOf(Carbon::parse($e['hired_at'])->diffInYears($ctx->now), self::BUCKETS);
            if ($bucket !== null) {
                $counts[$bucket]++;
            }
        }

        return self::bucketRows($counts);
    }
}
