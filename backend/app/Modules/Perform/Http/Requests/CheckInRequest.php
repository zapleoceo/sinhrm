<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/perform/objectives/{id}/check-ins {key_results: [{id, current}], comment?}. */
final class CheckInRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key_results' => ['required', 'array', 'min:1', 'max:10'],
            'key_results.*.id' => ['required', 'string', 'max:32'],
            'key_results.*.current' => ['required', 'numeric'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<array{id: string, current: float|int}> */
    public function values(): array
    {
        return array_values(array_map(static fn (array $kr): array => [
            'id' => (string) $kr['id'],
            'current' => $kr['current'] + 0,
        ], (array) $this->input('key_results')));
    }

    public function comment(): ?string
    {
        return $this->filled('comment') ? (string) $this->string('comment') : null;
    }
}
