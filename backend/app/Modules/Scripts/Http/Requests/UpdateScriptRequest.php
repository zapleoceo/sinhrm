<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /api/scripts/{script} {name?, archived?}: rename, archive (instead of delete), restore. */
final class UpdateScriptRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'archived' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->trim()->toString() : null;
    }

    public function archived(): ?bool
    {
        return $this->has('archived') ? $this->boolean('archived') : null;
    }
}
