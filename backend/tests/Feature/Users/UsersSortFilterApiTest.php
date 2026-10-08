<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** GET /api/users: sortable / filterable table headers (sort, dir, q, role, status, last login range). Synthetic data. */
final class UsersSortFilterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create(['name' => 'Aaron Root', 'email' => 'aaron@example.test', 'last_login_at' => '2026-09-20 10:00:00']);
        User::factory()->withRole(UserRole::Viewer)->create(['name' => 'Bella Viewer', 'email' => 'bella@example.test', 'last_login_at' => '2026-09-25 09:00:00']);
        User::factory()->withRole(UserRole::Recruiter)->blocked()->create(['name' => 'Carl Recruiter', 'email' => 'carl@example.test', 'last_login_at' => null]);
        User::factory()->withRole(UserRole::Viewer)->create(['name' => 'Dina Viewer', 'email' => 'dina@example.test', 'last_login_at' => '2026-09-28 18:30:00']);
    }

    public function test_sorts_by_name_by_default_and_reverses_with_dir_desc(): void
    {
        $this->assertSame(['Aaron Root', 'Bella Viewer', 'Carl Recruiter', 'Dina Viewer'], $this->fetch(''));
        $this->assertSame(['Dina Viewer', 'Carl Recruiter', 'Bella Viewer', 'Aaron Root'], $this->fetch('sort=name&dir=desc'));
    }

    public function test_sorts_by_last_login_with_never_logged_in_last_in_both_directions(): void
    {
        $this->assertSame(['Aaron Root', 'Bella Viewer', 'Dina Viewer', 'Carl Recruiter'], $this->fetch('sort=last_login&dir=asc'));
        $this->assertSame(['Dina Viewer', 'Bella Viewer', 'Aaron Root', 'Carl Recruiter'], $this->fetch('sort=last_login&dir=desc'));
        $this->actingAs($this->superadmin)->getJson('/api/users?sort=last_login&dir=desc&perPage=1&page=4')->assertOk()
            ->assertJsonPath('data.0.name', 'Carl Recruiter');
    }

    public function test_sorts_by_status_with_name_as_tie_breaker(): void
    {
        $this->assertSame(['Aaron Root', 'Bella Viewer', 'Dina Viewer', 'Carl Recruiter'], $this->fetch('sort=status&dir=asc'));
        $this->assertSame(['Carl Recruiter', 'Aaron Root', 'Bella Viewer', 'Dina Viewer'], $this->fetch('sort=status&dir=desc'));
    }

    public function test_column_filters_combine_with_sort_and_string_paging(): void
    {
        $this->assertSame(['Dina Viewer', 'Bella Viewer'], $this->fetch('role=viewer&sort=name&dir=desc'));
        $this->assertSame(['Carl Recruiter'], $this->fetch('status=blocked'));
        // The «to» day is inclusive; the range drops users who never logged in.
        $this->assertSame(['Bella Viewer', 'Dina Viewer'], $this->fetch('last_login_from=2026-09-25&last_login_to=2026-09-28'));
        $this->assertSame(['Aaron Root'], $this->fetch('last_login_to=2026-09-20'));
        // perPage / page arrive as query STRINGS and still page; the meta numbers are integers.
        $this->actingAs($this->superadmin)->getJson('/api/users?role=viewer&sort=last_login&dir=desc&perPage=1&page=2')->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Bella Viewer');
    }

    public function test_search_for_zero_is_a_real_search_and_wildcards_are_text(): void
    {
        User::factory()->withRole(UserRole::Viewer)->create(['name' => 'Agent 007', 'email' => 'agent@example.test']);

        $this->assertSame(['Agent 007'], $this->fetch('q=0'));
        $this->assertSame([], $this->fetch('q=%25'));
        $this->assertSame([], $this->fetch('q=_'));
    }

    public function test_unknown_sort_column_direction_or_bad_values_are_422(): void
    {
        foreach ([
            'sort=email',
            'sort=role',
            'sort='.rawurlencode('name; drop table users'),
            'sort[]=name',
            'dir=up',
            'dir='.rawurlencode('desc nulls first'),
            'last_login_from=28.09.2026',
            'last_login_from=2026-09-28&last_login_to=2026-09-01',
            'page=abc',
            'q='.str_repeat('a', 101),
        ] as $query) {
            $this->actingAs($this->superadmin)->getJson("/api/users?$query")->assertUnprocessable();
        }
    }

    public function test_sorting_does_not_open_the_list_to_other_roles(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->actingAs($admin)->getJson('/api/users?sort=last_login&dir=desc')->assertForbidden();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        /** @var list<string> $names */
        $names = array_column((array) $response->assertOk()->json('data'), 'name');

        return $names;
    }

    /** @return list<string> */
    private function fetch(string $query): array
    {
        return $this->names($this->actingAs($this->superadmin)->getJson('/api/users?'.$query));
    }
}
