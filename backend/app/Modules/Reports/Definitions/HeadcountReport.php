<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;

/** Working employees on a day (filter "to", default today) by branch and department. */
final class HeadcountReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'headcount';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function filters(): array
    {
        return [self::FILTER_TO, self::FILTER_BRANCH];
    }

    public function columns(): array
    {
        return [['key' => 'branch', 'type' => 'string'], ['key' => 'department', 'type' => 'string'], ['key' => 'headcount', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'department', 'value' => 'headcount'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $day = isset($filters['to']) ? Carbon::parse((string) $filters['to']) : $ctx->now;
        $groups = [];
        foreach ($this->data->employees($ctx->employeeIds(), self::branch($filters)) as $e) {
            if (! self::workingOn($e['hired_at'], $e['fired_at'], $day)) {
                continue;
            }
            $key = ($e['branch'] ?? '—').'|'.($e['department'] ?? '—');
            $groups[$key] ??= ['branch' => $e['branch'] ?? '—', 'department' => $e['department'] ?? '—', 'headcount' => 0];
            $groups[$key]['headcount']++;
        }
        ksort($groups);

        return array_values($groups);
    }
}
