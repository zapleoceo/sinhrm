<?php

declare(strict_types=1);

namespace App\Modules\Directory\Repositories;

use App\Modules\Core\Support\Database\Like;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\DTO\DictionaryFilter;
use App\Modules\Directory\Enums\DictionarySort;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\DictionaryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentDictionaryRepository implements DictionaryRepository
{
    public function paginate(DictionaryType $type, DictionaryFilter $filter): LengthAwarePaginator
    {
        $branches = $type === DictionaryType::Branches;
        $query = $this->query($type)
            ->when($branches, fn (Builder $query) => $query->with('city'))
            // !== null, not truthy: a search for "0" is a real search.
            ->when($filter->q !== null, function (Builder $query) use ($filter): void {
                $like = Like::contains(mb_strtolower((string) $filter->q));
                $query->whereRaw('lower(name) like ?', [$like]);
            })
            ->when($filter->status, fn (Builder $query, DirectoryStatus $s) => $query->where('status', $s->value))
            // City exists on branches only (the request refuses city_id / sort=city elsewhere).
            ->when($branches && $filter->cityId !== null, fn (Builder $query) => $query->where('city_id', $filter->cityId));
        self::sort($query, $branches ? $filter->sort : self::withoutCity($filter->sort), $filter->descending);

        return $query->paginate($filter->perPage);
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

    /**
     * ORDER BY of a whitelisted column: column names and direction are literals, never request text. Branch city
     * comes from a correlated subquery (no join: the selected columns and the count stay as they are); branches
     * without a city stay last in both directions — Postgres would put NULLs first on DESC. Ties: by name, then id.
     *
     * @param  Builder<DictionaryItem>  $query
     */
    private static function sort(Builder $query, DictionarySort $sort, bool $descending): void
    {
        $column = match ($sort) {
            DictionarySort::Name => 'name',
            DictionarySort::Status => 'status',
            DictionarySort::City => '(select cities.name from cities where cities.id = branches.city_id)',
        };
        $query->orderByRaw($column.' '.($descending ? 'desc' : 'asc').' nulls last')->orderBy('name')->orderBy('id');
    }

    private static function withoutCity(DictionarySort $sort): DictionarySort
    {
        return $sort === DictionarySort::City ? DictionarySort::Name : $sort;
    }

    /** @return Builder<DictionaryItem> */
    private function query(DictionaryType $type): Builder
    {
        $class = $type->modelClass();

        return $class::query();
    }
}
