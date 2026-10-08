<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\Models\BoardCard;
use App\Modules\Recruiting\Models\BoardColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A user's own view state on a vacancy board: columns (board_columns), filed cards (board_cards) and the saved
 * column order (board_layouts). Every lookup is by user, so another user's rows are never found.
 */
interface PersonalBoardRepository
{
    /** @return Collection<int, BoardColumn> own columns of the vacancy by position, id */
    public function columns(int $userId, int $vacancyId): Collection;

    public function columnCount(int $userId, int $vacancyId): int;

    /** The largest position among own columns of the vacancy (0 when none). */
    public function maxColumnPosition(int $userId, int $vacancyId): int;

    /** @param  array<string, mixed>  $attributes */
    public function createColumn(array $attributes): BoardColumn;

    /** @throws ModelNotFoundException another user's or a missing column */
    public function ownColumn(int $userId, int $columnId): BoardColumn;

    /** @param  array<string, mixed>  $attributes */
    public function updateColumn(BoardColumn $column, array $attributes): void;

    public function deleteColumn(BoardColumn $column): void;

    /**
     * Cards of the user filed into the given columns, only for applications of the vacancy, by id.
     *
     * @param  list<int>  $columnIds
     * @return Collection<int, BoardCard>
     */
    public function cards(int $userId, array $columnIds, int $vacancyId): Collection;

    /** Files the card (one per user and application) into the column. */
    public function fileCard(int $userId, int $applicationId, int $columnId): void;

    /** The card goes back to its stage column. */
    public function unfileCard(int $userId, int $applicationId): void;

    /** @return list<string> the stored column order, [] when never saved */
    public function savedLayout(int $userId, int $vacancyId): array;

    /**
     * One atomic upsert on the (user_id, vacancy_id) unique key: concurrent first saves do not race. Last write wins.
     *
     * @param  list<string>  $keys
     */
    public function storeLayout(int $userId, int $vacancyId, array $keys): void;

    /** Deletes the own columns (their cards cascade) and the saved order of the vacancy. */
    public function reset(int $userId, int $vacancyId): void;
}
