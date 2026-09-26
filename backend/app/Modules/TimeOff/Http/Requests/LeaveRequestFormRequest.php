<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use App\Modules\TimeOff\DTO\LeaveRequestData;
use App\Modules\TimeOff\Enums\HalfDay;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POST /timeoff/requests and GET /timeoff/requests/preview:
 * {leave_type_id, starts_on, ends_on, half_day?, comment?, employee_id?, override_balance?}.
 */
final class LeaveRequestFormRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'half_day' => ['nullable', Rule::enum(HalfDay::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'override_balance' => ['nullable', 'boolean'],
        ];
    }

    public function employeeId(): ?int
    {
        return $this->filled('employee_id') ? $this->integer('employee_id') : null;
    }

    public function leaveTypeId(): int
    {
        return $this->integer('leave_type_id');
    }

    public function leaveData(): LeaveRequestData
    {
        return new LeaveRequestData(
            leaveTypeId: $this->integer('leave_type_id'),
            startsOn: $this->day('starts_on'),
            endsOn: $this->day('ends_on'),
            halfDay: $this->enum('half_day', HalfDay::class) ?? HalfDay::None,
            comment: $this->filled('comment') ? $this->string('comment')->trim()->toString() : null,
            overrideBalance: $this->boolean('override_balance'),
        );
    }

    private function day(string $key): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string($key)->toString())?->startOfDay() ?? Carbon::today();
    }
}
