<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /timeoff/balances/adjust {employee_id, leave_type_id, delta (±, not 0), comment?}. Admin (route gate). */
final class AdjustBalanceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'leave_type_id' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')->where('tracks_balance', true)],
            'delta' => ['required', 'numeric', 'between:-366,366', 'not_in:0'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function comment(): ?string
    {
        return $this->filled('comment') ? $this->string('comment')->trim()->toString() : null;
    }
}
