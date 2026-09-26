<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST/PUT /api/perform/review/scales {name, levels: [{value 0..100, label}] (2..10, distinct values)}. Admins. */
final class SaveScaleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'levels' => ['required', 'array', 'min:2', 'max:10'],
            'levels.*.value' => ['required', 'integer', 'min:0', 'max:100', 'distinct'],
            'levels.*.label' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array{name: string, levels: list<array{value: int, label: string}>} */
    public function payload(): array
    {
        return [
            'name' => (string) $this->string('name'),
            'levels' => array_values(array_map(
                static fn (array $l): array => ['value' => (int) $l['value'], 'label' => (string) $l['label']],
                (array) $this->input('levels'),
            )),
        ];
    }
}
