<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\Core\Support\UserTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * POST /people/{employee}/terminate {fired_at, reason?, handover_to_employee_id?}. The handover colleague is checked
 * against the person picker rules of the caller in TerminationService.
 */
final class TerminateEmployeeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fired_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
            'handover_to_employee_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function firedAt(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('fired_at')->toString())?->startOfDay() ?? UserTime::today();
    }

    public function handoverToEmployeeId(): ?int
    {
        return $this->filled('handover_to_employee_id') ? $this->integer('handover_to_employee_id') : null;
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->trim()->toString() : null;
    }
}
