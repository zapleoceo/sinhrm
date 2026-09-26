<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/pulse/waves/{id}/responses {answers: {question id: value}}; values are checked by AnswerValidator. */
final class RespondRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['answers' => ['present', 'array', 'max:50']];
    }

    /** @return array<string, mixed> */
    public function answers(): array
    {
        $answers = [];
        foreach ((array) $this->input('answers', []) as $key => $value) {
            $answers[(string) $key] = $value;
        }

        return $answers;
    }
}
