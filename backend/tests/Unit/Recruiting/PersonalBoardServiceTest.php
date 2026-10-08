<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Recruiting\Contracts\PersonalBoardRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\BoardColumn;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Repositories\EloquentPersonalBoardRepository;
use App\Modules\Recruiting\Services\PersonalBoardService;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** PersonalBoardService keeps the layout rules; storage is PersonalBoardRepository + PipelineRepository (no DB). */
final class PersonalBoardServiceTest extends TestCase
{
    private PersonalBoardRepository&MockInterface $boards;

    private PipelineRepository&MockInterface $pipelines;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var PersonalBoardRepository&MockInterface $boards */
        $boards = Mockery::mock(PersonalBoardRepository::class);
        /** @var PipelineRepository&MockInterface $pipelines */
        $pipelines = Mockery::mock(PipelineRepository::class);
        $this->boards = $boards;
        $this->pipelines = $pipelines;
        $this->pipelines->allows('stageIds')->with(4)->andReturn([10, 11, 12]);
    }

    public function test_the_contract_is_bound_to_the_eloquent_repository(): void
    {
        $this->assertInstanceOf(EloquentPersonalBoardRepository::class, $this->app->make(PersonalBoardRepository::class));
    }

    public function test_layout_is_repaired_against_current_stages_and_columns(): void
    {
        $this->boards->allows('columns')->with(1, 2)->andReturn($this->columns(7, 8));
        $this->boards->allows('cards')->andReturn(new Collection);
        // Stage 11 was added to the funnel later, column 9 and stage 99 are gone, column 8 is new.
        $this->boards->allows('savedLayout')->with(1, 2)->andReturn(['col:9', 'stage:10', 'col:7', 'stage:12', 'stage:99']);

        $board = $this->service()->board($this->user(), $this->vacancy());

        $this->assertSame(['stage:10', 'stage:11', 'col:7', 'stage:12', 'col:8'], $board['layout']);
    }

    public function test_saving_a_layout_that_reorders_stages_is_refused(): void
    {
        $this->boards->allows('columns')->andReturn($this->columns(7));
        $this->boards->expects('storeLayout')->never();

        $this->expectException(RecruitingException::class);
        $this->service()->saveLayout($this->user(), $this->vacancy(), ['stage:11', 'stage:10', 'stage:12', 'col:7']);
    }

    public function test_a_new_column_goes_after_the_last_one(): void
    {
        $this->boards->allows('columnCount')->with(1, 2)->andReturn(3);
        $this->boards->allows('maxColumnPosition')->with(1, 2)->andReturn(5);
        $this->boards->expects('createColumn')->with(Mockery::on(static fn (array $a): bool => $a['position'] === 6 && $a['user_id'] === 1))
            ->andReturn(new BoardColumn);

        $this->service()->create($this->user(), $this->vacancy(), 'Call back', null);
    }

    public function test_no_column_over_the_limit(): void
    {
        $this->boards->allows('columnCount')->andReturn(PersonalBoardService::MAX_COLUMNS);
        $this->boards->expects('createColumn')->never();

        $this->expectException(RecruitingException::class);
        $this->service()->create($this->user(), $this->vacancy(), 'One more', null);
    }

    private function service(): PersonalBoardService
    {
        return new PersonalBoardService($this->boards, $this->pipelines);
    }

    /** @return Collection<int, BoardColumn> */
    private function columns(int ...$ids): Collection
    {
        return new Collection(array_values(array_map(static function (int $id): BoardColumn {
            $column = new BoardColumn;
            $column->id = $id;

            return $column;
        }, $ids)));
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 1;

        return $user;
    }

    private function vacancy(): Vacancy
    {
        $vacancy = new Vacancy;
        $vacancy->id = 2;
        $vacancy->pipeline_id = 4;

        return $vacancy;
    }
}
