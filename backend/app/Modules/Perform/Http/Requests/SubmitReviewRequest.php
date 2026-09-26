<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/perform/review/assignments/{id}/submit {answers: [{competency_id, rating, comment?}]}. */
final class SubmitReviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:30'],
            'answers.*.competency_id' => ['required', 'integer'],
            'answers.*.rating' => ['required', 'integer', 'min:0', 'max:100'],
            'answers.*.comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<array{competency_id: int, rating: int, comment?: string|null}> */
    public function answers(): array
    {
        return array_values(array_map(static fn (array $a): array => [
            'competency_id' => (int) $a['competency_id'],
            'rating' => (int) $a['rating'],
            'comment' => isset($a['comment']) ? (string) $a['comment'] : null,
        ], (array) $this->input('answers')));
    }
}
