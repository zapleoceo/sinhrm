<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Listeners;

use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\Pulse\Services\LifecycleSurveys;

/** People → Pulse: a termination starts active exit surveys for that employee (once per termination date). */
final readonly class StartExitSurvey
{
    public function __construct(private LifecycleSurveys $surveys) {}

    public function handle(EmployeeTerminated $event): void
    {
        $this->surveys->employeeTerminated($event->employee);
    }
}
