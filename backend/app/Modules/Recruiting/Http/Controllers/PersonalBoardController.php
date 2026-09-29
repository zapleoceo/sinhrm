<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Controllers;

use App\Models\User;
use App\Modules\Recruiting\Http\Requests\BoardColumnRequest;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardColumn;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\PersonalBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Personal columns of the /candidates board (per user and vacancy). Reading and filing need only "view" of the
 * vacancy — the same check as GET /vacancies/{id}/board, which supplies the cards; stage moves stay on
 * POST /applications/{id}/move with its own policy.
 */
final class PersonalBoardController
{
    public function __construct(private readonly PersonalBoardService $board) {}

    public function show(Request $request, Vacancy $vacancy): JsonResponse
    {
        $actor = $this->viewer($request, $vacancy);
        $board = $this->board->board($actor, $vacancy);

        return new JsonResponse(['data' => [
            'columns' => $board['columns']->map(fn (BoardColumn $c): array => $this->column($c))->values()->all(),
            'cards' => $board['cards'],
            'layout' => $board['layout'],
        ]]);
    }

    public function store(BoardColumnRequest $request, Vacancy $vacancy): JsonResponse
    {
        $data = $request->columnData();
        $column = $this->board->create($this->viewer($request, $vacancy), $vacancy, (string) $data['title'], $data['color'] ?? null);

        return new JsonResponse(['data' => $this->column($column)], 201);
    }

    public function update(BoardColumnRequest $request, int $column): JsonResponse
    {
        return new JsonResponse(['data' => $this->column($this->board->update($this->actor($request), $column, $request->columnData()))]);
    }

    public function destroy(Request $request, int $column): Response
    {
        $this->board->delete($this->actor($request), $column);

        return response()->noContent();
    }

    /** PUT {keys: ["stage:3", "col:7", ...]} — the combined column order; answers with the stored layout. */
    public function layout(Request $request, Vacancy $vacancy): JsonResponse
    {
        $keys = $request->validate([
            'keys' => ['present', 'array', 'max:'.PersonalBoardService::MAX_LAYOUT_KEYS],
            'keys.*' => ['string', 'regex:/^(stage|col):[1-9][0-9]{0,18}$/'],
        ])['keys'];

        return new JsonResponse(['data' => ['layout' => $this->board->saveLayout($this->viewer($request, $vacancy), $vacancy, array_values(array_map('strval', $keys)))]]);
    }

    /** DELETE — back to the shared stages only. */
    public function reset(Request $request, Vacancy $vacancy): Response
    {
        $this->board->reset($this->viewer($request, $vacancy), $vacancy);

        return response()->noContent();
    }

    /** PUT /applications/{application}/personal-column {column_id: int|null}. Never changes the stage. */
    public function file(Request $request, Application $application): Response
    {
        $columnId = $request->validate(['column_id' => ['present', 'nullable', 'integer', 'min:1']])['column_id'];
        $this->board->file($this->viewer($request, $application->vacancy), $application, $columnId === null ? null : (int) $columnId);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function column(BoardColumn $c): array
    {
        return ['id' => $c->id, 'title' => $c->title, 'color' => $c->color, 'position' => $c->position, 'hidden' => $c->hidden];
    }

    private function viewer(Request $request, Vacancy $vacancy): User
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $vacancy);

        return $actor;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
