<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Listeners;

use App\Modules\People\Events\EmployeeTerminationCancelled;
use App\Modules\Pulse\Services\LifecycleSurveys;

/** People → Pulse: a cancelled termination deletes its exit survey wave (closes it if someone already answered). */
final readonly class WithdrawExitSurvey
{
    public function __construct(private LifecycleSurveys $surveys) {}

    public function handle(EmployeeTerminationCancelled $event): void
    {
        $this->surveys->terminationCancelled($event->employee, $event->firedAt);
    }
}
