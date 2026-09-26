<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Hires (hired_at) and terminations (fired_at) per month of the range. */
final class HiresTerminationsReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'hires_terminations';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO, self::FILTER_BRANCH];
    }

    public function columns(): array
    {
        return [['key' => 'month', 'type' => 'string'], ['key' => 'hires', 'type' => 'number'], ['key' => 'terminations', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'month', 'value' => 'hires'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters);
        $rows = [];
        foreach (self::months($range) as $m) {
            $rows[$m] = ['month' => $m, 'hires' => 0, 'terminations' => 0];
        }
        $from = $range->from->toDateString();
        $to = $range->to->toDateString();
        foreach ($this->data->employees($ctx->employeeIds(), self::branch($filters)) as $e) {
            if ($e['hired_at'] >= $from && $e['hired_at'] <= $to) {
                $rows[substr($e['hired_at'], 0, 7)]['hires']++;
            }
            if ($e['fired_at'] !== null && $e['fired_at'] >= $from && $e['fired_at'] <= $to) {
                $rows[substr($e['fired_at'], 0, 7)]['terminations']++;
            }
        }

        return array_values($rows);
    }
}
