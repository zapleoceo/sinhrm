<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PUT /api/perform/kpis {employee_id, metric, unit?, period "2026-10" | "2026-Q4", target, actual?}. */
final class SaveKpiRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'metric' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:32'],
            'period' => ['required', 'string', 'regex:'.ListPerformRequest::PERIOD],
            'target' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'actual' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
        ];
    }

    /** @return array{employee_id: int, metric: string, unit: string|null, period: string, target: float|int|string, actual: float|int|string|null} */
    public function payload(): array
    {
        return [
            'employee_id' => $this->integer('employee_id'),
            'metric' => (string) $this->string('metric'),
            'unit' => $this->filled('unit') ? (string) $this->string('unit') : null,
            'period' => (string) $this->string('period'),
            'target' => (string) $this->input('target'),
            'actual' => $this->filled('actual') ? (string) $this->input('actual') : null,
        ];
    }
}
