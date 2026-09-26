<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** ?employee_id (own employee when omitted) and ?leave_type_id — balances and their history. */
final class EmployeeScopedRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'leave_type_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function employeeId(): ?int
    {
        return $this->filled('employee_id') ? $this->integer('employee_id') : null;
    }

    public function leaveTypeId(): ?int
    {
        return $this->filled('leave_type_id') ? $this->integer('leave_type_id') : null;
    }
}
