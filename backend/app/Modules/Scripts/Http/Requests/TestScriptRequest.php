<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/scripts/{script}/test {text, version?: draft|active} — preview of an evaluation, nothing is stored. */
final class TestScriptRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:50000'],
            'version' => ['nullable', 'in:draft,active'],
        ];
    }

    public function text(): string
    {
        return $this->string('text')->toString();
    }

    public function preferActive(): bool
    {
        return $this->input('version') === 'active';
    }
}
