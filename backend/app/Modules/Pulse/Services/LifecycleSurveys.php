<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Enums\LifecycleTrigger;
use App\Modules\Pulse\Enums\WaveStatus;
use App\Modules\Pulse\Models\Survey;
use Illuminate\Support\Carbon;

/**
 * Lifecycle surveys: a personal wave for one employee — 30 / 90 days after hire (found by "pulse.tick", with a
 * catch-up window for missed cron runs) and the exit survey.
 * Exit: a FUTURE termination opens the wave when it is scheduled (People EmployeeTerminationScheduled) — the person
 * keeps access until the end of fired_at (Kyiv), so the wave lasts until then and never longer than 14 days; a
 * cancellation deletes it (or closes it when it already has an answer — answers are never lost). A termination today
 * or in the past (access ends at once) opens it on EmployeeTerminated for 14 days, as before; HR may then enter the
 * answers on the person's behalf (ResponseService::respondOnBehalf).
 * Idempotent: one wave per (survey, employee, "<trigger>:<date>"); EmployeeTerminated after a scheduled wave does not
 * create a second one; a rehire or a new termination is a new date.
 * Lifecycle waves are confidential, not anonymous (a group of one cannot be anonymous): only HR (pulse-manage) sees them.
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

    /** @return int waves started (0 when the wave of that date already exists, e.g. opened when it was scheduled) */
    public function employeeTerminated(Employee $employee, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $anchor = $employee->fired_at ?? $now->copy()->startOfDay();

        return $this->startExit($employee, $anchor, $now, $now->copy()->addDays(self::DURATION_DAYS));
    }

    /** A future termination: open now, until the end of fired_at in the user's zone, at most 14 days. */
    public function terminationScheduled(Employee $employee, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        if ($employee->fired_at === null || $employee->isTerminated()) {
            return 0;
        }
        $lastMoment = UserTime::toStorage(Carbon::parse($employee->fired_at->toDateString(), UserTime::timezone())->endOfDay());
        $limit = $now->copy()->addDays(self::DURATION_DAYS);
        $ends = $lastMoment->lt($limit) ? $lastMoment : $limit;

        return $ends->lte($now) ? 0 : $this->startExit($employee, $employee->fired_at, $now, $ends);
    }

    /** @return int waves deleted or closed for the cancelled date */
    public function terminationCancelled(Employee $employee, string $firedAt, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $waves = $this->surveys->lifecycleWavesOf($employee->id, LifecycleTrigger::Exit->value.':'.$firedAt);
        foreach ($waves as $wave) {
            if ((int) $wave->responses_count === 0) {
                $this->surveys->deleteWave($wave);
            } elseif ($wave->status !== WaveStatus::Closed) {
                $this->lifecycle->close($wave, $now);
            }
        }

        return $waves->count();
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
                        $started += $this->start($survey, $employee, $trigger, $employee->hired_at, $now, $now->copy()->addDays(self::DURATION_DAYS));
                    }
                }
            }
        }

        return $started;
    }

    private function startExit(Employee $employee, Carbon $anchor, Carbon $now, Carbon $ends): int
    {
        $started = 0;
        foreach ($this->surveys->activeLifecycle(LifecycleTrigger::Exit) as $survey) {
            $started += $this->start($survey, $employee, LifecycleTrigger::Exit, $anchor, $now, $ends);
        }

        return $started;
    }

    private function start(Survey $survey, Employee $employee, LifecycleTrigger $trigger, Carbon $anchor, Carbon $now, Carbon $ends): int
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
            'ends_at' => $ends,
            'salt' => WaveLifecycle::salt(),
            'status' => $this->lifecycle->initialStatus($now, $now),
        ]);

        return $wave === null ? 0 : 1;
    }
}
