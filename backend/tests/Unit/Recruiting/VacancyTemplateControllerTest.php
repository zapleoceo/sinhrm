<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\Http\Controllers\VacancyTemplateController;
use App\Modules\Recruiting\Models\VacancyTemplate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** Vacancy templates are read and written through VacancyRepository, the controller only orchestrates (no DB). */
final class VacancyTemplateControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The policy itself is covered by VacancyFormApiTest; here every check passes.
        $gate = Mockery::mock(GateContract::class);
        $gate->shouldReceive('authorize');
        $gate->shouldReceive('allows')->andReturn(true);
        Gate::shouldReceive('forUser')->andReturn($gate);
    }

    public function test_index_lists_the_repository_templates(): void
    {
        $template = new VacancyTemplate(['name' => 'Sales', 'data' => ['title' => 'Manager'], 'created_by' => 3]);
        $template->id = 11;
        /** @var VacancyRepository&MockInterface $repo */
        $repo = $this->mock(VacancyRepository::class);
        $repo->expects('templates')->with(200)->andReturn(new Collection([$template]));

        $response = $this->app->make(VacancyTemplateController::class)->index($this->request());

        $row = $response->getData(true)['data'][0];
        $this->assertSame([11, 'Sales', ['title' => 'Manager'], 3, true], [$row['id'], $row['name'], $row['data'], $row['created_by'], $row['can_manage']]);
    }

    public function test_destroy_deletes_through_the_repository(): void
    {
        $template = new VacancyTemplate(['name' => 'Old', 'data' => []]);
        /** @var VacancyRepository&MockInterface $repo */
        $repo = $this->mock(VacancyRepository::class);
        $repo->expects('deleteTemplate')->with($template);

        $response = $this->app->make(VacancyTemplateController::class)->destroy($this->request(), $template);

        $this->assertSame(204, $response->getStatusCode());
    }

    private function request(): Request
    {
        $user = new User;
        $user->id = 3;
        $request = Request::create('/api/recruiting/vacancy-templates');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
