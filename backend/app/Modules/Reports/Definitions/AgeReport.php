<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;
use Illuminate\Support\Carbon;

/**
 * Working employees by age group. Birth dates are personal data (People PII tier), so the report is for admins only;
 * it shows counts per bucket, never a person.
 */
final class AgeReport extends AbstractBucketReport
{
    private const array BUCKETS = ['<25' => 25, '25-34' => 35, '35-44' => 45, '45-54' => 55, '55+' => PHP_INT_MAX];

    public function key(): string
    {
        return 'age';
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->isAdmin();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $counts = array_fill_keys([...array_keys(self::BUCKETS), 'unknown'], 0);
        foreach ($this->data->employees(null, self::branch($filters)) as $e) {
            if (! self::workingOn($e['hired_at'], $e['fired_at'], $ctx->now)) {
                continue;
            }
            if ($e['birth_date'] === null) {
                $counts['unknown']++;

                continue;
            }
            $bucket = self::bucketOf(Carbon::parse($e['birth_date'])->diffInYears($ctx->now), self::BUCKETS);
            if ($bucket !== null) {
                $counts[$bucket]++;
            }
        }

        return self::bucketRows($counts);
    }
}
