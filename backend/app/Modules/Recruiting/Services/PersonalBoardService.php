<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardCard;
use App\Modules\Recruiting\Models\BoardColumn;
use App\Modules\Recruiting\Models\BoardLayout;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Database\Eloquent\Collection;

/**
 * The user's own columns on the /candidates board of one vacancy. Only view state: the funnel stage changes only
 * through ApplicationService::move (POST /applications/{id}/move). Every lookup is by (user, id), so another user's
 * column is simply "not found" (no IDOR).
 */
final readonly class PersonalBoardService
{
    public const int MAX_COLUMNS = 12;

    /** @return array{columns: Collection<int, BoardColumn>, cards: list<array{application_id: int, column_id: int}>, layout: list<string>} */
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

        return ['columns' => $columns, 'cards' => array_values($cards), 'layout' => $this->layout($user, $vacancy, $columns)];
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

    /**
     * Saves the combined column order. Every key must exist (a stage of this vacancy's funnel or an own column of
     * this vacancy); stages must keep the funnel order among themselves. Missing keys are filled in by layout().
     *
     * @param  list<string>  $keys
     * @return list<string> the stored (normalized) layout
     */
    public function saveLayout(User $user, Vacancy $vacancy, array $keys): array
    {
        $columns = $this->columns($user, $vacancy);
        $stages = $this->stageKeys($vacancy);
        $known = array_merge($stages, array_map(static fn (int $id): string => 'col:'.$id, $columns->modelKeys()));
        if (array_diff($keys, $known) !== [] || count(array_unique($keys)) !== count($keys)) {
            throw RecruitingException::boardLayoutInvalid();
        }
        if (array_values(array_intersect($keys, $stages)) !== array_values(array_intersect($stages, $keys))) {
            throw RecruitingException::boardLayoutStageOrder();
        }
        BoardLayout::query()->updateOrCreate(
            ['user_id' => $user->id, 'vacancy_id' => $vacancy->id],
            ['keys' => $keys],
        );

        return $this->layout($user, $vacancy, $columns);
    }

    /**
     * The stored order, repaired against the current data: gone stages/columns are dropped, a stage added to the
     * funnel later lands right after its previous stage (the first one — at the start), new own columns go last.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @return list<string>
     */
    private function layout(User $user, Vacancy $vacancy, Collection $columns): array
    {
        $stages = $this->stageKeys($vacancy);
        $cols = array_map(static fn (int $id): string => 'col:'.$id, $columns->modelKeys());
        $saved = BoardLayout::query()->where('user_id', $user->id)->where('vacancy_id', $vacancy->id)->first()->keys ?? [];
        $keys = array_values(array_filter($saved, static fn (mixed $k): bool => in_array($k, $stages, true) || in_array($k, $cols, true)));
        foreach ($stages as $i => $stage) {
            if (! in_array($stage, $keys, true)) {
                $at = $i === 0 ? 0 : (int) array_search($stages[$i - 1], $keys, true) + 1;
                array_splice($keys, $at, 0, [$stage]);
            }
        }
        foreach ($cols as $col) {
            if (! in_array($col, $keys, true)) {
                $keys[] = $col;
            }
        }

        return $keys;
    }

    /** @return list<string> "stage:<id>" in funnel order */
    private function stageKeys(Vacancy $vacancy): array
    {
        return array_values(PipelineStage::query()->where('pipeline_id', $vacancy->pipeline_id)->orderBy('position')->orderBy('id')
            ->pluck('id')->map(static fn (int $id): string => 'stage:'.$id)->all());
    }

    /** Back to the defaults: only the shared stages (all own columns of the vacancy and their cards go). */
    public function reset(User $user, Vacancy $vacancy): void
    {
        BoardColumn::query()->where('user_id', $user->id)->where('scope_vacancy_id', $vacancy->id)->delete();
        BoardLayout::query()->where('user_id', $user->id)->where('vacancy_id', $vacancy->id)->delete();
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
