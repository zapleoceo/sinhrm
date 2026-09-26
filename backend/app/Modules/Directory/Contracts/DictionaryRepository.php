<?php

declare(strict_types=1);

namespace App\Modules\Directory\Contracts;

use App\Modules\Directory\DTO\DictionaryFilter;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Models\DictionaryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DictionaryRepository
{
    /** @return LengthAwarePaginator<int, DictionaryItem> */
    public function paginate(DictionaryType $type, DictionaryFilter $filter): LengthAwarePaginator;

    public function find(DictionaryType $type, int $id): ?DictionaryItem;

    /** @param  array<string, mixed>  $attributes */
    public function create(DictionaryType $type, array $attributes): DictionaryItem;

    /** @param  array<string, mixed>  $attributes */
    public function update(DictionaryItem $item, array $attributes): DictionaryItem;

    /** @return list<int> ids of the active branches assigned to the user */
    public function activeBranchIdsOfUser(int $userId): array;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
