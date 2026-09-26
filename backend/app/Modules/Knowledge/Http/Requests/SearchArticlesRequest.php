<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/knowledge/articles?q=&category_id=&tag= */
final class SearchArticlesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function q(): ?string
    {
        return $this->filled('q') ? $this->string('q')->toString() : null;
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? $this->integer('category_id') : null;
    }

    public function tag(): ?string
    {
        return $this->filled('tag') ? mb_strtolower(trim($this->string('tag')->toString())) : null;
    }
}
