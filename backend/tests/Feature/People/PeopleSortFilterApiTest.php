<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** GET /api/people: sortable / filterable table headers (sort, dir, name, contact, manager). Synthetic data only. */
final class PeopleSortFilterApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        /** @var list<string> $names */
        $names = array_column((array) $response->assertOk()->json('data'), 'full_name');

        return $names;
    }

    public function test_sorts_by_name_and_reverses_with_dir_desc(): void
    {
        foreach (['Bravo Person', 'Alpha Person', 'Charlie Person'] as $name) {
            $this->employee(['full_name' => $name]);
        }
        $admin = $this->login(UserRole::Admin);

        $this->assertSame(['Alpha Person', 'Bravo Person', 'Charlie Person'], $this->names($this->actingAs($admin)->getJson('/api/people')));
        $this->assertSame(['Alpha Person', 'Bravo Person', 'Charlie Person'], $this->names($this->actingAs($admin)->getJson('/api/people?sort=name&dir=asc')));
        $this->assertSame(['Charlie Person', 'Bravo Person', 'Alpha Person'], $this->names($this->actingAs($admin)->getJson('/api/people?sort=name&dir=desc')));
    }

    public function test_sorts_by_a_related_name_with_empty_values_last_in_both_directions(): void
    {
        $analyst = Position::factory()->create(['name' => 'Analyst']);
        $zoologist = Position::factory()->create(['name' => 'Zoologist']);
        $this->employee(['full_name' => 'No Position']);
        $this->employee(['full_name' => 'Zed Analyst', 'position_id' => $analyst->id]);
        $this->employee(['full_name' => 'Amy Zoologist', 'position_id' => $zoologist->id]);
        $this->employee(['full_name' => 'Abe Analyst', 'position_id' => $analyst->id]);
        $admin = $this->login(UserRole::Admin);

        // Ties inside one position go by name; the row without a position stays last (Postgres would put NULL first on DESC).
        $this->assertSame(
            ['Abe Analyst', 'Zed Analyst', 'Amy Zoologist', 'No Position'],
            $this->names($this->actingAs($admin)->getJson('/api/people?sort=position')),
        );
        $this->assertSame(
            ['Amy Zoologist', 'Abe Analyst', 'Zed Analyst', 'No Position'],
            $this->names($this->actingAs($admin)->getJson('/api/people?sort=position&dir=desc')),
        );
        $this->actingAs($admin)->getJson('/api/people?sort=position&dir=desc&perPage=1&page=4')->assertOk()
            ->assertJsonPath('data.0.full_name', 'No Position');
        // The subquery sort key keeps the same order on the first page.
        $this->actingAs($admin)->getJson('/api/people?sort=position&perPage=1&page=1')->assertOk()
            ->assertJsonPath('data.0.full_name', 'Abe Analyst');
    }

    public function test_sorts_by_manager_name(): void
    {
        $org = $this->org(); // head ← lead ← worker, peer; other without a manager
        $admin = $this->login(UserRole::Admin);

        $names = $this->names($this->actingAs($admin)->getJson('/api/people?sort=manager&dir=desc'));
        // Managers desc: Lead Person (peer, worker), Head Person (lead), then the people without one (head, other).
        $this->assertSame(['Peer Person', 'Worker Person', 'Lead Person', 'Head Person', 'Other Person'], $names);
        $this->assertSame($org['peer']->full_name, $names[0]);
    }

    public function test_column_filters_are_case_insensitive_contains_and_combine_with_paging(): void
    {
        $this->org();
        $this->employee(['full_name' => 'Mail Owner', 'work_email' => 'unique.box@example.test', 'phone' => '+380441112233']);
        $viewer = $this->login(UserRole::Viewer);

        $this->assertSame(['Worker Person'], $this->names($this->actingAs($viewer)->getJson('/api/people?name=WORK')));
        $this->assertSame(['Mail Owner'], $this->names($this->actingAs($viewer)->getJson('/api/people?contact=Unique.Box')));
        $this->assertSame(['Mail Owner'], $this->names($this->actingAs($viewer)->getJson('/api/people?contact=4411122')));
        $this->assertSame(['Peer Person', 'Worker Person'], $this->names($this->actingAs($viewer)->getJson('/api/people?manager=lead')));
        // perPage arrives as a query STRING ("1") and still pages; the meta numbers are integers.
        $this->actingAs($viewer)->getJson('/api/people?manager=lead&sort=name&dir=desc&perPage=1&page=2')->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.full_name', 'Peer Person');
        // LIKE wildcards are text, not patterns.
        $this->assertSame([], $this->names($this->actingAs($viewer)->getJson('/api/people?name=%25')));
        $this->assertSame([], $this->names($this->actingAs($viewer)->getJson('/api/people?name=_')));
    }

    public function test_a_filter_value_of_zero_is_a_real_filter(): void
    {
        $none = ['work_email' => null, 'phone' => null]; // no digits outside the names
        $agent = $this->employee(['full_name' => 'Agent 0'] + $none);
        $this->employee(['full_name' => 'Agent One'] + $none);
        $this->employee(['full_name' => 'Report Person', 'manager_id' => $agent->id] + $none);
        $admin = $this->login(UserRole::Admin);

        // "0" is falsy in PHP: a truthy check would drop the filter and return everybody.
        $this->assertSame(['Agent 0'], $this->names($this->actingAs($admin)->getJson('/api/people?name=0')));
        $this->assertSame(['Report Person'], $this->names($this->actingAs($admin)->getJson('/api/people?manager=0')));
        $this->assertSame(['Agent 0'], $this->names($this->actingAs($admin)->getJson('/api/people?q=0')));
    }

    public function test_unknown_sort_column_or_direction_is_422(): void
    {
        $this->employee(['full_name' => 'Anyone']);
        $admin = $this->login(UserRole::Admin);

        foreach ([
            'sort=full_name',
            'sort=id',
            'sort='.rawurlencode('name; drop table employees'),
            'sort='.rawurlencode('(select 1)'),
            'sort[]=name',
            'dir=up',
            'dir='.rawurlencode('desc nulls first'),
            'name='.str_repeat('a', 101),
        ] as $query) {
            $this->actingAs($admin)->getJson("/api/people?$query")->assertUnprocessable();
        }
        $this->actingAs($admin)->getJson('/api/people')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_sorting_and_filters_keep_the_terminated_scope(): void
    {
        $org = $this->org();
        Employee::factory()->terminated()->create(['full_name' => 'Gone Worker', 'manager_id' => $org['lead']->id]);
        $peer = $this->userOf($org['peer']);

        // Without status nobody gets terminated people, whatever the sort or filter.
        $this->assertSame([], $this->names($this->actingAs($peer)->getJson('/api/people?name=gone&sort=manager&dir=desc')));
        // status=terminated: an unrelated employee sees nothing, the manager above sees their former report.
        $this->actingAs($peer)->getJson('/api/people?status=terminated&sort=name&dir=desc')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame(['Gone Worker'], $this->names($this->actingAs($this->userOf($org['lead']))->getJson('/api/people?status=terminated&manager=lead&sort=position')));
    }
}
