<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /people/{employee}/restore {position_id?, department_id?, branch_id?, manager_id?, hired_at?} (HR, route gate).
 * Omitted = keep the previous value; null = clear it. Ids may come as strings ("5").
 */
final class RestoreEmployeeRequest extends FormRequest
{
    private const array IDS = ['position_id', 'department_id', 'branch_id', 'manager_id'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Table names, not Directory models: People may use only other modules' Contracts (ModuleBoundariesTest).
        $active = static fn (string $table) => Rule::exists($table, 'id')->where('status', DirectoryStatus::Active->value);

        return [
            'position_id' => ['sometimes', 'nullable', 'integer', $active('positions')],
            'department_id' => ['sometimes', 'nullable', 'integer', $active('departments')],
            'branch_id' => ['sometimes', 'nullable', 'integer', $active('branches')],
            // A working manager only; self and someone below are rejected by the cycle check (422 manager_cycle).
            'manager_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists(Employee::class, 'id')->whereNot('status', EmployeeStatus::Terminated->value)],
            'hired_at' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, int|string|null> */
    public function placement(): array
    {
        $placement = [];
        foreach (self::IDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $placement[$field] = is_numeric($value) ? (int) $value : null;
            }
        }
        if ($this->has('hired_at')) {
            $placement['hired_at'] = $this->string('hired_at')->toString();
        }

        return $placement;
    }
}
