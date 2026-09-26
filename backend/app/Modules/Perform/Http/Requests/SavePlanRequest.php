<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\PlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /api/perform/development-plans {employee_id, title, goals: [{id?, text}], actions: [{id?, text, due_on?,
 * done?}], due_on?, status?}.
 */
final class SavePlanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'title' => ['required', 'string', 'max:255'],
            'goals' => ['present', 'array', 'max:20'],
            'goals.*.id' => ['nullable', 'string', 'max:32'],
            'goals.*.text' => ['required', 'string', 'max:1000'],
            'actions' => ['present', 'array', 'max:50'],
            'actions.*.id' => ['nullable', 'string', 'max:32'],
            'actions.*.text' => ['required', 'string', 'max:1000'],
            'actions.*.due_on' => ['nullable', 'date_format:Y-m-d'],
            'actions.*.done' => ['sometimes', 'boolean'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(PlanStatus::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['actions'] = array_map(
            static fn (array $a): array => ['done' => (bool) ($a['done'] ?? false)] + $a,
            array_values((array) $data['actions']),
        );

        return $data;
    }
}
