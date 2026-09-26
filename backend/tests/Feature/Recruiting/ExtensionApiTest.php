<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class ExtensionApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private const string PROFILE = 'https://www.linkedin.com/in/ivan-testenko-000/';

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->north, $this->south] = Branch::factory()->count(2)->create()->all();
    }

    public function test_token_lifecycle_issue_status_rotate_revoke(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);

        $this->getJson('/api/me/extension-token')->assertUnauthorized();
        $this->actingAs($recruiter)->getJson('/api/me/extension-token')->assertOk()->assertJsonPath('data.active', false);

        $first = $this->actingAs($recruiter)->postJson('/api/me/extension-token')->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.last_used_at', null);
        $firstToken = (string) $first->json('data.token');
        $this->assertStringContainsString('|', $firstToken);
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, strtotime((string) $first->json('data.expires_at')), 5);

        $status = $this->actingAs($recruiter)->getJson('/api/me/extension-token')->assertOk()->assertJsonPath('data.active', true);
        $this->assertArrayNotHasKey('token', (array) $status->json('data'), 'the plaintext is shown only once');

        // Issuing again revokes the previous token: one active token per user.
        $secondToken = (string) $this->actingAs($recruiter)->postJson('/api/me/extension-token')->assertCreated()->json('data.token');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $recruiter->id)->count());
        $this->asExtension($firstToken, 'GET', '/api/clipper/me')->assertUnauthorized();
        $this->asExtension($secondToken, 'GET', '/api/clipper/me')->assertOk()->assertJsonPath('data.user.id', $recruiter->id);
        $this->assertNotNull(DB::table('personal_access_tokens')->value('last_used_at'));

        $this->actingAs($recruiter)->deleteJson('/api/me/extension-token')->assertNoContent();
        $this->actingAs($recruiter)->getJson('/api/me/extension-token')->assertOk()->assertJsonPath('data.active', false);
        $this->asExtension($secondToken, 'GET', '/api/clipper/me')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $token = $this->tokenFor($recruiter);
        $this->travel(91)->days();

        $this->asExtension($token, 'GET', '/api/clipper/me')->assertUnauthorized();
        $this->actingAs($recruiter)->getJson('/api/me/extension-token')->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_clipper_token_is_rejected_outside_clipper_routes(): void
    {
        $admin = $this->userWith(UserRole::Superadmin);
        $token = $this->tokenFor($admin);

        foreach ([
            ['GET', '/api/candidates'],
            ['POST', '/api/candidates'],
            ['GET', '/api/users'],
            ['GET', '/api/auth/me'],
            ['GET', '/api/me/extension-token'],
            ['POST', '/api/me/extension-token'],
            ['GET', '/api/vacancies'],
        ] as [$method, $uri]) {
            $this->asExtension($token, $method, $uri)->assertUnauthorized();
        }
        $this->asExtension($token, 'GET', '/api/clipper/me')->assertOk();
    }

    public function test_token_without_clipper_ability_is_rejected_on_clipper_routes(): void
    {
        $admin = $this->userWith(UserRole::Superadmin);
        $other = $admin->createToken('other', ['something-else'])->plainTextToken;

        $this->asExtension($other, 'GET', '/api/clipper/me')->assertUnauthorized();
        $this->asExtension($other, 'GET', '/api/candidates')->assertUnauthorized();
    }

    public function test_me_lists_open_vacancies_in_scope(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $mine = $this->vacancyIn($this->north);
        $this->vacancyIn($this->south);

        $response = $this->asExtension($this->tokenFor($recruiter), 'GET', '/api/clipper/me')->assertOk()
            ->assertJsonPath('data.user.email', $recruiter->email)
            ->assertJsonCount(1, 'data.vacancies')
            ->assertJsonPath('data.vacancies.0.id', $mine->id)
            ->assertJsonPath('data.vacancies.0.branch', $this->north->name);
        $this->assertSame(['id', 'title', 'branch'], array_keys((array) $response->json('data.vacancies.0')));
    }

    public function test_clip_creates_candidate_with_note_then_matches_by_profile_url(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $vacancy = $this->vacancyIn($this->north);
        $token = $this->tokenFor($recruiter);

        $created = $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload(['vacancy_id' => $vacancy->id]))
            ->assertCreated()
            ->assertJsonPath('data.created', true);
        $id = (int) $created->json('data.candidate_id');
        $created->assertJsonPath('data.url', 'https://sinhrm.vercel.app/candidates/'.$id);

        $candidate = Candidate::query()->findOrFail($id);
        $this->assertSame('Ivan Testenko', $candidate->full_name);
        $this->assertSame('linkedin', $candidate->source->value);
        $this->assertSame($recruiter->id, $candidate->owner_id);
        $this->assertSame(1, $candidate->applications()->where('vacancy_id', $vacancy->id)->count());
        $this->assertSame('https://linkedin.com/in/ivan-testenko-000', DB::table('candidate_profile_urls')->value('url'));
        $note = Touchpoint::query()->where('candidate_id', $id)->where('channel', 'note')->sole();
        $this->assertStringStartsWith('Imported from LinkedIn: https://linkedin.com/in/ivan-testenko-000', (string) $note->body);
        $this->assertStringContainsString('QA engineer', (string) $note->body);

        // Same page again (other URL spelling): matched, no second note, no second application.
        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload([
            'profile_url' => 'https://linkedin.com/in/ivan-testenko-000?trk=abc#top', 'vacancy_id' => $vacancy->id,
        ]))->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.candidate_id', $id);
        $this->assertSame(1, Candidate::query()->count());
        $this->assertSame(1, Touchpoint::query()->where('channel', 'note')->count());
    }

    public function test_clip_matches_existing_candidate_by_phone_or_email_and_links_the_url(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $token = $this->tokenFor($recruiter);
        $byPhone = Candidate::factory()->create(['phone' => '+380670000001', 'owner_id' => $recruiter->id]);
        $byEmail = Candidate::factory()->create(['email' => 'ivan@example.test', 'owner_id' => $recruiter->id]);

        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload([
            'source_site' => 'work_ua', 'profile_url' => 'https://www.work.ua/resumes/1000001/', 'phone' => '067 000 00 01',
        ]))->assertOk()->assertJsonPath('data.candidate_id', $byPhone->id)->assertJsonPath('data.created', false);

        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload([
            'source_site' => 'djinni', 'profile_url' => 'https://djinni.co/q/abc123/', 'email' => 'IVAN@example.test',
        ]))->assertOk()->assertJsonPath('data.candidate_id', $byEmail->id);

        $this->assertSame(2, Candidate::query()->count());
        $this->assertSame($byPhone->id, DB::table('candidate_profile_urls')->where('url', 'https://work.ua/resumes/1000001')->value('candidate_id'));
        // A new URL was linked → one note per matched candidate.
        $this->assertSame(1, Touchpoint::query()->where('candidate_id', $byEmail->id)->where('channel', 'note')->count());
    }

    public function test_profile_url_must_be_https_on_the_source_site_host(): void
    {
        $token = $this->tokenFor($this->userWith(UserRole::Recruiter, [$this->north]));

        foreach ([
            ['source_site' => 'linkedin', 'profile_url' => 'https://evil.example.com/in/ivan'],
            ['source_site' => 'linkedin', 'profile_url' => 'https://linkedin.com.evil.example/in/ivan'],
            ['source_site' => 'linkedin', 'profile_url' => 'http://www.linkedin.com/in/ivan'],
            ['source_site' => 'dou', 'profile_url' => 'https://www.linkedin.com/in/ivan'],
            ['source_site' => 'work_ua', 'profile_url' => 'https://user:pass@www.work.ua/resumes/1/'],
            ['source_site' => 'djinni', 'profile_url' => 'https://djinni.co/'],
        ] as $case) {
            $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload($case))
                ->assertUnprocessable()->assertJsonValidationErrors('profile_url');
        }
        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload(['source_site' => 'facebook']))
            ->assertUnprocessable()->assertJsonValidationErrors('source_site');
        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload(['phone' => 'not a phone']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame(0, Candidate::query()->count());
    }

    public function test_scope_restricted_match_vacancy_of_other_branch_and_viewer(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $token = $this->tokenFor($recruiter);
        $foreign = $this->applied($this->vacancyIn($this->south), ['email' => 'south@example.test'])->candidate;

        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload(['email' => 'south@example.test']))
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_candidate')->assertJsonPath('restricted', true)
            ->assertJsonMissingPath('existing_id');
        $this->assertSame(0, DB::table('candidate_profile_urls')->where('candidate_id', $foreign->id)->count());

        $this->asExtension($token, 'POST', '/api/clipper/candidates', $this->payload(['vacancy_id' => $this->vacancyIn($this->south)->id]))
            ->assertForbidden()->assertJsonPath('code', 'vacancy_out_of_scope');

        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);
        $this->asExtension($this->tokenFor($viewer), 'POST', '/api/clipper/candidates', $this->payload())->assertForbidden();
        $this->assertSame(1, Candidate::query()->count());
    }

    public function test_rate_limit_is_30_per_minute_per_token(): void
    {
        $token = $this->tokenFor($this->userWith(UserRole::Recruiter, [$this->north]));

        for ($i = 0; $i < 30; $i++) {
            $this->asExtension($token, 'GET', '/api/clipper/me')->assertOk();
        }
        $this->asExtension($token, 'GET', '/api/clipper/me')->assertStatus(429);

        // Another user's token has its own bucket.
        $this->asExtension($this->tokenFor($this->userWith(UserRole::Recruiter, [$this->south])), 'GET', '/api/clipper/me')->assertOk();
    }

    public function test_cors_only_for_extension_origins_on_clipper_routes(): void
    {
        $origin = 'chrome-extension://'.str_repeat('a', 32);
        $preflight = ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type'];

        $this->call('OPTIONS', '/api/clipper/candidates', [], [], [], $this->server($preflight))
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->call('OPTIONS', '/api/clipper/candidates', [], [], [], $this->server(['Origin' => 'https://evil.example'] + $preflight))
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->call('OPTIONS', '/api/candidates', [], [], [], $this->server($preflight))
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->actingAs($user)->postJson('/api/me/extension-token')->assertCreated()->json('data.token');
    }

    /**
     * A token-only request, as the extension makes it: no session user, a fresh guard (the test app keeps resolved
     * guards between requests).
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function asExtension(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri, $data);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Ivan Testenko',
            'source_site' => 'linkedin',
            'profile_url' => self::PROFILE,
            'headline' => 'QA engineer',
            'location' => 'Kyiv',
            'summary' => str_repeat('Synthetic summary. ', 20),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
