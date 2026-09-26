<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/workflows/runs/{run}/steps/{step}/skip {reason?}. */
final class SkipStepRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? (string) $this->string('reason') : null;
    }
}
