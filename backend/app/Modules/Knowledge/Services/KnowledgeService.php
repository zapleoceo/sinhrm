<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Services;

use App\Models\User;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Documents\Support\MarkdownRenderer;
use App\Modules\Knowledge\Contracts\KnowledgeRepository;
use App\Modules\Knowledge\Enums\ArticleStatus;
use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Knowledge\Models\KbArticleVersion;
use App\Modules\Knowledge\Models\KbCategory;
use App\Modules\Knowledge\Support\Audience;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Knowledge base. Admins (HR) write and see drafts; everyone else reads published articles whose audience includes
 * them (all / their branches / their roles). Markdown is rendered once on save by the Documents renderer
 * (raw HTML escaped, unsafe links dropped) — the browser only receives sanitized HTML.
 */
final readonly class KnowledgeService
{
    public const int LIMIT = 200;

    /** Articles scanned before the audience filter (the base is small; the filter runs in PHP for portability). */
    private const int SCAN = 1000;

    public function __construct(
        private KnowledgeRepository $kb,
        private PeopleScope $scope,
        private AccessibleBranches $branches,
    ) {}

    public function isEditor(User $user): bool
    {
        return $this->scope->isAdmin($user);
    }

    /** @return Collection<int, KbCategory> */
    public function categories(): Collection
    {
        return $this->kb->categories();
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveCategory(?KbCategory $category, array $attributes): KbCategory
    {
        return $this->kb->saveCategory($category, $attributes);
    }

    public function findCategory(int $id): KbCategory
    {
        return $this->kb->findCategory($id) ?? abort(404);
    }

    /** @return Collection<int, KbArticle> */
    public function search(User $user, ?string $q, ?int $categoryId, ?string $tag): Collection
    {
        $editor = $this->isEditor($user);
        $reader = $editor ? null : $this->reader($user);
        $found = $this->kb->articles($q === null ? null : trim($q), $categoryId, ! $editor, self::SCAN)
            ->filter(fn (KbArticle $a): bool => ($reader === null || Audience::allows($a->audience, $reader['branches'], $reader['roles']))
                && ($tag === null || in_array($tag, $a->tags, true)));

        return new Collection($found->take(self::LIMIT)->values()->all());
    }

    public function findVisible(User $user, int $id): KbArticle
    {
        $article = $this->kb->find($id);
        if ($article === null || ! $this->canRead($user, $article)) {
            abort(404);
        }

        return $article;
    }

    public function canRead(User $user, KbArticle $article): bool
    {
        if ($this->isEditor($user)) {
            return true;
        }
        if (! $user->isActive() || $article->status !== ArticleStatus::Published) {
            return false;
        }
        $reader = $this->reader($user);

        return Audience::allows($article->audience, $reader['branches'], $reader['roles']);
    }

    /** @param  array{category_id?: int|null, title: string, body_md: string, tags?: list<string>, audience?: array<string, mixed>, status?: ArticleStatus}  $data */
    public function create(User $editor, array $data, ?Carbon $now = null): KbArticle
    {
        $status = $data['status'] ?? ArticleStatus::Draft;
        $article = $this->kb->create([
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'body_md' => $data['body_md'],
            'body_html' => MarkdownRenderer::toHtml($data['body_md']),
            'tags' => self::tags($data['tags'] ?? []),
            'audience' => Audience::normalize($data['audience'] ?? []),
            'status' => $status->value,
            'version' => 1,
            'author_id' => $editor->id,
            'updated_by' => $editor->id,
            'published_at' => $status === ArticleStatus::Published ? ($now ?? Carbon::now()) : null,
        ]);

        return $this->kb->find($article->id) ?? $article;
    }

    /**
     * A change of the title or the body keeps the previous text as a version (version + 1).
     *
     * @param  array{category_id?: int|null, title?: string, body_md?: string, tags?: list<string>, audience?: array<string, mixed>, status?: ArticleStatus}  $data
     */
    public function update(User $editor, KbArticle $article, array $data, ?Carbon $now = null): KbArticle
    {
        $attributes = ['updated_by' => $editor->id];
        foreach (['category_id', 'title'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }
        if (isset($data['body_md'])) {
            $attributes['body_md'] = $data['body_md'];
            $attributes['body_html'] = MarkdownRenderer::toHtml($data['body_md']);
        }
        if (isset($data['tags'])) {
            $attributes['tags'] = self::tags($data['tags']);
        }
        if (isset($data['audience'])) {
            $attributes['audience'] = Audience::normalize($data['audience']);
        }
        if (isset($data['status'])) {
            $attributes['status'] = $data['status']->value;
            if ($data['status'] === ArticleStatus::Published && $article->published_at === null) {
                $attributes['published_at'] = $now ?? Carbon::now();
            }
        }
        $textChanged = (isset($data['title']) && $data['title'] !== $article->title)
            || (isset($data['body_md']) && $data['body_md'] !== $article->body_md);
        $this->kb->update($article, $attributes, $textChanged, $editor->id);

        return $this->kb->find($article->id) ?? $article;
    }

    /** @return Collection<int, KbArticleVersion> */
    public function versions(KbArticle $article): Collection
    {
        return $this->kb->versions($article->id);
    }

    public function vote(User $user, KbArticle $article, bool $helpful): KbArticle
    {
        $this->kb->vote($article->id, $user->id, $helpful);

        return $this->kb->find($article->id) ?? $article;
    }

    public function myVote(User $user, KbArticle $article): ?bool
    {
        return $this->kb->myVote($article->id, $user->id);
    }

    /** @return array{branches: list<int>, roles: list<string>} */
    private function reader(User $user): array
    {
        $branches = $this->branches->for($user) ?? [];
        $employeeBranch = $this->scope->employeeOf($user)?->branch_id;
        if ($employeeBranch !== null) {
            $branches[] = $employeeBranch;
        }

        return ['branches' => array_values(array_unique($branches)), 'roles' => array_values($user->getRoleNames()->all())];
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private static function tags(array $tags): array
    {
        $clean = array_values(array_unique(array_filter(array_map(static fn (string $t): string => mb_strtolower(trim($t)), $tags), static fn (string $t): bool => $t !== '')));
        sort($clean);

        return $clean;
    }
}
