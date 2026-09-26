<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Models\User;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Enums\EmploymentType;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /people (full_name + hired_at required) and PATCH /people/{employee} (partial). Admin only (route gate). */
final class SaveEmployeeRequest extends FormRequest
{
    private const array FIELDS = [
        'user_id', 'full_name', 'work_email', 'phone', 'avatar_url', 'birth_date', 'personal_email', 'address',
        'emergency_contact', 'custom_fields', 'hired_at', 'status', 'employment_type', 'work_schedule', 'branch_id',
        'department_id', 'position_id', 'manager_id',
    ];

    private const array IDS = ['user_id', 'branch_id', 'department_id', 'position_id', 'manager_id'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $employee = $this->route('employee');
        $active = static fn (string $model) => Rule::exists($model, 'id')->where('status', DirectoryStatus::Active->value);
        $req = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => [$req, 'required', 'string', 'max:255'],
            'hired_at' => [$req, 'required', 'date_format:Y-m-d'],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists(User::class, 'id'),
                Rule::unique(Employee::class, 'user_id')->ignore($employee instanceof Employee ? $employee->id : null)],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'avatar_url' => ['sometimes', 'nullable', 'url:https', 'max:512'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'custom_fields' => ['sometimes', 'nullable', 'array', 'max:50'],
            'custom_fields.*' => ['nullable', 'string', 'max:1000'],
            // Termination goes through POST /people/{id}/terminate.
            'status' => ['sometimes', 'required', Rule::in([EmployeeStatus::Active->value, EmployeeStatus::OnLeave->value])],
            'employment_type' => ['sometimes', 'required', Rule::enum(EmploymentType::class)],
            'work_schedule' => ['sometimes', 'nullable', 'array'],
            'work_schedule.days' => ['sometimes', 'array', 'max:7'],
            'work_schedule.days.*' => ['integer', 'between:1,7'],
            'work_schedule.hours_per_day' => ['sometimes', 'numeric', 'between:0,24'],
            'branch_id' => ['sometimes', 'nullable', 'integer', $active(Branch::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', $active(Department::class)],
            'position_id' => ['sometimes', 'nullable', 'integer', $active(Position::class)],
            'manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Employee::class, 'id')],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $attributes = [];
        foreach (self::FIELDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $attributes[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }
        foreach (self::IDS as $id) {
            if (isset($attributes[$id]) && is_numeric($attributes[$id])) {
                $attributes[$id] = (int) $attributes[$id];
            }
        }
        if (isset($attributes['work_email']) && is_string($attributes['work_email'])) {
            $attributes['work_email'] = mb_strtolower($attributes['work_email']);
        }

        return $attributes;
    }
}
