<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use App\Modules\TimeOff\DTO\LeaveRequestFilter;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListLeaveRequestsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'leave_type_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(LeaveRequestStatus::class)],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function filter(): LeaveRequestFilter
    {
        return new LeaveRequestFilter(
            employeeId: $this->filled('employee_id') ? $this->integer('employee_id') : null,
            status: $this->enum('status', LeaveRequestStatus::class),
            leaveTypeId: $this->filled('leave_type_id') ? $this->integer('leave_type_id') : null,
            perPage: $this->integer('perPage', 50),
        );
    }
}
