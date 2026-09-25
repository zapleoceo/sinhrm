<?php

declare(strict_types=1);

namespace App\Modules\Directory\Repositories;

use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\DTO\DictionaryFilter;
use App\Modules\Directory\DTO\ImportedItem;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Enums\UpsertOutcome;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\DictionaryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentDictionaryRepository implements DictionaryRepository
{
    public function paginate(DictionaryType $type, DictionaryFilter $filter): LengthAwarePaginator
    {
        return $this->query($type)
            ->when($type === DictionaryType::Branches, fn (Builder $query) => $query->with('city'))
            ->when($filter->q, function (Builder $query, string $q): void {
                $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
                $query->whereRaw('lower(name) like ?', [$like]);
            })
            ->when($filter->status, fn (Builder $query, DirectoryStatus $s) => $query->where('status', $s->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filter->perPage);
    }

    public function find(DictionaryType $type, int $id): ?DictionaryItem
    {
        return $this->query($type)->find($id);
    }

    public function create(DictionaryType $type, array $attributes): DictionaryItem
    {
        return $this->query($type)->create($attributes);
    }

    public function update(DictionaryItem $item, array $attributes): DictionaryItem
    {
        $item->fill($attributes)->save();

        return $item;
    }

    public function upsert(DictionaryType $type, ImportedItem $item): UpsertOutcome
    {
        $attributes = ['name' => $item->name, 'status' => $item->status];
        if ($type === DictionaryType::Branches && $item->cityExternalId !== null) {
            $cityId = City::query()->where('external_id', $item->cityExternalId)->value('id');
            if ($cityId !== null) {
                $attributes['city_id'] = $cityId;
            }
        }

        $existing = $this->query($type)->where('external_id', $item->externalId)->first();
        if ($existing === null) {
            $this->query($type)->create($attributes + ['external_id' => $item->externalId]);

            return UpsertOutcome::Created;
        }

        $existing->fill($attributes);
        if (! $existing->isDirty()) {
            return UpsertOutcome::Unchanged;
        }
        $existing->save();

        return UpsertOutcome::Updated;
    }

    public function activeBranchIdsOfUser(int $userId): array
    {
        return DB::table('branch_user')
            ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branch_user.user_id', $userId)
            ->where('branches.status', DirectoryStatus::Active->value)
            ->orderBy('branches.id')
            ->pluck('branches.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }

    /** @return Builder<DictionaryItem> */
    private function query(DictionaryType $type): Builder
    {
        $class = $type->modelClass();

        return $class::query();
    }
}
