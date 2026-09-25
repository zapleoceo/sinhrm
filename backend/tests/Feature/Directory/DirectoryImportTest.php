<?php

declare(strict_types=1);

namespace Tests\Feature\Directory;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Position;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLog;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/** Sintegrum is faked with Http::fake; every name and token here is synthetic. */
final class DirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'fake-sintegrum-token-7788';

    private const string BASE = 'https://sintegrum.example.test/v1/demo';

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver(['internal.example.test' => ['10.0.0.5']]));
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_not_configured_is_422_without_any_request(): void
    {
        Http::fake();

        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertStatus(422)->assertJsonPath('code', 'integration_not_configured');
        Http::assertNothingSent();
    }

    public function test_only_superadmin_can_import(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->actingAs($admin)->postJson('/api/directory/import')->assertForbidden();
    }

    public function test_imports_top_level_arrays_with_bearer_token(): void
    {
        $this->configure();
        Http::fake($this->responses(wrap: false));

        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertOk()
            ->assertJsonPath('data.cities', ['created' => 2, 'updated' => 0, 'skipped' => 0])
            ->assertJsonPath('data.branches', ['created' => 2, 'updated' => 0, 'skipped' => 1])
            ->assertJsonPath('data.departments.created', 1)
            ->assertJsonPath('data.positions.created', 1)
            ->assertJsonPath('data.total', ['created' => 6, 'updated' => 0, 'skipped' => 1]);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && str_starts_with($r->url(), self::BASE.'/'));
        Http::assertSent(fn (Request $r): bool => $r->url() === self::BASE.'/jobs/list');

        $branch = Branch::query()->where('external_id', '10')->firstOrFail();
        $this->assertSame('Branch Alpha', $branch->name);
        $this->assertSame('active', $branch->status->value);
        $this->assertSame(City::query()->where('external_id', '1')->value('id'), $branch->city_id);
        $this->assertSame(DirectoryStatus::Disabled, Branch::query()->where('external_id', '11')->firstOrFail()->status);
        $this->assertSame('Position One', Position::query()->where('external_id', '30')->value('name'));
    }

    public function test_imports_lists_wrapped_in_data(): void
    {
        $this->configure();
        Http::fake($this->responses(wrap: true));

        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertOk()->assertJsonPath('data.total.created', 6);
        $this->assertSame(2, City::query()->count());
    }

    public function test_second_run_is_idempotent_and_updates_changes_without_deleting(): void
    {
        $this->configure();
        $state = new ArrayObject(['renamed' => false]);
        $full = $this->responses(wrap: false);
        // One closure fake (repeated Http::fake calls stack, first match wins): the third run renames a city
        // and Sintegrum returns fewer items — nothing may be deleted.
        Http::fake(function (Request $request) use ($state, $full) {
            if ($state['renamed'] !== true) {
                return $full[$request->url()];
            }

            return str_ends_with($request->url(), '/cities/list')
                ? Http::response([['id' => 1, 'name' => 'City One Renamed', 'status' => 1]])
                : Http::response([]);
        });
        $this->actingAs($this->superadmin)->postJson('/api/directory/import')->assertOk();
        $manual = City::factory()->create(['name' => 'Manual City']);

        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertOk()->assertJsonPath('data.total', ['created' => 0, 'updated' => 0, 'skipped' => 7]);

        $state['renamed'] = true;
        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertOk()->assertJsonPath('data.cities', ['created' => 0, 'updated' => 1, 'skipped' => 0]);

        $this->assertSame(3, City::query()->count(), 'import never deletes (city 2 and the manual one stay)');
        $this->assertSame('City One Renamed', City::query()->where('external_id', '1')->value('name'));
        $this->assertDatabaseHas('cities', ['id' => $manual->id, 'external_id' => null]);
    }

    public function test_unauthorized_changes_nothing_and_is_logged_without_secrets(): void
    {
        $this->configure();
        Http::fake([
            self::BASE.'/cities/list' => Http::response([['id' => 1, 'name' => 'City One']]),
            self::BASE.'/branches/list' => Http::response(['name' => 'Unauthorized', 'status' => 401], 401),
            self::BASE.'/*' => Http::response([]),
        ]);

        $response = $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertStatus(502)->assertJsonPath('code', 'sintegrum_unauthorized');

        $this->assertSame(0, City::query()->count(), 'fetch-then-write: a failed run writes nothing');
        $log = IntegrationLog::query()->latest('id')->firstOrFail();
        $this->assertSame('directory_import_failed', $log->message);
        $this->assertSame('sintegrum_unauthorized', $log->context['code'] ?? null);
        $dump = json_encode(IntegrationLog::query()->get()->toArray()).$response->getContent();
        $this->assertStringNotContainsString(self::TOKEN, (string) $dump);
    }

    public function test_success_is_logged_with_counts_only(): void
    {
        $this->configure();
        Http::fake($this->responses(wrap: false));
        $this->actingAs($this->superadmin)->postJson('/api/directory/import')->assertOk();

        $log = IntegrationLog::query()->latest('id')->firstOrFail();
        $this->assertSame('directory_imported', $log->message);
        $this->assertSame($this->superadmin->id, $log->context['by'] ?? null);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($log->context));
        $this->assertStringNotContainsString('sintegrum.example.test', (string) json_encode($log->context));
    }

    public function test_guard_rejects_internal_base_url_before_any_request(): void
    {
        $this->configure('https://internal.example.test/api');
        Http::fake();

        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertStatus(422)->assertJsonPath('code', 'sintegrum_blocked_host');
        Http::assertNothingSent();
    }

    public function test_bad_shape_is_502(): void
    {
        $this->configure();
        Http::fake([self::BASE.'/*' => Http::response(['unexpected' => true])]);
        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertStatus(502)->assertJsonPath('code', 'sintegrum_bad_response');
    }

    public function test_http_error_is_502_with_status_code(): void
    {
        $this->configure();
        Http::fake([self::BASE.'/*' => Http::response('oops', 500)]);
        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertStatus(502)->assertJsonPath('code', 'sintegrum_http_500');
    }

    public function test_connection_failure_hides_the_exception_text(): void
    {
        $this->configure();
        Http::fake(fn () => throw new ConnectionException('cURL error for '.self::BASE.' Bearer '.self::TOKEN));
        $this->actingAs($this->superadmin)->postJson('/api/directory/import')
            ->assertStatus(502)->assertExactJson(['message' => 'sintegrum_unreachable', 'code' => 'sintegrum_unreachable']);
    }

    private function configure(string $baseUrl = self::BASE): void
    {
        $this->app->make(SecretVault::class)->put('sintegrum_api', 'token', self::TOKEN);
        $integration = Integration::query()->firstOrCreate(['key' => 'sintegrum_api']);
        $integration->settings = ['base_url' => $baseUrl];
        $integration->save();
    }

    /** @return array<string, mixed> */
    private function responses(bool $wrap): array
    {
        $lists = [
            'cities' => [['id' => 1, 'name' => 'City One', 'status' => 1], ['id' => '2', 'name' => 'City Two']],
            'branches' => [
                ['id' => 10, 'name' => 'Branch Alpha', 'status' => 1, 'city_id' => 1],
                ['id' => 11, 'name' => 'Branch Beta', 'status' => 0],
                ['name' => 'No id'],
            ],
            'departments' => [['id' => 20, 'name' => 'Department One', 'status' => 1]],
            'jobs' => [['id' => 30, 'name' => 'Position One', 'status' => 1]],
        ];
        $fakes = [];
        foreach ($lists as $resource => $rows) {
            $fakes[self::BASE."/$resource/list"] = Http::response($wrap ? ['data' => $rows] : $rows);
        }

        return $fakes;
    }
}
