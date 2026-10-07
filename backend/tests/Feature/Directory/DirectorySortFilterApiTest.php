<?php

declare(strict_types=1);

namespace Tests\Feature\Directory;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** GET /api/directory/{type}: sortable / filterable table headers (sort, dir, q, status, city_id). Synthetic data. */
final class DirectorySortFilterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $kyiv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->kyiv = City::factory()->create(['name' => 'Kyiv'])->id;
        $lviv = City::factory()->create(['name' => 'Lviv'])->id;
        Branch::factory()->create(['name' => 'Bravo Branch', 'city_id' => $lviv]);
        Branch::factory()->create(['name' => 'Alpha Branch', 'city_id' => $this->kyiv]);
        Branch::factory()->create(['name' => 'Charlie Branch', 'city_id' => null]);
        Branch::factory()->create(['name' => 'Delta Branch', 'city_id' => $this->kyiv, 'status' => 'disabled']);
    }

    public function test_sorts_by_name_and_status(): void
    {
        $this->assertSame(['Alpha Branch', 'Bravo Branch', 'Charlie Branch', 'Delta Branch'], $this->fetch('branches'));
        $this->assertSame(['Delta Branch', 'Charlie Branch', 'Bravo Branch', 'Alpha Branch'], $this->fetch('branches?sort=name&dir=desc'));
        // Ties inside one status go by name.
        $this->assertSame(['Delta Branch', 'Alpha Branch', 'Bravo Branch', 'Charlie Branch'], $this->fetch('branches?sort=status&dir=desc'));
    }

    public function test_sorts_branches_by_city_with_empty_city_last_in_both_directions(): void
    {
        $this->assertSame(['Alpha Branch', 'Delta Branch', 'Bravo Branch', 'Charlie Branch'], $this->fetch('branches?sort=city&dir=asc'));
        $this->assertSame(['Bravo Branch', 'Alpha Branch', 'Delta Branch', 'Charlie Branch'], $this->fetch('branches?sort=city&dir=desc'));
        $this->actingAs($this->admin)->getJson('/api/directory/branches?sort=city&dir=desc&perPage=1&page=4')->assertOk()
            ->assertJsonPath('data.0.name', 'Charlie Branch');
    }

    public function test_column_filters_combine_with_sort_and_string_paging(): void
    {
        $this->assertSame(['Delta Branch', 'Alpha Branch'], $this->fetch("branches?city_id={$this->kyiv}&sort=name&dir=desc"));
        $this->assertSame(['Alpha Branch'], $this->fetch("branches?city_id={$this->kyiv}&status=active"));
        $this->assertSame(['Bravo Branch'], $this->fetch('branches?q=BRAV'));
        // perPage / page arrive as query STRINGS and still page; the meta numbers are integers.
        $this->actingAs($this->admin)->getJson('/api/directory/branches?sort=city&dir=asc&perPage=2&page=2')->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.name', 'Bravo Branch');
    }

    public function test_search_for_zero_is_a_real_search(): void
    {
        Position::factory()->create(['name' => 'Level 0']);
        Position::factory()->create(['name' => 'Level One']);

        $this->assertSame(['Level 0'], $this->fetch('positions?q=0'));
    }

    public function test_city_exists_on_branches_only_and_unknown_values_are_422(): void
    {
        foreach ([
            'positions?sort=city',
            'cities?city_id=1',
            'branches?sort=created_at',
            'branches?sort='.rawurlencode('name desc, id'),
            'branches?sort[]=name',
            'branches?dir=down',
            'branches?city_id=abc',
            'branches?page=0',
        ] as $path) {
            $this->actingAs($this->admin)->getJson('/api/directory/'.$path)->assertUnprocessable();
        }
        $this->assertSame([], $this->fetch('positions?sort=status&dir=desc'));
    }

    public function test_any_active_reader_may_sort_but_not_write(): void
    {
        $viewer = User::factory()->withRole(UserRole::Viewer)->create();
        $this->actingAs($viewer)->getJson('/api/directory/branches?sort=city&dir=desc')->assertOk()->assertJsonPath('meta.total', 4);
        $this->actingAs($viewer)->postJson('/api/directory/branches', ['name' => 'Echo'])->assertForbidden();
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
    private function fetch(string $path): array
    {
        return $this->names($this->actingAs($this->admin)->getJson('/api/directory/'.$path));
    }
}
