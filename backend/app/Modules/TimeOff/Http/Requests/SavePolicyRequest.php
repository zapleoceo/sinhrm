<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use App\Modules\Directory\Models\Branch;
use App\Modules\TimeOff\Enums\AccrualMode;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /timeoff/policies, PATCH /timeoff/policies/{policy}. branch_id null = company default. Admin (route gate). */
final class SavePolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'leave_type_id' => [$req, 'required', 'integer', Rule::exists(LeaveType::class, 'id')],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Branch::class, 'id')],
            'accrual_mode' => ['sometimes', Rule::enum(AccrualMode::class)],
            'annual_days' => [$req, 'required', 'numeric', 'between:0,366'],
            'carry_over_max' => ['sometimes', 'nullable', 'numeric', 'between:0,366'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $attributes = [];
        foreach (['leave_type_id', 'branch_id'] as $id) {
            if ($this->has($id)) {
                $attributes[$id] = $this->filled($id) ? $this->integer($id) : null;
            }
        }
        foreach (['annual_days', 'carry_over_max'] as $number) {
            if ($this->has($number)) {
                $attributes[$number] = $this->filled($number) ? round($this->float($number), 2) : null;
            }
        }
        if ($this->has('accrual_mode')) {
            $attributes['accrual_mode'] = $this->string('accrual_mode')->toString();
        }
        if ($this->has('active')) {
            $attributes['active'] = $this->boolean('active');
        }

        return $attributes;
    }
}
