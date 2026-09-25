<?php

declare(strict_types=1);

namespace App\Modules\Directory\Services;

use App\Models\User;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\DTO\DictionaryFilter;
use App\Modules\Directory\DTO\DictionaryItemData;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Models\DictionaryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/** Manual management of the dictionaries. No hard delete: items are disabled. */
final class DirectoryService
{
    public function __construct(
        private readonly DictionaryRepository $dictionaries,
        private readonly LoggerInterface $log,
    ) {}

    /** @return LengthAwarePaginator<int, DictionaryItem> */
    public function list(DictionaryType $type, DictionaryFilter $filter): LengthAwarePaginator
    {
        return $this->dictionaries->paginate($type, $filter);
    }

    /** @throws ModelNotFoundException<DictionaryItem> */
    public function find(DictionaryType $type, int $id): DictionaryItem
    {
        return $this->dictionaries->find($type, $id)
            ?? throw (new ModelNotFoundException)->setModel($type->modelClass(), [$id]);
    }

    public function create(User $actor, DictionaryType $type, DictionaryItemData $data): DictionaryItem
    {
        $item = $this->dictionaries->create($type, $data->attributes());
        $this->log->info('directory.created', ['type' => $type->value, 'id' => $item->id, 'by' => $actor->id]);

        return $this->fresh($type, $item);
    }

    public function update(User $actor, DictionaryType $type, DictionaryItem $item, DictionaryItemData $data): DictionaryItem
    {
        $attributes = $data->attributes();
        if ($attributes === []) {
            return $item;
        }
        $this->dictionaries->update($item, $attributes);
        $this->log->info('directory.updated', [
            'type' => $type->value,
            'id' => $item->id,
            'by' => $actor->id,
            'fields' => array_keys($attributes),
        ]);

        return $this->fresh($type, $item);
    }

    /** Re-read so the response carries relations (a branch's city) and DB defaults. */
    private function fresh(DictionaryType $type, DictionaryItem $item): DictionaryItem
    {
        return $this->dictionaries->find($type, $item->id) ?? $item;
    }
}
