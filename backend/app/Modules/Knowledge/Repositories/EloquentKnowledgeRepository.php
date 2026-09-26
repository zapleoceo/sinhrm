<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Repositories;

use App\Modules\Knowledge\Contracts\KnowledgeRepository;
use App\Modules\Knowledge\Contracts\PublishedArticles;
use App\Modules\Knowledge\Enums\ArticleStatus;
use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Knowledge\Models\KbArticleVersion;
use App\Modules\Knowledge\Models\KbCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentKnowledgeRepository implements KnowledgeRepository, PublishedArticles
{
    public function categories(): Collection
    {
        return KbCategory::query()->orderBy('position')->orderBy('name')->get();
    }

    public function findCategory(int $id): ?KbCategory
    {
        return KbCategory::query()->find($id);
    }

    public function saveCategory(?KbCategory $category, array $attributes): KbCategory
    {
        $category ??= new KbCategory;
        $category->fill($attributes)->save();

        return $category;
    }

    public function articles(?string $q, ?int $categoryId, bool $publishedOnly, int $limit): Collection
    {
        $query = KbArticle::query()->with('category')->withCount([
            'votes as helpful_count' => static fn (Builder $v) => $v->where('helpful', true),
            'votes as not_helpful_count' => static fn (Builder $v) => $v->where('helpful', false),
        ]);
        if ($q !== null && $q !== '') {
            $operator = (new KbArticle)->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            // "!" escapes the wildcards: ESCAPE works the same on Postgres and SQLite (SQLite has no default escape).
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(static fn (Builder $w) => $w
                ->whereRaw("title {$operator} ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("body_md {$operator} ? ESCAPE '!'", [$pattern]));
        }

        return $query
            ->when($categoryId !== null, static fn (Builder $w) => $w->where('category_id', $categoryId))
            ->when($publishedOnly, static fn (Builder $w) => $w->where('status', ArticleStatus::Published->value))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?KbArticle
    {
        return KbArticle::query()->with('category')->withCount([
            'votes as helpful_count' => static fn (Builder $v) => $v->where('helpful', true),
            'votes as not_helpful_count' => static fn (Builder $v) => $v->where('helpful', false),
        ])->find($id);
    }

    public function create(array $attributes): KbArticle
    {
        return KbArticle::query()->create($attributes);
    }

    public function update(KbArticle $article, array $attributes, bool $snapshot, int $editorId): KbArticle
    {
        return DB::transaction(function () use ($article, $attributes, $snapshot, $editorId): KbArticle {
            if ($snapshot) {
                KbArticleVersion::query()->create([
                    'article_id' => $article->id,
                    'version' => $article->version,
                    'title' => $article->getOriginal('title'),
                    'body_md' => $article->getOriginal('body_md'),
                    'edited_by' => $editorId,
                ]);
                $attributes['version'] = $article->version + 1;
            }
            $article->fill($attributes)->save();

            return $article;
        });
    }

    public function versions(int $articleId): Collection
    {
        return KbArticleVersion::query()->where('article_id', $articleId)->orderByDesc('version')->get();
    }

    public function vote(int $articleId, int $userId, bool $helpful): void
    {
        DB::table('kb_votes')->upsert(
            [['article_id' => $articleId, 'user_id' => $userId, 'helpful' => $helpful, 'created_at' => now(), 'updated_at' => now()]],
            ['article_id', 'user_id'],
            ['helpful', 'updated_at'],
        );
    }

    public function myVote(int $articleId, int $userId): ?bool
    {
        $value = DB::table('kb_votes')->where('article_id', $articleId)->where('user_id', $userId)->value('helpful');

        return $value === null ? null : (bool) $value;
    }

    public function titles(array $ids): array
    {
        return $this->publishedTitles($ids);
    }

    public function publishedTitles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var array<int, string> $titles */
        $titles = KbArticle::query()
            ->whereIn('id', $ids)
            ->where('status', ArticleStatus::Published->value)
            ->pluck('title', 'id')
            ->all();

        return $titles;
    }
}
