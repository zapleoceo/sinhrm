<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Enums\LifecycleTrigger;
use App\Modules\Pulse\Models\Survey;
use Illuminate\Support\Carbon;

/**
 * Lifecycle surveys: a personal wave for one employee — 30 / 90 days after hire (found by "pulse.tick", with a
 * catch-up window for missed cron runs) and the exit survey on termination (People's EmployeeTerminated).
 * Idempotent: one wave per (survey, employee, "<trigger>:<date>"); a rehire or a new termination is a new date.
 * Lifecycle waves are confidential, not anonymous (a group of one cannot be anonymous): admins only see them.
 */
final readonly class LifecycleSurveys
{
    public const int DURATION_DAYS = 14;

    /** A hire_30 wave is still started if cron missed the exact day, up to this many days late. */
    public const int CATCH_UP_DAYS = 7;

    public function __construct(
        private SurveyRepository $surveys,
        private EmployeeRepository $employees,
        private WaveLifecycle $lifecycle,
    ) {}

    /** @return int waves started */
    public function employeeTerminated(Employee $employee, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $anchor = $employee->fired_at ?? $now->copy()->startOfDay();
        $started = 0;
        foreach ($this->surveys->activeLifecycle(LifecycleTrigger::Exit) as $survey) {
            $started += $this->start($survey, $employee, LifecycleTrigger::Exit, $anchor, $now);
        }

        return $started;
    }

    /** @return int waves started for hire_30 / hire_90 anniversaries due today (or within the catch-up window) */
    public function hiresDue(Carbon $now): int
    {
        $today = $now->copy()->startOfDay();
        $started = 0;
        foreach ([LifecycleTrigger::Hire30, LifecycleTrigger::Hire90] as $trigger) {
            $surveys = $this->surveys->activeLifecycle($trigger);
            if ($surveys->isEmpty()) {
                continue;
            }
            foreach ($this->employees->working() as $employee) {
                $due = $employee->hired_at->copy()->startOfDay()->addDays((int) $trigger->daysAfterHire());
                if ($due->lte($today) && $due->gte($today->copy()->subDays(self::CATCH_UP_DAYS))) {
                    foreach ($surveys as $survey) {
                        $started += $this->start($survey, $employee, $trigger, $employee->hired_at, $now);
                    }
                }
            }
        }

        return $started;
    }

    private function start(Survey $survey, Employee $employee, LifecycleTrigger $trigger, Carbon $anchor, Carbon $now): int
    {
        $wave = $this->surveys->createLifecycleOnce([
            'survey_id' => $survey->id,
            'subject_employee_id' => $employee->id,
            'trigger_key' => $trigger->value.':'.$anchor->toDateString(),
            'schedule' => 'once',
            'audience' => ['branch_ids' => [], 'department_ids' => []],
            'anonymous' => false,
            'min_group_size' => 1,
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays(self::DURATION_DAYS),
            'salt' => WaveLifecycle::salt(),
            'status' => $this->lifecycle->initialStatus($now, $now),
        ]);

        return $wave === null ? 0 : 1;
    }
}
