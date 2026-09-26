<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/documents {employee_id, template_id?, title?, category?, content_md?}. Admin only (route gate).
 * Without a template a title is required; content may be added later or replaced by a file.
 */
final class CreateDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'template_id' => ['nullable', 'integer', Rule::exists(DocumentTemplate::class, 'id')],
            'title' => ['required_without:template_id', 'nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'content_md' => ['nullable', 'string', 'max:100000'],
        ];
    }

    public function employeeId(): int
    {
        return $this->integer('employee_id');
    }

    public function templateId(): ?int
    {
        return $this->filled('template_id') ? $this->integer('template_id') : null;
    }

    public function title(): ?string
    {
        return $this->filled('title') ? (string) $this->string('title') : null;
    }

    public function category(): ?string
    {
        return $this->filled('category') ? (string) $this->string('category') : null;
    }

    public function contentMd(): ?string
    {
        return $this->filled('content_md') ? (string) $this->input('content_md') : null;
    }
}
