<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;

/** Current balances (sum of the TimeOff ledger) of working employees in scope, by leave type that tracks a balance. */
final class LeaveBalancesReport extends AbstractTeamReport
{
    public const int LIMIT = 2000;

    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'leave_balances';
    }

    public function columns(): array
    {
        return [['key' => 'employee', 'type' => 'string'], ['key' => 'leave_type', 'type' => 'string'], ['key' => 'balance', 'type' => 'number', 'total' => 'none']];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        return $this->data->balances($ctx->employeeIds(), self::LIMIT);
    }
}
