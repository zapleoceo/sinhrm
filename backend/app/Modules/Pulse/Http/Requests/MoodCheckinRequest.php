<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/pulse/mood {score: 1..5, comment?}. */
final class MoodCheckinRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function score(): int
    {
        return $this->integer('score');
    }

    public function comment(): ?string
    {
        return $this->filled('comment') ? (string) $this->string('comment') : null;
    }
}
