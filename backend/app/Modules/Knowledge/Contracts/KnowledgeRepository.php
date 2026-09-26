<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Contracts;

use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Knowledge\Models\KbArticleVersion;
use App\Modules\Knowledge\Models\KbCategory;
use Illuminate\Database\Eloquent\Collection;

interface KnowledgeRepository
{
    /** @return Collection<int, KbCategory> */
    public function categories(): Collection;

    public function findCategory(int $id): ?KbCategory;

    /** @param  array<string, mixed>  $attributes */
    public function saveCategory(?KbCategory $category, array $attributes): KbCategory;

    /**
     * Articles with vote counts, newest first. $q matches title or body case-insensitively (ILIKE on Postgres,
     * LIKE elsewhere); wildcards in $q are literal.
     *
     * @return Collection<int, KbArticle>
     */
    public function articles(?string $q, ?int $categoryId, bool $publishedOnly, int $limit): Collection;

    public function find(int $id): ?KbArticle;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): KbArticle;

    /**
     * Updates the article; when $snapshot is true the previous title/body are kept as a version row first.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(KbArticle $article, array $attributes, bool $snapshot, int $editorId): KbArticle;

    /** @return Collection<int, KbArticleVersion> newest first */
    public function versions(int $articleId): Collection;

    public function vote(int $articleId, int $userId, bool $helpful): void;

    public function myVote(int $articleId, int $userId): ?bool;

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function publishedTitles(array $ids): array;
}
