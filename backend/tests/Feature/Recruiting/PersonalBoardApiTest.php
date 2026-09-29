<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\BoardCard;
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
        $this->actingAs($this->recruiter)->getJson($url)->assertOk()->assertExactJson(['data' => ['columns' => [], 'cards' => []]]);

        $id = $this->column($this->recruiter);
        $this->actingAs($this->recruiter)->postJson("$url/columns", ['title' => str_repeat('я', 41)])->assertUnprocessable();
        $this->actingAs($this->recruiter)->postJson("$url/columns", ['title' => 'X', 'color' => '#fff'])->assertUnprocessable();
        $this->actingAs($this->recruiter)->patchJson("/api/personal-board/columns/$id", ['title' => 'Топ', 'hidden' => '1'])
            ->assertOk()->assertJsonPath('data.title', 'Топ')->assertJsonPath('data.hidden', true);

        $second = $this->column($this->recruiter, 'Чекаю резюме');
        $this->actingAs($this->recruiter)->putJson("$url/columns/order", ['ids' => [$second, $id]])->assertNoContent();
        $this->actingAs($this->recruiter)->getJson($url)->assertJsonPath('data.columns.0.id', $second);
        $this->actingAs($this->recruiter)->putJson("$url/columns/order", ['ids' => [$id]])
            ->assertUnprocessable()->assertJsonPath('code', 'board_column_mismatch');

        for ($i = 3; $i <= PersonalBoardService::MAX_COLUMNS; $i++) {
            $this->column($this->recruiter, "C$i");
        }
        $this->actingAs($this->recruiter)->postJson("$url/columns", ['title' => 'One too many'])
            ->assertUnprocessable()->assertJsonPath('code', 'board_column_limit');

        $this->actingAs($this->recruiter)->deleteJson($url)->assertNoContent();
        $this->actingAs($this->recruiter)->getJson($url)->assertJsonCount(0, 'data.columns');
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
