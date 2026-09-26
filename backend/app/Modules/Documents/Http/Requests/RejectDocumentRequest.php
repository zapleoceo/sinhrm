<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/documents/{document}/reject {reason?} — by the employee the document belongs to. */
final class RejectDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? (string) $this->string('reason') : null;
    }
}
