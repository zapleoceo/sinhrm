<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Controllers;

use App\Models\User;
use App\Modules\Knowledge\Enums\ArticleStatus;
use App\Modules\Knowledge\Http\Requests\SaveArticleRequest;
use App\Modules\Knowledge\Http\Requests\SaveKbCategoryRequest;
use App\Modules\Knowledge\Http\Requests\SearchArticlesRequest;
use App\Modules\Knowledge\Http\Requests\VoteRequest;
use App\Modules\Knowledge\Http\Resources\ArticlePresenter;
use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Knowledge\Models\KbArticleVersion;
use App\Modules\Knowledge\Models\KbCategory;
use App\Modules\Knowledge\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Knowledge base: categories, search, article view, votes; writing — gate knowledge-manage (routes). */
final class KnowledgeController
{
    public function __construct(private readonly KnowledgeService $kb) {}

    public function categories(): JsonResponse
    {
        return new JsonResponse(['data' => $this->kb->categories()->map(static fn (KbCategory $c): array => ArticlePresenter::category($c))->values()->all()]);
    }

    public function storeCategory(SaveKbCategoryRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ArticlePresenter::category($this->kb->saveCategory(null, $request->attributesToSave()))], 201);
    }

    public function updateCategory(SaveKbCategoryRequest $request, int $category): JsonResponse
    {
        return new JsonResponse(['data' => ArticlePresenter::category($this->kb->saveCategory($this->kb->findCategory($category), $request->attributesToSave()))]);
    }

    public function index(SearchArticlesRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $editor = $this->kb->isEditor($actor);

        return new JsonResponse(['data' => $this->kb->search($actor, $request->q(), $request->categoryId(), $request->tag())
            ->map(static fn (KbArticle $a): array => ArticlePresenter::present($a, $editor))->values()->all()]);
    }

    public function show(Request $request, int $article): JsonResponse
    {
        $actor = $this->actor($request);
        $found = $this->kb->findVisible($actor, $article);

        return $this->detail($actor, $found);
    }

    public function store(SaveArticleRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        /** @var array{category_id?: int|null, title: string, body_md: string, tags?: list<string>, audience?: array<string, mixed>, status?: ArticleStatus} $data */
        $data = $request->articleData();

        return $this->detail($actor, $this->kb->create($actor, $data))->setStatusCode(201);
    }

    public function update(SaveArticleRequest $request, int $article): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->detail($actor, $this->kb->update($actor, $this->kb->findVisible($actor, $article), $request->articleData()));
    }

    public function versions(Request $request, int $article): JsonResponse
    {
        $found = $this->kb->findVisible($this->actor($request), $article);

        return new JsonResponse(['data' => $this->kb->versions($found)->map(static fn (KbArticleVersion $v): array => [
            'version' => $v->version,
            'title' => $v->title,
            'body_md' => $v->body_md,
            'edited_by' => $v->edited_by,
            'created_at' => $v->created_at?->toIso8601String(),
        ])->values()->all()]);
    }

    public function vote(VoteRequest $request, int $article): JsonResponse
    {
        $actor = $this->actor($request);
        $found = $this->kb->findVisible($actor, $article);

        return $this->detail($actor, $this->kb->vote($actor, $found, $request->helpful()));
    }

    private function detail(User $actor, KbArticle $article): JsonResponse
    {
        return new JsonResponse(['data' => ArticlePresenter::present($article, $this->kb->isEditor($actor), true, $this->kb->myVote($actor, $article))]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
