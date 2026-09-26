<?php

declare(strict_types=1);

namespace App\Modules\Directory\Http\Controllers;

use App\Models\User;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Http\Requests\ListDictionaryRequest;
use App\Modules\Directory\Http\Requests\SaveDictionaryItemRequest;
use App\Modules\Directory\Http\Resources\DictionaryItemResource;
use App\Modules\Directory\Services\DirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Company dictionaries. Access: routes.php (read — any active user; write — manage-directory). */
final class DirectoryController
{
    public function __construct(private readonly DirectoryService $service) {}

    public function index(ListDictionaryRequest $request, DictionaryType $dictionary): AnonymousResourceCollection
    {
        return DictionaryItemResource::collection($this->service->list($dictionary, $request->filter()));
    }

    public function store(SaveDictionaryItemRequest $request, DictionaryType $dictionary): JsonResponse
    {
        $item = $this->service->create($this->actor($request), $dictionary, $request->itemData());

        return (new DictionaryItemResource($item))->response()->setStatusCode(201);
    }

    public function update(SaveDictionaryItemRequest $request, DictionaryType $dictionary, int $id): DictionaryItemResource
    {
        $item = $this->service->find($dictionary, $id);

        return new DictionaryItemResource($this->service->update($this->actor($request), $dictionary, $item, $request->itemData()));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
