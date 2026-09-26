<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Listeners;

use App\Modules\People\Events\EmployeeHired;
use App\Modules\TimeOff\Services\AccrualService;
use Illuminate\Support\Carbon;

/** A new employee gets the current period's (prorated) grant immediately instead of waiting for the cron. */
final readonly class GrantAccrualOnHire
{
    public function __construct(private AccrualService $accruals) {}

    public function handle(EmployeeHired $event): void
    {
        $this->accruals->accrueFor($event->employee, Carbon::now());
    }
}
