<?php

declare(strict_types=1);

namespace App\Modules\People\Privacy;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\People\Models\EmployeeCompensation;

/**
 * Compensation history of an employee. Export lists every record. Erase keeps it: pay is an employment record kept
 * for the period set by law (like signed documents). Candidates have no compensation.
 */
final readonly class CompensationPersonalData implements PersonalDataProvider
{
    public function section(): string
    {
        return 'compensation';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        return null;
    }

    public function export(DataSubject $subject): array
    {
        if ($subject->type !== DataSubjectType::Employee) {
            return [];
        }

        return EmployeeCompensation::query()->where('employee_id', $subject->id)->orderBy('effective_on')->orderBy('id')->get()
            ->map(static fn (EmployeeCompensation $c): array => [
                'amount' => $c->amount,
                'currency' => $c->currency,
                'period' => $c->period,
                'effective_on' => $c->effective_on->toDateString(),
                'reason' => $c->reason,
            ])->all();
    }

    public function erase(DataSubject $subject): array
    {
        return [];
    }
}
