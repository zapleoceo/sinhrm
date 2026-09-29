<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardCard;
use App\Modules\Recruiting\Models\BoardLayout;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\PersonalBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class PersonalBoardApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $branch;

    private Vacancy $vacancy;

    private Application $application;

    private User $recruiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->vacancy = $this->vacancyIn($this->branch);
        $this->application = $this->applied($this->vacancy);
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
    }

    private function column(User $user, string $title = 'Перезвонити у пт', ?Vacancy $vacancy = null): int
    {
        $vacancy ??= $this->vacancy;

        return (int) $this->actingAs($user)->postJson("/api/vacancies/{$vacancy->id}/personal-board/columns", ['title' => $title, 'color' => 'amber'])
            ->assertCreated()->json('data.id');
    }

    public function test_columns_crud_limits_and_reset(): void
    {
        $url = "/api/vacancies/{$this->vacancy->id}/personal-board";
        $this->actingAs($this->recruiter)->getJson($url)->assertOk()->assertJsonPath('data.columns', [])->assertJsonPath('data.cards', []);

        $id = $this->column($this->recruiter);
        $this->actingAs($this->recruiter)->postJson("$url/columns", ['title' => str_repeat('я', 41)])->assertUnprocessable();
        $this->actingAs($this->recruiter)->postJson("$url/columns", ['title' => 'X', 'color' => '#fff'])->assertUnprocessable();
        $this->actingAs($this->recruiter)->patchJson("/api/personal-board/columns/$id", ['title' => 'Топ', 'hidden' => '1'])
            ->assertOk()->assertJsonPath('data.title', 'Топ')->assertJsonPath('data.hidden', true);

        $second = $this->column($this->recruiter, 'Чекаю резюме');
        for ($i = 3; $i <= PersonalBoardService::MAX_COLUMNS; $i++) {
            $this->column($this->recruiter, "C$i");
        }
        $this->actingAs($this->recruiter)->postJson("$url/columns", ['title' => 'One too many'])
            ->assertUnprocessable()->assertJsonPath('code', 'board_column_limit');

        $this->actingAs($this->recruiter)->deleteJson($url)->assertNoContent();
        $this->actingAs($this->recruiter)->getJson($url)->assertJsonCount(0, 'data.columns');
    }

    public function test_layout_mixes_own_columns_between_stages_and_keeps_the_funnel_order(): void
    {
        $url = "/api/vacancies/{$this->vacancy->id}/personal-board";
        $stages = $this->defaultPipeline()->stages()->pluck('id')->map(static fn (int $id): string => "stage:$id")->all();
        $this->actingAs($this->recruiter)->getJson($url)->assertJsonPath('data.layout', $stages);
        $id = $this->column($this->recruiter);
        $this->actingAs($this->recruiter)->getJson($url)->assertJsonPath('data.layout', [...$stages, "col:$id"]);

        // Own column before the first stage and between stages.
        $second = $this->column($this->recruiter, 'Топ');
        $keys = ["col:$id", $stages[0], "col:$second", ...array_slice($stages, 1)];
        $this->actingAs($this->recruiter)->putJson("$url/layout", ['keys' => $keys])->assertOk()->assertJsonPath('data.layout', $keys);
        $this->actingAs($this->recruiter)->getJson($url)->assertJsonPath('data.layout', $keys);

        // Stages may not be reordered among themselves; unknown, repeated and foreign keys are refused.
        $swapped = $keys;
        [$swapped[1], $swapped[3]] = [$swapped[3], $swapped[1]];
        $this->actingAs($this->recruiter)->putJson("$url/layout", ['keys' => $swapped])->assertUnprocessable()->assertJsonPath('code', 'board_layout_stage_order');
        $foreign = $this->column($this->userWith(UserRole::Recruiter, [$this->branch]));
        foreach ([["col:$foreign"], ['stage:999999'], ["col:$id", "col:$id"]] as $bad) {
            $this->actingAs($this->recruiter)->putJson("$url/layout", ['keys' => $bad])->assertUnprocessable()->assertJsonPath('code', 'board_layout_invalid');
        }
        $this->actingAs($this->recruiter)->putJson("$url/layout", ['keys' => ['evil']])->assertUnprocessable();

        // A stage added to the funnel later appears right after its previous stage; a deleted column just drops out.
        $this->defaultPipeline()->stages()->getQuery()->where('position', '>=', 3)->increment('position', 100);
        $new = $this->defaultPipeline()->stages()->create(['name' => 'Нова', 'kind' => 'select', 'position' => 3]);
        $first = $this->defaultPipeline()->stages()->orderBy('position')->orderBy('id')->pluck('id')->all();
        $this->actingAs($this->recruiter)->deleteJson("/api/personal-board/columns/$id")->assertNoContent();
        $layout = $this->actingAs($this->recruiter)->getJson($url)->json('data.layout');
        $this->assertNotContains("col:$id", $layout);
        $at = array_search("stage:{$new->id}", $layout, true);
        $prev = $first[array_search($new->id, $first, true) - 1];
        $this->assertSame("stage:$prev", $layout[$at - 1]);
    }

    public function test_concurrent_first_layout_saves_do_not_fail(): void
    {
        $url = "/api/vacancies/{$this->vacancy->id}/personal-board";
        $stages = $this->actingAs($this->recruiter)->getJson($url)->json('data.layout');
        $this->assertIsArray($stages);
        // The other request's first save lands between our "no layout yet" read and our insert: with the old
        // SELECT-then-INSERT this row made our INSERT hit the (user_id, vacancy_id) unique key -> 500.
        BoardLayout::creating(function (): void {
            BoardLayout::query()->insert(['user_id' => $this->recruiter->id, 'vacancy_id' => $this->vacancy->id, 'keys' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->actingAs($this->recruiter)->putJson("$url/layout", ['keys' => $stages])->assertOk()->assertJsonPath('data.layout', $stages);
        $this->actingAs($this->recruiter)->putJson("$url/layout", ['keys' => $stages])->assertOk();
        $this->assertSame(1, BoardLayout::query()->where('user_id', $this->recruiter->id)->count());
        $this->assertSame($stages, BoardLayout::query()->where('user_id', $this->recruiter->id)->sole()->keys);
    }

    public function test_layout_size_is_capped(): void
    {
        $keys = array_map(static fn (int $i): string => 'col:'.$i, range(1, PersonalBoardService::MAX_LAYOUT_KEYS + 1));
        $this->actingAs($this->recruiter)->putJson("/api/vacancies/{$this->vacancy->id}/personal-board/layout", ['keys' => $keys])
            ->assertUnprocessable()->assertJsonValidationErrors('keys');
    }

    public function test_personal_move_never_changes_the_stage_and_delete_returns_the_card(): void
    {
        $stageBefore = $this->application->stage_id;
        $changes = StageChange::query()->count();
        $id = $this->column($this->recruiter);

        $this->actingAs($this->recruiter)->putJson("/api/applications/{$this->application->id}/personal-column", ['column_id' => (string) $id])
            ->assertNoContent();
        $this->actingAs($this->recruiter)->getJson("/api/vacancies/{$this->vacancy->id}/personal-board")
            ->assertJsonPath('data.cards', [['application_id' => $this->application->id, 'column_id' => $id]]);
        $this->assertSame($stageBefore, $this->application->fresh()?->stage_id);
        $this->assertSame($changes, StageChange::query()->count());

        $this->actingAs($this->recruiter)->deleteJson("/api/personal-board/columns/$id")->assertNoContent();
        $this->assertSame(0, BoardCard::query()->count());
        $this->assertSame($stageBefore, $this->application->fresh()?->stage_id);
    }

    public function test_shared_move_still_goes_through_the_move_policy(): void
    {
        $viewer = $this->userWith(UserRole::Viewer, [$this->branch]);
        $id = $this->column($viewer);

        // A viewer may organise his own view but cannot move the candidate through the funnel.
        $this->actingAs($viewer)->putJson("/api/applications/{$this->application->id}/personal-column", ['column_id' => $id])->assertNoContent();
        $this->actingAs($viewer)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $this->stageAt(2)->id])->assertForbidden();
        $this->actingAs($this->recruiter)->postJson("/api/applications/{$this->application->id}/move", ['stage_id' => $this->stageAt(2)->id])
            ->assertOk()->assertJsonPath('data.stage_id', $this->stageAt(2)->id);
    }

    public function test_no_idor_and_scope_parity_with_the_vacancy_board(): void
    {
        $other = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $foreign = $this->column($other);

        $this->actingAs($this->recruiter)->patchJson("/api/personal-board/columns/$foreign", ['title' => 'mine'])->assertNotFound();
        $this->actingAs($this->recruiter)->deleteJson("/api/personal-board/columns/$foreign")->assertNotFound();
        $this->actingAs($this->recruiter)->putJson("/api/applications/{$this->application->id}/personal-column", ['column_id' => $foreign])->assertNotFound();
        $this->actingAs($this->recruiter)->getJson("/api/vacancies/{$this->vacancy->id}/personal-board")->assertJsonCount(0, 'data.columns');

        // A column of another vacancy cannot hold this card.
        $otherVacancy = $this->vacancyIn($this->branch);
        $elsewhere = $this->column($this->recruiter, 'Other', $otherVacancy);
        $this->actingAs($this->recruiter)->putJson("/api/applications/{$this->application->id}/personal-column", ['column_id' => $elsewhere])
            ->assertUnprocessable()->assertJsonPath('code', 'board_column_mismatch');

        // Out of branch scope: the same 403 as the shared board, for reading and for filing.
        $outsider = $this->userWith(UserRole::Recruiter, [Branch::factory()->create()]);
        $this->actingAs($outsider)->getJson("/api/vacancies/{$this->vacancy->id}/board")->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/vacancies/{$this->vacancy->id}/personal-board")->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/vacancies/{$this->vacancy->id}/personal-board/columns", ['title' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->putJson("/api/applications/{$this->application->id}/personal-column", ['column_id' => null])->assertForbidden();
    }
}
