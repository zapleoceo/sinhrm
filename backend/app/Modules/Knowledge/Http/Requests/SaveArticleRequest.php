<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Knowledge\Enums\ArticleStatus;
use App\Modules\Knowledge\Models\KbCategory;
use App\Modules\Knowledge\Support\Audience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST (title + body required) / PATCH (partial) /api/knowledge/articles. */
final class SaveArticleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'body_md' => [$required, 'string', 'max:100000'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(KbCategory::class, 'id')],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'audience' => ['sometimes', 'array'],
            'audience.type' => ['required_with:audience', Rule::in([Audience::ALL, Audience::BRANCHES, Audience::ROLES])],
            'audience.ids' => ['required_if:audience.type,'.Audience::BRANCHES, 'array', 'max:200'],
            'audience.ids.*' => ['integer'],
            'audience.roles' => ['required_if:audience.type,'.Audience::ROLES, 'array'],
            'audience.roles.*' => [Rule::in(UserRole::values())],
            'status' => ['sometimes', Rule::enum(ArticleStatus::class)],
        ];
    }

    /** @return array{category_id?: int|null, title?: string, body_md?: string, tags?: list<string>, audience?: array<string, mixed>, status?: ArticleStatus} */
    public function articleData(): array
    {
        $out = [];
        if ($this->has('title')) {
            $out['title'] = trim($this->string('title')->toString());
        }
        if ($this->has('body_md')) {
            $out['body_md'] = $this->string('body_md')->toString();
        }
        if ($this->has('category_id')) {
            $out['category_id'] = $this->input('category_id') === null ? null : $this->integer('category_id');
        }
        if ($this->has('tags')) {
            $out['tags'] = array_values(array_map(strval(...), (array) $this->input('tags')));
        }
        if ($this->has('audience')) {
            /** @var array<string, mixed> $audience */
            $audience = (array) $this->input('audience');
            $out['audience'] = $audience;
        }
        $status = $this->enum('status', ArticleStatus::class);
        if ($status !== null) {
            $out['status'] = $status;
        }

        return $out;
    }
}
