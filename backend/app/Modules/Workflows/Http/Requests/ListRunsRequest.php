<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Workflows\DTO\RunFilter;
use App\Modules\Workflows\Enums\RunStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/workflows/runs?employee_id=&template_id=&status= (strings are fine). */
final class ListRunsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(RunStatus::class)],
        ];
    }

    public function filter(): RunFilter
    {
        return new RunFilter(
            employeeId: $this->filled('employee_id') ? $this->integer('employee_id') : null,
            templateId: $this->filled('template_id') ? $this->integer('template_id') : null,
            status: $this->enum('status', RunStatus::class),
        );
    }
}
