<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;

/**
 * Working employees by age group. Birth dates are personal data (People PII tier), so the report is for admins only;
 * it shows counts per bucket, never a person.
 */
final class AgeReport extends AbstractReport
{
    private const array BUCKETS = ['<25' => 25, '25-34' => 35, '35-44' => 45, '45-54' => 55, '55+' => PHP_INT_MAX];

    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'age';
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
            $age = Carbon::parse($e['birth_date'])->diffInYears($ctx->now);
            foreach (self::BUCKETS as $bucket => $below) {
                if ($age < $below) {
                    $counts[$bucket]++;
                    break;
                }
            }
        }

        return array_map(static fn (string $b, int $n): array => ['bucket' => $b, 'employees' => $n], array_keys($counts), array_values($counts));
    }
}
