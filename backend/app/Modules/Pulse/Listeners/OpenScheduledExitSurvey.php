<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Listeners;

use App\Modules\People\Events\EmployeeTerminationScheduled;
use App\Modules\Pulse\Services\LifecycleSurveys;

/** People → Pulse: a future termination opens the exit survey now, while the employee can still log in and answer. */
final readonly class OpenScheduledExitSurvey
{
    public function __construct(private LifecycleSurveys $surveys) {}

    public function handle(EmployeeTerminationScheduled $event): void
    {
        $this->surveys->terminationScheduled($event->employee);
    }
}
