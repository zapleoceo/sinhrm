<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardCard;
use App\Modules\Recruiting\Models\BoardColumn;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The user's own columns on the /candidates board of one vacancy. Only view state: the funnel stage changes only
 * through ApplicationService::move (POST /applications/{id}/move). Every lookup is by (user, id), so another user's
 * column is simply "not found" (no IDOR).
 */
final readonly class PersonalBoardService
{
    public const int MAX_COLUMNS = 12;

    /** @return array{columns: Collection<int, BoardColumn>, cards: list<array{application_id: int, column_id: int}>} */
    public function board(User $user, Vacancy $vacancy): array
    {
        $columns = $this->columns($user, $vacancy);
        $cards = BoardCard::query()
            ->where('user_id', $user->id)
            ->whereIn('column_id', $columns->modelKeys())
            ->whereIn('application_id', Application::query()->select('id')->where('vacancy_id', $vacancy->id))
            ->orderBy('id')
            ->get()
            ->map(static fn (BoardCard $c): array => ['application_id' => $c->application_id, 'column_id' => $c->column_id])
            ->all();

        return ['columns' => $columns, 'cards' => array_values($cards)];
    }

    public function create(User $user, Vacancy $vacancy, string $title, ?string $color): BoardColumn
    {
        $query = BoardColumn::query()->where('user_id', $user->id)->where('scope_vacancy_id', $vacancy->id);
        if ($query->count() >= self::MAX_COLUMNS) {
            throw RecruitingException::boardColumnLimit(self::MAX_COLUMNS);
        }

        return BoardColumn::query()->create([
            'user_id' => $user->id,
            'scope_vacancy_id' => $vacancy->id,
            'title' => $title,
            'color' => $color,
            'position' => (int) $query->max('position') + 1,
        ]);
    }

    /** @param  array<string, mixed>  $attributes  title, color, hidden (validated) */
    public function update(User $user, int $columnId, array $attributes): BoardColumn
    {
        $column = $this->own($user, $columnId);
        $column->fill($attributes)->save();

        return $column;
    }

    /** Its cards simply go back to their real stage column (the card rows cascade). */
    public function delete(User $user, int $columnId): void
    {
        $this->own($user, $columnId)->delete();
    }

    /** @param  list<int>  $ids  all of the user's columns of the vacancy, in the new order */
    public function reorder(User $user, Vacancy $vacancy, array $ids): void
    {
        $known = $this->columns($user, $vacancy)->modelKeys();
        $given = $ids;
        sort($known);
        sort($given);
        if ($known !== $given) {
            throw RecruitingException::boardColumnMismatch();
        }
        DB::transaction(function () use ($user, $ids): void {
            foreach ($ids as $position => $id) {
                BoardColumn::query()->where('user_id', $user->id)->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /** Back to the defaults: only the shared stages (all own columns of the vacancy and their cards go). */
    public function reset(User $user, Vacancy $vacancy): void
    {
        BoardColumn::query()->where('user_id', $user->id)->where('scope_vacancy_id', $vacancy->id)->delete();
    }

    /** Files the card into an own column of the same vacancy, or (null) back to its stage column. Stage untouched. */
    public function file(User $user, Application $application, ?int $columnId): void
    {
        if ($columnId === null) {
            BoardCard::query()->where('user_id', $user->id)->where('application_id', $application->id)->delete();

            return;
        }
        $column = $this->own($user, $columnId);
        if ($column->scope_vacancy_id !== $application->vacancy_id) {
            throw RecruitingException::boardColumnMismatch();
        }
        BoardCard::query()->updateOrCreate(
            ['user_id' => $user->id, 'application_id' => $application->id],
            ['column_id' => $column->id],
        );
    }

    /** @return Collection<int, BoardColumn> */
    private function columns(User $user, Vacancy $vacancy): Collection
    {
        return BoardColumn::query()->where('user_id', $user->id)->where('scope_vacancy_id', $vacancy->id)
            ->orderBy('position')->orderBy('id')->get();
    }

    private function own(User $user, int $columnId): BoardColumn
    {
        return BoardColumn::query()->where('user_id', $user->id)->findOrFail($columnId);
    }
}
