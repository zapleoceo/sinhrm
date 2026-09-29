<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /vacancies/{vacancy}/personal-board/columns {title, color?}
 * PATCH /personal-board/columns/{column} {title?, color?, hidden?} ("1"/"0" strings are fine for hidden).
 */
final class BoardColumnRequest extends FormRequest
{
    /** Palette keys; the UI maps them to theme tokens (light/dark). */
    public const array COLORS = ['blue', 'green', 'amber', 'red', 'purple', 'grey'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:40'],
            'color' => ['sometimes', 'nullable', Rule::in(self::COLORS)],
            'hidden' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function columnData(): array
    {
        $data = $this->safe()->only(['title', 'color', 'hidden']);
        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('hidden', $data)) {
            $data['hidden'] = $this->boolean('hidden');
        }

        return $data;
    }
}
