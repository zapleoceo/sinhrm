<?php

declare(strict_types=1);

namespace App\Modules\People\Privacy;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeChangeRequest;
use Illuminate\Support\Carbon;

/**
 * People's share of an employee's data: the profile and change requests. Erase only after offboarding (status
 * terminated): contacts, birth date, address, emergency contact, avatar, custom fields and change requests are wiped,
 * the name becomes "Видалений співробітник #id". Kept for labour-law records and stats: dates of hire/termination,
 * branch, department, position, manager, employment type.
 *
 * For a candidate: a hired candidate who still works here can't be erased as a candidate (erase the employee
 * after offboarding instead).
 */
final readonly class EmployeePersonalData implements PersonalDataProvider
{
    public function section(): string
    {
        return 'employee';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        if ($subject->type === DataSubjectType::Candidate) {
            $employee = Employee::query()->where('candidate_id', $subject->id)->first();

            return $erase && $employee !== null && ! $employee->isTerminated() ? 'hired' : null;
        }
        $employee = Employee::query()->find($subject->id);
        if ($employee === null) {
            return 'not_found';
        }

        return $erase && ! $employee->isTerminated() ? 'not_terminated' : null;
    }

    public function export(DataSubject $subject): array
    {
        $employee = $this->employee($subject);
        if ($employee === null) {
            return [];
        }
        $employee->load(['branch', 'department', 'position']);

        return [
            'profile' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'work_email' => $employee->work_email,
                'personal_email' => $employee->personal_email,
                'phone' => $employee->phone,
                'birth_date' => $employee->birth_date?->toDateString(),
                'address' => $employee->address,
                'emergency_contact' => $employee->emergency_contact,
                'avatar_url' => $employee->avatar_url,
                'custom_fields' => $employee->custom_fields,
                'branch' => $employee->branch?->name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'employment_type' => $employee->employment_type->value,
                'status' => $employee->status->value,
                'hired_at' => $employee->hired_at->toDateString(),
                'fired_at' => $employee->fired_at?->toDateString(),
                'termination_reason' => $employee->termination_reason,
                'anonymized_at' => $employee->anonymized_at?->toIso8601String(),
            ],
            'change_requests' => EmployeeChangeRequest::query()->where('employee_id', $employee->id)->orderBy('id')->get()
                ->map(static fn (EmployeeChangeRequest $r): array => [
                    'changes' => $r->changes,
                    'status' => $r->status->value,
                    'comment' => $r->comment,
                    'decision_comment' => $r->decision_comment,
                    'created_at' => $r->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    public function erase(DataSubject $subject): array
    {
        $employee = $this->employee($subject);
        if ($employee === null) {
            return [];
        }
        $employee->forceFill([
            'full_name' => 'Видалений співробітник #'.$employee->id,
            'work_email' => null,
            'personal_email' => null,
            'phone' => null,
            'birth_date' => null,
            'address' => null,
            'emergency_contact' => null,
            'avatar_url' => null,
            'custom_fields' => null,
            'termination_reason' => null,
            'anonymized_at' => $employee->anonymized_at ?? Carbon::now(),
        ])->save();

        return ['change_requests' => EmployeeChangeRequest::query()->where('employee_id', $employee->id)->delete()];
    }

    private function employee(DataSubject $subject): ?Employee
    {
        return $subject->type === DataSubjectType::Employee ? Employee::query()->find($subject->id) : null;
    }
}
