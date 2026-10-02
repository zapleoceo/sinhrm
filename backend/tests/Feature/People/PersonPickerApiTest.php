<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** GET /api/people/search and /api/people/lookup — the person picker. Synthetic data only. */
final class PersonPickerApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const array ROW_KEYS = ['id', 'full_name', 'position', 'department', 'avatar_url', 'terminated', 'terminated_at'];

    public function test_guest_and_blocked_user_are_rejected(): void
    {
        $this->getJson('/api/people/search?q=pe')->assertUnauthorized();
        $this->getJson('/api/people/lookup?ids[]=1')->assertUnauthorized();
        $blocked = User::factory()->blocked()->withRole(UserRole::Admin)->create();
        $this->actingAs($blocked)->getJson('/api/people/search?q=pe')->assertForbidden();
    }

    public function test_search_matches_the_directory_and_returns_no_sensitive_fields(): void
    {
        $position = Position::factory()->create(['name' => 'Analyst']);
        $org = $this->org();
        $org['worker']->update(['position_id' => $position->id, 'phone' => '+10000000001']);
        Employee::factory()->terminated()->create(['full_name' => 'Gone Person']);
        $viewer = $this->login(UserRole::Viewer);

        $directory = $this->actingAs($viewer)->getJson('/api/people?q=person&perPage=50')->assertOk()->json('data.*.id');
        $response = $this->actingAs($viewer)->getJson('/api/people/search?q=person&limit=50')->assertOk();
        $this->assertSame($directory, $response->json('data.*.id'));
        $this->assertCount(5, $directory);

        $row = $this->actingAs($viewer)->getJson('/api/people/search?q=worker')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertIsArray($row);
        $this->assertSame(self::ROW_KEYS, array_keys($row));
        $this->assertSame('Analyst', $row['position']);
        $this->assertStringNotContainsString('example.test', (string) json_encode($row));
    }

    public function test_terminated_only_for_hr_on_request(): void
    {
        Employee::factory()->terminated()->create(['full_name' => 'Gone Person']);
        $this->employee(['full_name' => 'Here Person']);
        $admin = $this->login(UserRole::Admin);
        $viewer = $this->login(UserRole::Viewer);

        $this->actingAs($admin)->getJson('/api/people/search?q=person')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/people/search?q=person&include_terminated=1')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($viewer)->getJson('/api/people/search?q=person&include_terminated=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Here Person');
    }

    public function test_manager_finds_only_own_former_subordinates(): void
    {
        $org = $this->org();
        $goneWorker = Employee::factory()->terminated()->create(['full_name' => 'Gone Worker', 'manager_id' => $org['worker']->id]);
        Employee::factory()->terminated()->create(['full_name' => 'Gone Stranger', 'manager_id' => $org['other']->id]);
        Employee::factory()->terminated()->create(['full_name' => 'Gone Orphan']);
        $search = fn (User $u): array => $this->actingAs($u)->getJson('/api/people/search?q=gone&include_terminated=1')->assertOk()->json('data.*.full_name');

        // Indirect report (worker -> lead -> head) counts; another manager's subtree does not.
        $this->assertSame(['Gone Worker'], $search($this->userOf($org['lead'])));
        $this->assertSame(['Gone Worker'], $search($this->userOf($org['head'])));
        $this->assertSame(['Gone Stranger'], $search($this->userOf($org['other'])));
        $this->assertSame([], $search($this->userOf($org['peer'])));
        $this->assertSame([], $search($this->login(UserRole::Viewer)));
        $this->assertSame(['Gone Orphan', 'Gone Stranger', 'Gone Worker'], $search($this->login(UserRole::HrManager)));
        // Without the flag nobody gets terminated people.
        $this->actingAs($this->userOf($org['lead']))->getJson('/api/people/search?q=gone')->assertOk()->assertJsonCount(0, 'data');

        $row = $this->actingAs($this->userOf($org['lead']))->getJson('/api/people/search?q=gone&include_terminated=1')->json('data.0');
        $this->assertIsArray($row);
        $this->assertSame(self::ROW_KEYS, array_keys($row));
        $this->assertSame([$goneWorker->id, true, '2026-01-31'], [$row['id'], $row['terminated'], $row['terminated_at']]);
        $working = $this->actingAs($this->userOf($org['lead']))->getJson('/api/people/search?q=worker%20person')->json('data.0');
        $this->assertIsArray($working);
        $this->assertSame([false, null], [$working['terminated'], $working['terminated_at']]);
    }

    public function test_lookup_obeys_the_former_subordinate_rule(): void
    {
        $org = $this->org();
        $mine = Employee::factory()->terminated()->create(['full_name' => 'Gone Mine', 'manager_id' => $org['peer']->id]);
        $theirs = Employee::factory()->terminated()->create(['full_name' => 'Gone Theirs', 'manager_id' => $org['other']->id]);
        $query = '/api/people/lookup?'.http_build_query(['ids' => [$mine->id, $theirs->id]]);
        $names = fn (User $u): array => $this->actingAs($u)->getJson($query)->assertOk()->json('data.*.full_name');

        $this->assertSame(['Gone Mine'], $names($this->userOf($org['lead'])));
        $this->assertSame(['Gone Theirs'], $names($this->userOf($org['other'])));
        $this->assertSame([], $names($this->userOf($org['worker'])));
        $this->assertSame(['Gone Mine', 'Gone Theirs'], $names($this->login(UserRole::Admin)));
        $this->actingAs($this->userOf($org['lead']))->getJson($query)->assertJsonPath('data.0.terminated', true)
            ->assertJsonPath('data.0.terminated_at', '2026-01-31');
    }

    public function test_subordinates_scope_is_the_managed_subtree(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $head = $this->userOf($org['head']);
        $worker = $this->userOf($org['worker']);

        $names = fn (User $u): array => $this->actingAs($u)->getJson('/api/people/search?q=person&scope=subordinates')->assertOk()->json('data.*.full_name');
        $this->assertSame(['Peer Person', 'Worker Person'], $names($lead));
        $this->assertSame(['Lead Person', 'Peer Person', 'Worker Person'], $names($head));
        $this->assertSame([], $names($worker));
        $this->assertCount(5, $names($this->login(UserRole::HrManager)));
    }

    public function test_users_scope_is_hr_only(): void
    {
        // A token no faker name or e-mail contains: "rita" also matched random users (Margarita, rita@…) → flaky count.
        User::factory()->withRole(UserRole::Recruiter)->create(['name' => 'Ritaqz Recruiter']);
        $admin = $this->login(UserRole::Admin);

        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/people/search?q=ritaqz&scope=users')->assertForbidden();
        $row = $this->actingAs($admin)->getJson('/api/people/search?q=ritaqz&scope=users')->assertOk()
            ->assertJsonCount(1, 'data')->json('data.0');
        $this->assertIsArray($row);
        $this->assertSame(self::ROW_KEYS, array_keys($row));
        $this->assertSame('Ritaqz Recruiter', $row['full_name']);
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $this->employee(['full_name' => 'Percent 100% Person']);
        $this->employee(['full_name' => 'Plain Person']);
        $viewer = $this->login(UserRole::Viewer);

        $this->actingAs($viewer)->getJson('/api/people/search?q='.urlencode('0%'))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->getJson('/api/people/search?q='.urlencode('%%'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson('/api/people/search?q=__')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validation_min_length_and_limits(): void
    {
        $viewer = $this->login(UserRole::Viewer);
        foreach (['', 'q=a', 'q=%20a%20', 'q=ab&limit=0', 'q=ab&limit=51', 'q=ab&limit=x', 'q=ab&scope=all', 'q=ab&include_terminated=maybe'] as $query) {
            $this->actingAs($viewer)->getJson("/api/people/search?$query")->assertUnprocessable();
        }
        $this->actingAs($viewer)->getJson('/api/people/search?q=ab&limit=20')->assertOk();
        foreach (['', 'ids=1', 'ids[]=x', 'ids[]=0'] as $query) {
            $this->actingAs($viewer)->getJson("/api/people/lookup?$query")->assertUnprocessable();
        }
    }

    public function test_lookup_returns_only_visible_people(): void
    {
        $org = $this->org();
        $gone = Employee::factory()->terminated()->create(['full_name' => 'Gone Person', 'manager_id' => $org['lead']->id]);
        $viewer = $this->login(UserRole::Viewer);
        $lead = $this->userOf($org['lead']);
        $ids = [$org['worker']->id, $gone->id, 999999];
        $query = '/api/people/lookup?'.http_build_query(['ids' => $ids]);

        $this->actingAs($viewer)->getJson($query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Worker Person');
        $this->assertSame(['Gone Person', 'Worker Person'], $this->actingAs($lead)->getJson($query)->assertOk()->json('data.*.full_name'));
        $row = $this->actingAs($viewer)->getJson($query)->json('data.0');
        $this->assertIsArray($row);
        $this->assertSame(self::ROW_KEYS, array_keys($row));
    }

    public function test_lookup_users_scope_is_hr_only(): void
    {
        $user = User::factory()->withRole(UserRole::Recruiter)->create(['name' => 'Rita Recruiter']);
        $blocked = User::factory()->blocked()->create();
        $query = '/api/people/lookup?scope=users&ids[]='.$user->id.'&ids[]='.$blocked->id;

        $this->actingAs($this->login(UserRole::Viewer))->getJson($query)->assertForbidden();
        $this->actingAs($this->login(UserRole::Admin))->getJson($query)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Rita Recruiter');
    }

    public function test_search_is_rate_limited(): void
    {
        $viewer = $this->login(UserRole::Viewer);
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($viewer)->getJson('/api/people/search?q=ab')->assertOk();
        }
        $this->actingAs($viewer)->getJson('/api/people/search?q=ab')->assertTooManyRequests();
    }
}
