<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use App\Modules\TimeOff\Enums\LeaveUnit;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /timeoff/types (name + code required), PATCH /timeoff/types/{type} (partial). Admin (route gate). */
final class SaveLeaveTypeRequest extends FormRequest
{
    private const array FIELDS = ['name', 'code', 'paid', 'unit', 'color', 'requires_approval', 'tracks_balance', 'active'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';
        $type = $this->route('leaveType');

        return [
            'name' => [$req, 'required', 'string', 'max:100'],
            'code' => [$req, 'required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/',
                Rule::unique(LeaveType::class, 'code')->ignore($type instanceof LeaveType ? $type->id : null)],
            'paid' => ['sometimes', 'boolean'],
            'unit' => ['sometimes', Rule::enum(LeaveUnit::class)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'requires_approval' => ['sometimes', 'boolean'],
            'tracks_balance' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $attributes = [];
        foreach (self::FIELDS as $field) {
            if ($this->has($field)) {
                $attributes[$field] = in_array($field, ['paid', 'requires_approval', 'tracks_balance', 'active'], true)
                    ? $this->boolean($field)
                    : trim((string) $this->input($field));
            }
        }

        return $attributes;
    }
}
