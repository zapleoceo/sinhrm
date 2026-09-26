<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /api/documents/{document} {title?, category?, content_md?, status?: "archived"}. Admin only. */
final class UpdateDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'content_md' => ['sometimes', 'nullable', 'string', 'max:100000'],
            // Other transitions have their own endpoints (send, acknowledge, reject).
            'status' => ['sometimes', Rule::in([DocumentStatus::Archived->value])],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        return $this->safe()->only(['title', 'category', 'content_md', 'status']);
    }
}
