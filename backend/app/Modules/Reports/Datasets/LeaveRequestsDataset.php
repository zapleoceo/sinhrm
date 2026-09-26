<?php

declare(strict_types=1);

namespace App\Modules\Reports\Datasets;

use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\ScopedContext;

/** Leave requests of employees in the People scope. Comments are not exposed. */
final class LeaveRequestsDataset implements Dataset
{
    public function key(): string
    {
        return 'leave_requests';
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function columns(): array
    {
        return [
            'id' => ['expr' => 'lr.id', 'type' => self::NUMBER],
            'employee' => ['expr' => 'e.full_name', 'type' => self::STRING],
            'branch' => ['expr' => 'b.name', 'type' => self::STRING],
            'leave_type' => ['expr' => 't.name', 'type' => self::STRING],
            'status' => ['expr' => 'lr.status', 'type' => self::STRING],
            'starts_on' => ['expr' => 'lr.starts_on', 'type' => self::DATE],
            'ends_on' => ['expr' => 'lr.ends_on', 'type' => self::DATE],
            'days' => ['expr' => 'lr.days', 'type' => self::NUMBER],
            'created_at' => ['expr' => 'lr.created_at', 'type' => self::DATE],
        ];
    }
}
