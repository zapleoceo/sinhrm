<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/knowledge/articles/{id}/vote {helpful: bool} */
final class VoteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['helpful' => ['required', 'boolean']];
    }

    public function helpful(): bool
    {
        return $this->boolean('helpful');
    }
}
