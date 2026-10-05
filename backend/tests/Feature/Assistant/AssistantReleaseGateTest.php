<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Services\AssistantChatService;
use App\Modules\Assistant\Services\InternalApi;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Core\Contracts\ModuleSettingsRepository;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Directory\Models\Branch;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Contracts\HiringTeamRepository;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Real API authorization/projection and conversation protocol; the broker is entirely synthetic. */
final class AssistantReleaseGateTest extends TestCase
{
    use AiFixtures, RecruitingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow('2026-10-04 12:00:00');
    }

    public function test_removed_selected_role_cannot_fall_back_to_base_superadmin_before_history_replay(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $this->fakeBroker([[self::doneAnswer('Not delivered')]]);
        $user = $this->userWith(UserRole::Superadmin);
        $user->assignRole(UserRole::Recruiter->value);
        $user->actAs(UserRole::Recruiter->value);
        $fresh = User::query()->findOrFail($user->id);
        $fresh->removeRole(UserRole::Recruiter->value);
        $reads = 0;
        Route::get('api/people', function () use (&$reads) {
            $reads++;

            return response()->json(['data' => [['id' => 1]]]);
        });
        // Preserve the stale in-flight request object; global actAs fallback behavior is deliberately unchanged.
        $result = $this->app->make(AssistantChatService::class)->turn($user, self::readRound('people'), ['path' => '/', 'title' => '']);
        $this->assertSame(['state' => 'failed', 'request_id' => 0, 'error' => 'ai_context_changed'], $result);
        $this->assertSame(0, $reads);
        $this->assertSame([], $this->brokerSubmits);
        Http::assertNothingSent();
    }

    #[DataProvider('initialCaptureChanges')]
    public function test_initial_capture_rechecks_assistant_access_after_middleware(string $change): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $this->fakeBroker([[self::doneAnswer('Not delivered')]]);
        $user = $this->userWith(UserRole::Superadmin);
        $settings = $this->app->make(ModuleSettingsRepository::class);
        $settings->save('assistant', true, [UserRole::Superadmin->value]);
        $access = $this->app->make(ModuleAccess::class);
        $access->refreshSettings();
        $this->assertTrue($access->allows($user, 'assistant'));
        $users = $this->app->make(UserRepository::class);
        // The real route middleware has passed when capture makes its first user-repository lookup.
        $this->mock(UserRepository::class)->shouldReceive('find')->once()->with($user->id)
            ->andReturnUsing(function (int $id) use ($change, $settings, $access, $user, $users): User {
                $fresh = $users->find($id);
                assert($fresh instanceof User);
                if ($change === 'demotion') {
                    $fresh->syncRoles([UserRole::Employee->value]);
                } else {
                    $settings->save('assistant', false, [UserRole::Superadmin->value]);
                    Cache::forget(ModuleAccess::CACHE_KEY);
                }
                $this->assertTrue($access->allows($user, 'assistant'), 'Middleware/current instance still accepted the earlier authority.');

                return $fresh;
            });
        $reads = 0;
        Route::get('api/people', function () use (&$reads) {
            $reads++;

            return response()->json(['data' => [['id' => 1]]]);
        });
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => self::readRound('people')])
            ->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.error', 'ai_context_changed')
            ->assertJsonPath('data.request_id', 0)->assertJsonMissingPath('data.assistant');
        $this->assertSame(0, $reads);
        $this->assertSame([], $this->brokerSubmits);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string}> */
    public static function initialCaptureChanges(): iterable
    {
        yield 'demoted after middleware' => ['demotion'];
        yield 'disabled after middleware' => ['module_off'];
    }

    public function test_role_revocation_during_history_read_blocks_provider_submit(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $this->fakeBroker([[self::doneAnswer('Not delivered')]]);
        $user = $this->userWith(UserRole::Superadmin);
        $reads = 0;
        Route::get('api/people', function () use ($user, &$reads) {
            $reads++;
            User::query()->findOrFail($user->id)->syncRoles([UserRole::Employee->value]);

            return response()->json(['data' => [['id' => 1]]]);
        });
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => self::readRound('people')])
            ->assertOk()->assertJsonPath('data.error', 'ai_context_changed')->assertJsonMissingPath('data.assistant');
        $this->assertSame(1, $reads);
        $this->assertSame([], $this->brokerSubmits);
        Http::assertNothingSent();
    }

    public function test_role_revocation_inside_first_broker_wait_blocks_answer_and_both_tool_runners(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $user = $this->userWith(UserRole::Superadmin);
        $finish = true;
        $this->brokerWithRevocation(function () use ($user): void {
            User::query()->findOrFail($user->id)->syncRoles([UserRole::Employee->value]);
        }, $finish);
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => [['role' => 'user', 'content' => 'List endpoints and propose a write']]])
            ->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.error', 'ai_context_changed')
            ->assertJsonMissingPath('data.assistant')->assertJsonMissingPath('data.server_results')->assertJsonMissingPath('data.client_calls');
        $this->assertCount(1, $this->brokerSubmits);
        $this->assertSame(1, $this->brokerPolls);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_blocking_actor_inside_broker_wait_blocks_delivery(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $user = $this->userWith(UserRole::Superadmin);
        $finish = true;
        $this->brokerWithRevocation(function () use ($user): void {
            User::query()->findOrFail($user->id)->forceFill(['status' => UserStatus::Blocked])->save();
        }, $finish);
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => [['role' => 'user', 'content' => 'Count']]])
            ->assertOk()->assertJsonPath('data.error', 'ai_context_changed')->assertJsonMissingPath('data.assistant')
            ->assertJsonMissingPath('data.server_results')->assertJsonMissingPath('data.client_calls');
        $this->assertCount(1, $this->brokerSubmits);
        $this->assertSame(1, $this->brokerPolls);
    }

    public function test_parallel_module_disable_is_detected_despite_request_local_access_cache(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $user = $this->userWith(UserRole::Superadmin);
        $access = $this->app->make(ModuleAccess::class);
        $this->assertTrue($access->allows($user, 'recruiting'));
        $finish = true;
        $this->brokerWithRevocation(function () use ($access, $user): void {
            // Another request saves through its repository and forgets shared cache; it cannot reset this instance.
            $this->app->make(ModuleSettingsRepository::class)->save('recruiting', false, UserRole::values());
            Cache::forget(ModuleAccess::CACHE_KEY);
            $this->assertTrue($access->allows($user, 'recruiting'), 'The old instance is still stale before the final capture.');
        }, $finish);
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => [['role' => 'user', 'content' => 'Count']]])
            ->assertOk()->assertJsonPath('data.error', 'ai_context_changed')->assertJsonMissingPath('data.assistant')
            ->assertJsonMissingPath('data.server_results')->assertJsonMissingPath('data.client_calls');
        $this->assertFalse($access->allows($user, 'recruiting'), 'Final capture refreshed the instance before checking authority.');
        $this->assertCount(1, $this->brokerSubmits);
        $this->assertSame(1, $this->brokerPolls);
    }

    public function test_contextual_revocation_inside_poll_refresh_blocks_newly_completed_and_cached_answer(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $user = $this->userWith(UserRole::Employee);
        $vacancy = $this->vacancyIn(Branch::factory()->create());
        $vacancy->forceFill(['hiring_manager_id' => $user->id])->save();
        $finish = false;
        $this->brokerWithRevocation(function () use ($vacancy): void {
            $vacancy->forceFill(['hiring_manager_id' => null])->save();
        }, $finish);
        $id = $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => [['role' => 'user', 'content' => 'Count']]])
            ->assertOk()->assertJsonPath('data.state', 'pending')->json('data.request_id');
        $polls = $this->brokerPolls;
        $finish = true;
        $this->actingAs($user)->getJson('/api/assistant/turns/'.$id)->assertOk()->assertJsonPath('data.error', 'ai_context_changed')
            ->assertJsonMissingPath('data.assistant')->assertJsonMissingPath('data.server_results')->assertJsonMissingPath('data.client_calls');
        $this->assertSame($polls + 1, $this->brokerPolls, 'The pre-refresh scope matched; revocation happened in the broker callback.');
        $this->actingAs($user)->getJson('/api/assistant/turns/'.$id)->assertOk()->assertJsonPath('data.error', 'ai_context_changed');
        $this->assertSame($polls + 1, $this->brokerPolls, 'A completed provider cache cannot bypass the fresh scope check.');
    }

    #[DataProvider('contextualScopes')]
    public function test_pending_and_cached_contextual_assignment_removal_denies_before_broker_refresh(string $assignment, string $state): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $this->fakeBroker([[$state === 'pending' ? self::pendingAnswer() : self::doneAnswer('SENTINEL_CACHED')]]);
        $user = $this->userWith(UserRole::Employee);
        $vacancy = $this->vacancyIn(Branch::factory()->create());
        $application = $this->applied($vacancy, ['full_name' => 'SENTINEL_PERSON']);
        $team = $this->app->make(HiringTeamRepository::class);
        if ($assignment === 'manager') {
            $vacancy->forceFill(['hiring_manager_id' => $user->id])->save();
        } else {
            $team->syncInterviewers($application, [$user->id]);
        }
        $this->actingAs($user)->getJson('/api/candidates/'.$application->candidate_id)->assertOk();
        $id = $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => self::readRound('candidates/'.$application->candidate_id)])
            ->assertOk()->assertJsonPath('data.state', $state)->json('data.request_id');
        $this->assertSame(200, json_decode($this->brokerSubmits[0]['messages'][3]['content'], true)['status']);
        if ($assignment === 'manager') {
            $vacancy->forceFill(['hiring_manager_id' => null])->save();
        } else {
            $team->syncInterviewers($application, []);
        }
        $this->actingAs($user)->getJson('/api/candidates/'.$application->candidate_id)->assertForbidden();
        $polls = $this->brokerPolls;
        $this->actingAs($user)->getJson('/api/assistant/turns/'.$id)->assertOk()->assertJsonPath('data.error', 'ai_context_changed')
            ->assertJsonMissingPath('data.assistant')->assertJsonMissingPath('data.client_calls');
        $this->assertSame($polls, $this->brokerPolls);
    }

    /** @return iterable<string, array{string, string}> */
    public static function contextualScopes(): iterable
    {
        yield 'manager pending' => ['manager', 'pending'];
        yield 'manager cached' => ['manager', 'done'];
        yield 'interviewer pending' => ['interviewer', 'pending'];
        yield 'interviewer cached' => ['interviewer', 'done'];
    }

    public function test_filtered_count_and_real_references_support_the_read_then_navigation_protocol(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $north = Branch::factory()->create();
        $user = $this->userWith(UserRole::Recruiter, [$north]);
        $vacancy = $this->vacancyIn($north);
        $first = $this->applied($vacancy, ['full_name' => 'SENTINEL_FIRST']);
        $second = $this->applied($vacancy, ['full_name' => 'SENTINEL_SECOND']);
        $this->applied($this->vacancyIn($north));
        $foreign = $this->applied($this->vacancyIn(Branch::factory()->create()));
        $query = ['vacancy_id' => $vacancy->id, 'stage_id' => $first->stage_id, 'status' => 'active', 'perPage' => 1];
        $raw = $this->actingAs($user)->getJson('/api/candidates?'.http_build_query($query))->assertOk()->assertJsonPath('meta.total', 2)->json();
        $selectedId = $raw['data'][0]['id'];
        $this->assertContains($selectedId, [$first->candidate_id, $second->candidate_id]);
        $path = '/candidates/'.$selectedId;
        $this->fakeBroker([
            [self::callsAnswer([['id' => 'read', 'name' => 'api_get', 'arguments' => (string) json_encode(['path' => 'candidates', 'query' => $query])]])],
            [self::callsAnswer([['id' => 'navigate', 'name' => 'open_page', 'arguments' => (string) json_encode(['path' => $path])]], '2 matching candidates.')],
            [self::doneAnswer('Navigation requested.')],
        ]);
        $messages = [['role' => 'user', 'content' => 'Count matching candidates and open the first card']];
        $read = $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()
            ->assertJsonPath('data.client_calls.0.arguments.query', $query)->json('data');
        $messages[] = $read['assistant'];
        $messages[] = ['role' => 'tool', 'tool_call_id' => 'read', 'content' => json_encode($raw)];
        $navigation = $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()
            ->assertJsonPath('data.assistant.content', '2 matching candidates.')->assertJsonPath('data.client_calls.0.name', 'open_page')
            ->assertJsonPath('data.client_calls.0.arguments.path', $path)->json('data');
        $projected = json_decode($this->brokerSubmits[1]['messages'][3]['content'], true);
        $this->assertSame(2, $projected['data']['meta']['total']);
        $this->assertSame(1, $projected['data']['meta']['per_page']);
        $this->assertSame($selectedId, $projected['data']['data'][0]['id']);
        $reference = $projected['data']['data'][0]['applications'][0];
        $this->assertSame($vacancy->id, $reference['vacancy_id']);
        $this->assertSame($first->stage_id, $reference['stage_id']);
        $this->assertSame(['path' => 'candidates'], json_decode($this->brokerSubmits[1]['messages'][2]['tool_calls'][0]['function']['arguments'], true));
        $this->assertStringNotContainsString('SENTINEL', (string) json_encode($this->brokerSubmits));
        $this->assertNotContains($foreign->candidate_id, array_column($projected['data']['data'], 'id'));
        $messages[] = $navigation['assistant'];
        $messages[] = ['role' => 'tool', 'tool_call_id' => 'navigate', 'content' => '{"navigated":true}'];
        $this->actingAs($user)->getJson('/api/candidates/'.$selectedId)->assertOk();
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()->assertJsonPath('data.assistant.content', 'Navigation requested.');
        $this->assertSame(['unverified' => true, 'ack' => 'navigation_result_omitted'], json_decode($this->brokerSubmits[2]['messages'][5]['content'], true));
    }

    public function test_write_proposal_does_nothing_until_explicit_api_write_and_repeated_history_never_replays_it(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $user = $this->userWith(UserRole::HrManager);
        $args = ['method' => 'POST', 'path' => 'people', 'body' => ['full_name' => 'SENTINEL_NEW', 'hired_at' => '2026-10-01'], 'summary' => 'Create a synthetic employee'];
        $this->fakeBroker([[self::callsAnswer([['id' => 'proposal', 'name' => 'api_write', 'arguments' => (string) json_encode($args)]])], [self::doneAnswer('Write result is unverified.')]]);
        $messages = [['role' => 'user', 'content' => 'Propose adding an employee']];
        $proposal = $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()
            ->assertJsonPath('data.client_calls.0.name', 'api_write')->assertJsonPath('data.client_calls.0.arguments', $args)->json('data');
        $this->assertDatabaseCount('employees', 0);
        // The SPA's explicit confirmation executes this ordinary API request; receiving a proposal never does.
        $written = $this->actingAs($user)->postJson('/api/people', $args['body'])->assertCreated()->json();
        $this->assertDatabaseCount('employees', 1);
        $messages[] = $proposal['assistant'];
        $messages[] = ['role' => 'tool', 'tool_call_id' => 'proposal', 'content' => json_encode(['status' => 201, 'data' => $written])];
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()->assertJsonPath('data.assistant.content', 'Write result is unverified.');
            $this->assertSame(1, Employee::query()->count(), 'History replay does not execute the write again; this is not global exactly-once delivery.');
            $this->assertSame(['unverified' => true, 'ack' => 'write_result_omitted'], json_decode($this->brokerSubmits[$i + 1]['messages'][3]['content'], true));
            $this->assertStringNotContainsString('SENTINEL_NEW', (string) json_encode($this->brokerSubmits[$i + 1]));
        }
        $this->assertSame('SENTINEL_NEW', Employee::query()->sole()->full_name);
    }

    public function test_denied_read_round_exposes_fixed_code_without_foreign_record_and_can_be_explained(): void
    {
        $this->enableAi(['native_tools' => 'on']);
        $user = $this->userWith(UserRole::Recruiter, [Branch::factory()->create()]);
        $foreign = $this->applied($this->vacancyIn(Branch::factory()->create()), ['full_name' => 'SENTINEL_FOREIGN']);
        $path = 'candidates/'.$foreign->candidate_id;
        $this->fakeBroker([[self::callsAnswer([['id' => 'read', 'name' => 'api_get', 'arguments' => (string) json_encode(['path' => $path])]])], [self::doneAnswer('Access denied (forbidden); ask an authorized colleague.')]]);
        $messages = [['role' => 'user', 'content' => 'Read the supplied candidate ID']];
        $proposal = $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()->assertJsonPath('data.client_calls.0.arguments.path', $path)->json('data');
        $raw = $this->actingAs($user)->getJson('/api/'.$path)->assertForbidden()->json();
        $messages[] = $proposal['assistant'];
        // Match AssistantToolExecutor: errors carry status/message, never the whole Laravel debug response.
        $messages[] = ['role' => 'tool', 'tool_call_id' => 'read', 'content' => json_encode(['status' => 403, 'error' => $raw['message']])];
        $this->actingAs($user)->postJson('/api/assistant/turn', ['messages' => $messages])->assertOk()->assertJsonPath('data.assistant.content', 'Access denied (forbidden); ask an authorized colleague.');
        $this->assertSame(['status' => 403, 'error' => 'forbidden'], json_decode($this->brokerSubmits[1]['messages'][3]['content'], true));
        $this->assertStringNotContainsString('SENTINEL_FOREIGN', (string) json_encode($this->brokerSubmits));
    }

    public function test_shared_candidate_card_and_assistant_projection_only_include_visible_application_refs(): void
    {
        $north = Branch::factory()->create();
        $user = $this->userWith(UserRole::Recruiter, [$north]);
        $northApplication = $this->applied($this->vacancyIn($north), ['full_name' => 'SENTINEL_SHARED']);
        $southVacancy = $this->vacancyIn(Branch::factory()->create());
        $candidate = Candidate::query()->findOrFail($northApplication->candidate_id);
        $southApplication = $this->app->make(ApplicationService::class)->apply(null, $candidate, $southVacancy);
        $this->actingAs($user)->getJson('/api/vacancies/'.$southVacancy->id)->assertForbidden();

        $raw = $this->actingAs($user)->getJson('/api/candidates/'.$candidate->id)->assertOk()->json('data.applications');
        $rawIds = array_column($raw, 'id');
        $this->assertContains($northApplication->id, $rawIds);
        $this->assertNotContains($southApplication->id, $rawIds);

        $api = $this->app->make(InternalApi::class);
        $projected = $api->call($user, 'GET', 'candidates/'.$candidate->id);
        $refs = $projected['data']['data']['applications'];
        $projectedIds = array_column($refs, 'id');
        $this->assertContains($northApplication->id, $projectedIds);
        $this->assertNotContains($southApplication->id, $projectedIds);
        $this->assertContains($northApplication->vacancy_id, array_column($refs, 'vacancy_id'));
        $this->assertNotContains($southVacancy->id, array_column($refs, 'vacancy_id'));
        $this->assertStringNotContainsString('SENTINEL_SHARED', (string) json_encode($projected));

        $filtered = $api->call($user, 'GET', 'candidates', ['vacancy_id' => $southVacancy->id]);
        $this->assertSame(0, $filtered['data']['meta']['total']);
        $this->assertSame([], $filtered['data']['data']);
    }

    /** @param  callable(): void  $revoke */
    private function brokerWithRevocation(callable $revoke, bool &$finish): void
    {
        Http::fake(function (Request $request) use ($revoke, &$finish) {
            if (! str_starts_with($request->url(), self::BROKER.'/v1/jobs')) {
                return null;
            }
            if ($request->method() === 'POST') {
                $this->brokerSubmits[] = $request->data();

                return Http::response(['job_id' => 1001, 'poll_after_s' => 2], 202);
            }
            $this->brokerPolls++;
            if (! $finish) {
                return Http::response(self::pendingAnswer());
            }
            $revoke();

            return Http::response(self::callsAnswer([
                ['id' => 'server', 'name' => 'find_endpoints', 'arguments' => '{"query":"people"}'],
                ['id' => 'client', 'name' => 'api_write', 'arguments' => '{"method":"POST","path":"people","body":{"full_name":"SENTINEL"},"summary":"SENTINEL"}'],
            ], 'SENTINEL_ANSWER'));
        });
    }

    /**
     * @param  list<array{id: string, name: string, arguments: string}>  $calls
     * @return array<string, mixed>
     */
    private static function callsAnswer(array $calls, string $text = ''): array
    {
        return [...self::doneAnswer($text), 'finish_reason' => 'tool_calls', 'tool_calls' => array_map(static fn (array $call): array => [
            'id' => $call['id'], 'type' => 'function', 'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
        ], $calls)];
    }

    /** @return list<array<string, mixed>> */
    private static function readRound(string $path): array
    {
        return [
            ['role' => 'user', 'content' => 'Read records'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'read', 'type' => 'function', 'function' => ['name' => 'api_get', 'arguments' => (string) json_encode(['path' => $path])]]]],
            ['role' => 'tool', 'tool_call_id' => 'read', 'content' => '{}'],
        ];
    }
}
