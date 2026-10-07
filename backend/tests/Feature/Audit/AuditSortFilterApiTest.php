<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** GET /api/audit: sortable / filterable table headers (sort, dir + the existing filters). Synthetic rows only. */
final class AuditSortFilterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create(['name' => 'Mila Root']);
        $zed = User::factory()->withRole(UserRole::HrManager)->create(['name' => 'Zed Hr']);
        $amy = User::factory()->withRole(UserRole::HrManager)->create(['name' => 'Amy Hr']);
        // Only the rows of this test: creating the users above may have logged entries of its own.
        AuditEntry::query()->delete();
        foreach ([
            [101, $zed->id, 'vacancy', 7, 'updated', '2026-09-10 10:00:00'],
            [102, null, 'candidate', 3, 'created', '2026-09-12 11:00:00'],
            [103, $amy->id, 'employee', 9, 'status_changed', '2026-09-15 12:00:00'],
            [104, $amy->id, 'candidate', 1, 'deleted', '2026-09-15 12:00:00'],
        ] as [$id, $user, $type, $entity, $action, $at]) {
            AuditEntry::query()->insert([
                'id' => $id, 'user_id' => $user, 'entity_type' => $type, 'entity_id' => $entity, 'action' => $action, 'created_at' => $at,
            ]);
        }
    }

    public function test_newest_first_by_default_and_time_ascending_on_request(): void
    {
        // Same timestamp (103, 104): the id decides, so pages are stable.
        $this->assertSame([104, 103, 102, 101], $this->fetch(''));
        $this->assertSame([104, 103, 102, 101], $this->fetch('sort=time&dir=desc'));
        $this->assertSame([101, 102, 103, 104], $this->fetch('sort=time&dir=asc'));
    }

    public function test_sorts_by_user_name_with_system_entries_last_in_both_directions(): void
    {
        $this->assertSame([104, 103, 101, 102], $this->fetch('sort=user&dir=asc'));
        $this->assertSame([101, 104, 103, 102], $this->fetch('sort=user&dir=desc'));
        $this->actingAs($this->superadmin)->getJson('/api/audit?sort=user&dir=desc&perPage=1&page=4')->assertOk()
            ->assertJsonPath('data.0.id', 102);
    }

    public function test_sorts_by_action_and_by_entity(): void
    {
        $this->assertSame([102, 104, 103, 101], $this->fetch('sort=action&dir=asc'));
        $this->assertSame([104, 102, 103, 101], $this->fetch('sort=entity&dir=asc'));
        $this->assertSame([101, 103, 102, 104], $this->fetch('sort=entity&dir=desc'));
    }

    public function test_column_filters_combine_with_sort_and_string_paging(): void
    {
        $this->assertSame([104, 102], $this->fetch('entity_type=candidate'));
        $this->assertSame([103, 104], $this->fetch('from=2026-09-15&to=2026-09-15&sort=entity&dir=desc'));
        // perPage / page arrive as query STRINGS and still page; the meta numbers are integers.
        $this->actingAs($this->superadmin)->getJson('/api/audit?entity_type=candidate&sort=time&dir=asc&perPage=1&page=2')->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', 104);
    }

    public function test_unknown_sort_column_or_direction_is_422(): void
    {
        foreach (['sort=changes', 'sort=created_at', 'sort='.rawurlencode('(select 1)'), 'sort[]=time', 'dir=up', 'dir='.rawurlencode('asc nulls first')] as $query) {
            $this->actingAs($this->superadmin)->getJson("/api/audit?$query")->assertUnprocessable();
        }
    }

    public function test_sorting_does_not_open_the_log_to_other_roles(): void
    {
        $hr = User::factory()->withRole(UserRole::HrManager)->create();
        $this->actingAs($hr)->getJson('/api/audit?sort=user')->assertForbidden();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<int>
     */
    private function ids(TestResponse $response): array
    {
        /** @var list<int> $ids */
        $ids = array_column((array) $response->assertOk()->json('data'), 'id');

        return $ids;
    }

    /** @return list<int> */
    private function fetch(string $query): array
    {
        return $this->ids($this->actingAs($this->superadmin)->getJson('/api/audit?'.$query));
    }
}
