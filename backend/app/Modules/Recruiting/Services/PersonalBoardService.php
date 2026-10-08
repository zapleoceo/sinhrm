<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\PersonalBoardRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardCard;
use App\Modules\Recruiting\Models\BoardColumn;
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

    /** Upper bound on keys in one saved layout (funnel stages + own columns), a request-size guard. */
    public const int MAX_LAYOUT_KEYS = 100;

    public function __construct(private PersonalBoardRepository $boards, private PipelineRepository $pipelines) {}

    /** @return array{columns: Collection<int, BoardColumn>, cards: list<array{application_id: int, column_id: int}>, layout: list<string>} */
    public function board(User $user, Vacancy $vacancy): array
    {
        $columns = $this->columns($user, $vacancy);
        $cards = $this->boards->cards($user->id, $columns->modelKeys(), $vacancy->id)
            ->map(static fn (BoardCard $c): array => ['application_id' => $c->application_id, 'column_id' => $c->column_id])
            ->all();

        return ['columns' => $columns, 'cards' => array_values($cards), 'layout' => $this->layout($user, $vacancy, $columns)];
    }

    public function create(User $user, Vacancy $vacancy, string $title, ?string $color): BoardColumn
    {
        if ($this->boards->columnCount($user->id, $vacancy->id) >= self::MAX_COLUMNS) {
            throw RecruitingException::boardColumnLimit(self::MAX_COLUMNS);
        }

        return $this->boards->createColumn([
            'user_id' => $user->id,
            'scope_vacancy_id' => $vacancy->id,
            'title' => $title,
            'color' => $color,
            'position' => $this->boards->maxColumnPosition($user->id, $vacancy->id) + 1,
        ]);
    }

    /** @param  array<string, mixed>  $attributes  title, color, hidden (validated) */
    public function update(User $user, int $columnId, array $attributes): BoardColumn
    {
        $column = $this->own($user, $columnId);
        $this->boards->updateColumn($column, $attributes);

        return $column;
    }

    /** Its cards simply go back to their real stage column (the card rows cascade). */
    public function delete(User $user, int $columnId): void
    {
        $this->boards->deleteColumn($this->own($user, $columnId));
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
        // One atomic INSERT .. ON CONFLICT on the (user_id, vacancy_id) unique key: two first saves at once
        // (two tabs, a double drop) no longer race a SELECT-then-INSERT into a unique violation. The last write wins.
        $this->boards->storeLayout($user->id, $vacancy->id, $keys);

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
        $saved = $this->boards->savedLayout($user->id, $vacancy->id);
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
        return array_map(static fn (int $id): string => 'stage:'.$id, $this->pipelines->stageIds($vacancy->pipeline_id));
    }

    /** Back to the defaults: only the shared stages (all own columns of the vacancy and their cards go). */
    public function reset(User $user, Vacancy $vacancy): void
    {
        $this->boards->reset($user->id, $vacancy->id);
    }

    /** Files the card into an own column of the same vacancy, or (null) back to its stage column. Stage untouched. */
    public function file(User $user, Application $application, ?int $columnId): void
    {
        if ($columnId === null) {
            $this->boards->unfileCard($user->id, $application->id);

            return;
        }
        $column = $this->own($user, $columnId);
        if ($column->scope_vacancy_id !== $application->vacancy_id) {
            throw RecruitingException::boardColumnMismatch();
        }
        $this->boards->fileCard($user->id, $application->id, $column->id);
    }

    /** @return Collection<int, BoardColumn> */
    private function columns(User $user, Vacancy $vacancy): Collection
    {
        return $this->boards->columns($user->id, $vacancy->id);
    }

    private function own(User $user, int $columnId): BoardColumn
    {
        return $this->boards->ownColumn($user->id, $columnId);
    }
}
