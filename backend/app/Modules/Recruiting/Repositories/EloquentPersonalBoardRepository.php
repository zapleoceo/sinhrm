<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\PersonalBoardRepository;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardCard;
use App\Modules\Recruiting\Models\BoardColumn;
use App\Modules\Recruiting\Models\BoardLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentPersonalBoardRepository implements PersonalBoardRepository
{
    public function columns(int $userId, int $vacancyId): Collection
    {
        return $this->ownColumns($userId, $vacancyId)->orderBy('position')->orderBy('id')->get();
    }

    public function columnCount(int $userId, int $vacancyId): int
    {
        return $this->ownColumns($userId, $vacancyId)->count();
    }

    public function maxColumnPosition(int $userId, int $vacancyId): int
    {
        return (int) $this->ownColumns($userId, $vacancyId)->max('position');
    }

    public function createColumn(array $attributes): BoardColumn
    {
        return BoardColumn::query()->create($attributes);
    }

    public function ownColumn(int $userId, int $columnId): BoardColumn
    {
        return BoardColumn::query()->where('user_id', $userId)->findOrFail($columnId);
    }

    public function updateColumn(BoardColumn $column, array $attributes): void
    {
        $column->fill($attributes)->save();
    }

    public function deleteColumn(BoardColumn $column): void
    {
        $column->delete();
    }

    public function cards(int $userId, array $columnIds, int $vacancyId): Collection
    {
        return BoardCard::query()
            ->where('user_id', $userId)
            ->whereIn('column_id', $columnIds)
            ->whereIn('application_id', Application::query()->select('id')->where('vacancy_id', $vacancyId))
            ->orderBy('id')
            ->get();
    }

    public function fileCard(int $userId, int $applicationId, int $columnId): void
    {
        BoardCard::query()->updateOrCreate(
            ['user_id' => $userId, 'application_id' => $applicationId],
            ['column_id' => $columnId],
        );
    }

    public function unfileCard(int $userId, int $applicationId): void
    {
        BoardCard::query()->where('user_id', $userId)->where('application_id', $applicationId)->delete();
    }

    public function savedLayout(int $userId, int $vacancyId): array
    {
        return BoardLayout::query()->where('user_id', $userId)->where('vacancy_id', $vacancyId)->first()->keys ?? [];
    }

    public function storeLayout(int $userId, int $vacancyId, array $keys): void
    {
        BoardLayout::query()->upsert(
            [['user_id' => $userId, 'vacancy_id' => $vacancyId, 'keys' => json_encode($keys, JSON_THROW_ON_ERROR)]],
            ['user_id', 'vacancy_id'],
            ['keys'],
        );
    }

    public function reset(int $userId, int $vacancyId): void
    {
        $this->ownColumns($userId, $vacancyId)->delete();
        BoardLayout::query()->where('user_id', $userId)->where('vacancy_id', $vacancyId)->delete();
    }

    /** @return Builder<BoardColumn> */
    private function ownColumns(int $userId, int $vacancyId): Builder
    {
        return BoardColumn::query()->where('user_id', $userId)->where('scope_vacancy_id', $vacancyId);
    }
}
