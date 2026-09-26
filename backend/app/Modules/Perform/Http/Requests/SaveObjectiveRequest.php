<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\ObjectiveScope;
use App\Modules\Perform\Enums\ObjectiveStatus;
use App\Modules\Perform\Enums\ObjectiveVisibility;
use App\Modules\Perform\Models\Objective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /api/perform/objectives {scope, owner_employee_id?, department_id?, branch_id? (admins), period "2026-Q4",
 * title, description?, key_results: [{id?, title, start, target, current?, unit?, weight?}] (1..10),
 * status?, parent_objective_id?, visibility}.
 */
final class SaveObjectiveRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(ObjectiveScope::class)],
            'owner_employee_id' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists(Branch::class, 'id')],
            'period' => ['required', 'string', 'regex:/^\d{4}-Q[1-4]$/'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'key_results' => ['required', 'array', 'min:1', 'max:10'],
            'key_results.*.id' => ['nullable', 'string', 'max:32'],
            'key_results.*.title' => ['required', 'string', 'max:255'],
            'key_results.*.start' => ['required', 'numeric'],
            'key_results.*.target' => ['required', 'numeric'],
            'key_results.*.current' => ['nullable', 'numeric'],
            'key_results.*.unit' => ['nullable', 'string', 'max:32'],
            'key_results.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::enum(ObjectiveStatus::class)],
            'parent_objective_id' => ['nullable', 'integer', Rule::exists(Objective::class, 'id')],
            'visibility' => ['required', Rule::enum(ObjectiveVisibility::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['key_results'] = array_map(static function (array $kr): array {
            foreach (['start', 'target', 'current', 'weight'] as $field) {
                if (isset($kr[$field])) {
                    $kr[$field] = $kr[$field] + 0;
                }
            }
            $kr['current'] ??= $kr['start'];

            return $kr;
        }, array_values((array) $data['key_results']));

        return $data;
    }
}
