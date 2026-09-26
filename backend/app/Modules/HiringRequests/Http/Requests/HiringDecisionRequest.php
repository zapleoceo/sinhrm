<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/hiring-requests/{id}/decision {decision: approve|reject, comment?, recruiter_id?}. A reject needs a comment. */
final class HiringDecisionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
            'recruiter_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function approve(): bool
    {
        return $this->string('decision')->toString() === 'approve';
    }

    public function comment(): ?string
    {
        return $this->filled('comment') ? trim($this->string('comment')->toString()) : null;
    }

    public function recruiterId(): ?int
    {
        return $this->filled('recruiter_id') ? $this->integer('recruiter_id') : null;
    }
}
