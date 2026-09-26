<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/desk/cases/{id}/comments: body, internal (HR only), article_id (HR only, published article). */
final class CaseCommentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'internal' => ['sometimes', 'boolean'],
            'article_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }

    public function internal(): bool
    {
        return $this->boolean('internal');
    }

    public function articleId(): ?int
    {
        return $this->input('article_id') === null ? null : $this->integer('article_id');
    }
}
