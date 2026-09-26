<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Controllers;

use App\Models\User;
use App\Modules\Desk\Http\Requests\SaveDeskCategoryRequest;
use App\Modules\Desk\Http\Resources\DeskCasePresenter;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Desk\Services\DeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Case categories: everyone reads the active ones (to open a case); HR manages (?all=1 adds inactive). */
final class DeskCategoryController
{
    public function __construct(private readonly DeskService $desk) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return new JsonResponse(['data' => $this->desk->categories($user, $request->boolean('all'))
            ->map(static fn (DeskCategory $c): array => DeskCasePresenter::category($c))->values()->all()]);
    }

    public function store(SaveDeskCategoryRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => DeskCasePresenter::category($this->desk->saveCategory(null, $request->attributesToSave()))], 201);
    }

    public function update(SaveDeskCategoryRequest $request, int $category): JsonResponse
    {
        $saved = $this->desk->saveCategory($this->desk->findCategory($category), $request->attributesToSave());

        return new JsonResponse(['data' => DeskCasePresenter::category($saved)]);
    }
}
