<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;

/**
 * Shared frame of the approved-leave reports: from/to period over ReportDataRepository::approvedLeave and the final
 * pass that turns the collected employee sets into a head count and rounds the day sums.
 */
abstract class AbstractLeaveReport extends AbstractTeamReport
{
    public function __construct(protected readonly ReportDataRepository $data) {}

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO];
    }

    /**
     * Sets `$column` of every row to the number of distinct employees collected for its key and rounds `days` to 2.
     *
     * @template T of array{days: float|int}
     *
     * @param  array<array-key, T>  $rows
     * @param  array<array-key, array<int, true>>  $people  row key → employee id set
     * @return array<array-key, T>
     */
    protected static function countPeople(array $rows, array $people, string $column): array
    {
        foreach ($rows as $key => $row) {
            $row[$column] = count($people[$key] ?? []);
            $row['days'] = round($row['days'], 2);
            $rows[$key] = $row;
        }

        return $rows;
    }
}
