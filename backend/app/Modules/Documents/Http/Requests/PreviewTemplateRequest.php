<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/documents/templates/preview {body, employee_id?} — nothing is saved. */
final class PreviewTemplateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['present', 'nullable', 'string', 'max:50000'],
            'employee_id' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')],
        ];
    }

    public function body(): string
    {
        return (string) $this->input('body', '');
    }

    public function employeeId(): ?int
    {
        return $this->filled('employee_id') ? $this->integer('employee_id') : null;
    }
}
