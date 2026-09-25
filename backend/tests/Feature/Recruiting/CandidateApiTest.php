<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\ApplicationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class CandidateApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->north, $this->south] = Branch::factory()->count(2)->create()->all();
    }

    public function test_create_normalizes_contacts_and_applies_to_vacancy(): void
    {
        $vacancy = $this->vacancyIn($this->north);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);

        $this->actingAs($recruiter)->postJson('/api/candidates', [
            'full_name' => 'Test Person',
            'phone' => '067 123-45-67',
            'email' => ' Person@Example.TEST ',
            'telegram_username' => '@Test_Person',
            'source' => 'meta_ads',
            'utm' => ['utm_source' => 'facebook'],
            'tags' => ['evening'],
            'vacancy_id' => $vacancy->id,
        ])->assertCreated()
            ->assertJsonPath('data.phone', '+380671234567')
            ->assertJsonPath('data.email', 'person@example.test')
            ->assertJsonPath('data.telegram_username', 'test_person')
            ->assertJsonPath('data.source', 'meta_ads')
            ->assertJsonPath('data.utm.utm_source', 'facebook')
            ->assertJsonPath('data.owner_id', $recruiter->id);

        $candidate = Candidate::query()->where('email', 'person@example.test')->firstOrFail();
        $this->assertSame(1, $candidate->applications()->count());
        $this->assertSame($recruiter->id, $candidate->created_by);
    }

    public function test_dedupe_returns_409_with_existing_id_by_phone_email_or_telegram(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $existing = Candidate::factory()->create([
            'phone' => '+380671112233', 'email' => 'dup@example.test', 'telegram_username' => 'dup_user', 'owner_id' => $recruiter->id,
        ]);

        foreach ([
            ['phone' => '0671112233', 'matched' => 'phone'],
            ['email' => 'DUP@example.test', 'matched' => 'email'],
            ['telegram_username' => 'https://t.me/Dup_User', 'matched' => 'telegram'],
        ] as $case) {
            $matched = $case['matched'];
            unset($case['matched']);
            $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'Someone'] + $case)
                ->assertStatus(409)
                ->assertJsonPath('code', 'duplicate_candidate')
                ->assertJsonPath('existing_id', $existing->id)
                ->assertJsonPath('matched_by', $matched);
        }

        // No "create anyway" any more: the flag is ignored and the duplicate is still refused.
        $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'Twin', 'phone' => '0671112233', 'force_new' => true])
            ->assertStatus(409);
        $this->assertSame(1, Candidate::query()->where('phone', '+380671112233')->count());
    }

    public function test_duplicate_outside_scope_is_not_disclosed(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $south = $this->applied($this->vacancyIn($this->south), ['phone' => '+380675550000', 'email' => 'south@example.test']);

        $response = $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'Probe', 'phone' => '067 555 00 00'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_candidate')
            ->assertJsonPath('restricted', true);
        $this->assertArrayNotHasKey('existing_id', $response->json());
        $this->assertArrayNotHasKey('matched_by', $response->json());

        // Same on edit of an own candidate.
        $own = Candidate::factory()->create(['owner_id' => $recruiter->id]);
        $edit = $this->actingAs($recruiter)->patchJson("/api/candidates/$own->id", ['email' => 'south@example.test'])->assertStatus(409);
        $this->assertArrayNotHasKey('existing_id', $edit->json());

        // Admin sees everything → gets the id.
        $this->actingAs($this->userWith(UserRole::Admin))->postJson('/api/candidates', ['full_name' => 'Probe', 'phone' => '0675550000'])
            ->assertStatus(409)->assertJsonPath('existing_id', $south->candidate_id);
    }

    public function test_db_rejects_duplicate_contacts_even_without_the_pre_check(): void
    {
        Candidate::factory()->create(['phone' => '+380671119999']);

        $this->expectException(UniqueConstraintViolationException::class);
        Candidate::factory()->create(['phone' => '+380671119999']);
    }

    public function test_candidates_without_contacts_are_allowed_many_times(): void
    {
        Candidate::factory()->count(2)->create(['phone' => null, 'email' => null, 'telegram_username' => null]);
        $this->assertSame(2, Candidate::query()->whereNull('phone')->count());
    }

    public function test_invalid_contacts_and_roles(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);

        $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'X Y', 'phone' => '12'])->assertUnprocessable();
        $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'X Y', 'email' => 'nope'])->assertUnprocessable();
        $this->actingAs($recruiter)->postJson('/api/candidates', ['phone' => '0671112233'])->assertUnprocessable();
        $this->actingAs($viewer)->postJson('/api/candidates', ['full_name' => 'X Y'])->assertForbidden();
        // A vacancy in another branch cannot be used.
        $foreign = $this->vacancyIn($this->south);
        $this->actingAs($recruiter)->postJson('/api/candidates', ['full_name' => 'X Y', 'vacancy_id' => $foreign->id])
            ->assertForbidden()->assertJsonPath('code', 'vacancy_out_of_scope');
    }

    public function test_list_is_scoped_and_searchable(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $inNorth = $this->applied($this->vacancyIn($this->north), ['full_name' => 'Alpha North', 'phone' => '+380501234567']);
        $this->applied($this->vacancyIn($this->south), ['full_name' => 'Beta South']);
        Candidate::factory()->create(['full_name' => 'Gamma Own', 'owner_id' => $recruiter->id]);
        Candidate::factory()->create(['full_name' => 'Delta Nobody']);

        $this->actingAs($recruiter)->getJson('/api/candidates')->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($recruiter)->getJson('/api/candidates?q=alpha')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.applications.0.vacancy.id', $inNorth->vacancy_id);
        $this->actingAs($recruiter)->getJson('/api/candidates?q=1234567')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($recruiter)->getJson('/api/candidates?vacancy_id='.$inNorth->vacancy_id.'&status=active&perPage=10')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/candidates')->assertOk()->assertJsonPath('meta.total', 4);
        $this->actingAs($recruiter)->getJson('/api/candidates?status=deleted')->assertUnprocessable();
    }

    public function test_show_card_has_route_with_durations_and_update(): void
    {
        $vacancy = $this->vacancyIn($this->north);
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $application = $this->applied($vacancy, [], now()->subDays(2));
        $this->app->make(ApplicationService::class)->move($recruiter, $application, new MoveData($this->stageAt(3)->id, 'good fit'), now()->subDay());

        $this->actingAs($recruiter)->getJson("/api/candidates/$application->candidate_id")
            ->assertOk()
            ->assertJsonPath('data.applications.0.route.0.stage_id', $this->stageAt(1)->id)
            ->assertJsonPath('data.applications.0.route.0.duration_sec', 86400)
            ->assertJsonPath('data.applications.0.route.1.stage_id', $this->stageAt(3)->id)
            ->assertJsonPath('data.applications.0.route.1.left_at', null)
            ->assertJsonPath('data.applications.0.route.1.reason', 'good fit')
            ->assertJsonPath('data.applications.0.route.1.by.id', $recruiter->id)
            ->assertJsonCount(8, 'data.applications.0.stages');

        $this->actingAs($recruiter)->patchJson("/api/candidates/$application->candidate_id", ['full_name' => 'Renamed', 'tags' => ['a']])
            ->assertOk()->assertJsonPath('data.full_name', 'Renamed')->assertJsonPath('data.tags.0', 'a');
        $other = Candidate::factory()->create(['email' => 'taken@example.test']);
        $this->actingAs($recruiter)->patchJson("/api/candidates/$application->candidate_id", ['email' => 'taken@example.test'])
            ->assertStatus(409)->assertJsonPath('restricted', true); // $other is outside the recruiter's scope

        $stranger = $this->userWith(UserRole::Recruiter, [$this->south]);
        $this->actingAs($stranger)->getJson("/api/candidates/$application->candidate_id")->assertForbidden();
        $this->actingAs($stranger)->patchJson("/api/candidates/$application->candidate_id", ['full_name' => 'X Y'])->assertForbidden();
        $viewer = $this->userWith(UserRole::Viewer, [$this->north]);
        $this->actingAs($viewer)->getJson("/api/candidates/$application->candidate_id")->assertOk();
        $this->actingAs($viewer)->patchJson("/api/candidates/$application->candidate_id", ['full_name' => 'X Y'])->assertForbidden();
    }
}
