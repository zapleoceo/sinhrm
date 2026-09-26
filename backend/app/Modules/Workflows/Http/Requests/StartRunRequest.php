<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\People\Models\Employee;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** POST /api/workflows/runs {template_id, employee_id, anchor_date?: Y-m-d (default today)}. Admin only. */
final class StartRunRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', Rule::exists(WorkflowTemplate::class, 'id')],
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'anchor_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function templateId(): int
    {
        return $this->integer('template_id');
    }

    public function employeeId(): int
    {
        return $this->integer('employee_id');
    }

    public function anchorDate(): ?Carbon
    {
        return $this->filled('anchor_date') ? Carbon::createFromFormat('Y-m-d', (string) $this->input('anchor_date'))?->startOfDay() : null;
    }
}
