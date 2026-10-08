<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Shared frame of the «working employees by bucket» HR reports (age, tenure): branch filter, bucket/employees columns
 * and chart, bucket lookup by upper bound and the row shape. Availability stays per report.
 */
abstract class AbstractBucketReport extends AbstractReport
{
    public function __construct(protected readonly ReportDataRepository $data) {}

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
        return [['key' => 'bucket', 'type' => 'string'], ['key' => 'employees', 'type' => 'number', 'total' => 'sum']];
    }

    public function chart(): array
    {
        return ['label' => 'bucket', 'value' => 'employees'];
    }

    /**
     * The first bucket whose exclusive upper bound is above the value; null when none is.
     *
     * @param  array<string, int>  $buckets  label → exclusive upper bound, ascending
     */
    protected static function bucketOf(int|float $value, array $buckets): ?string
    {
        foreach ($buckets as $bucket => $below) {
            if ($value < $below) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $counts  bucket → employees, in output order
     * @return list<array{bucket: string, employees: int}>
     */
    protected static function bucketRows(array $counts): array
    {
        return array_map(static fn (string $b, int $n): array => ['bucket' => $b, 'employees' => $n], array_keys($counts), array_values($counts));
    }
}
