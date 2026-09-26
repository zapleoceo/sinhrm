<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\DTO\DocumentFilter;
use App\Modules\Documents\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/documents?employee_id=&status=&category= (strings are fine). */
final class ListDocumentsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'category' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function filter(): DocumentFilter
    {
        return new DocumentFilter(
            employeeId: $this->filled('employee_id') ? $this->integer('employee_id') : null,
            status: $this->enum('status', DocumentStatus::class),
            category: $this->filled('category') ? (string) $this->string('category') : null,
        );
    }
}
