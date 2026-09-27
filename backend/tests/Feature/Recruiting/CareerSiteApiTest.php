<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Models\ModuleSetting;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\CareerSiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Synthetic data only: the repository is public. */
final class CareerSiteApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Vacancy $vacancy;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $vacancy = $this->actingAs($recruiter)->postJson('/api/vacancies', [
            'title' => 'Sales Manager', 'branch_id' => $branch->id, 'published' => true, 'public_description' => 'Join us',
        ])->assertCreated()->assertJsonPath('data.published', true)->json('data');
        $this->vacancy = Vacancy::query()->findOrFail($vacancy['id']);
        $this->app['auth']->forgetGuards();
    }

    public function test_lists_only_published_open_vacancies_without_login(): void
    {
        $this->vacancyIn(Branch::factory()->create());
        $this->assertSame('sales-manager-'.$this->vacancy->id, $this->vacancy->slug);

        $this->getJson('/api/public/vacancies')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $this->vacancy->slug)->assertJsonMissingPath('data.0.recruiter_id');
        $this->getJson('/api/public/vacancies/'.$this->vacancy->slug)->assertOk()->assertJsonPath('data.description', 'Join us');

        $this->vacancy->update(['status' => 'closed']);
        $this->getJson('/api/public/vacancies/'.$this->vacancy->slug)->assertNotFound();
    }

    public function test_apply_creates_candidate_application_submission_and_task(): void
    {
        $cv = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->post('/api/public/vacancies/'.$this->vacancy->slug.'/apply', [
            'name' => 'Olena Sample', 'email' => 'olena.sample@example.test', 'phone' => '+380 67 000 00 01',
            'message' => 'Hello', 'consent' => '1', 'cv' => $cv,
        ], ['Accept' => 'application/json'])->assertCreated();

        $candidate = Candidate::query()->where('email', 'olena.sample@example.test')->firstOrFail();
        $this->assertDatabaseHas('candidates', ['id' => $candidate->id, 'added_via' => 'career_site', 'source' => 'site']);
        $this->assertSame('site', DB::table('acquisition_channels')->where('id', $candidate->channel_id)->value('code'));
        $application = $candidate->applications()->firstOrFail();
        $this->assertSame($this->defaultPipeline()->stages()->orderBy('position')->value('id'), $application->stage_id);
        $submission = CareerSubmission::query()->firstOrFail();
        $this->assertSame('application/pdf', $submission->cv_mime);
        $this->assertDatabaseHas('tasks', ['application_id' => $application->id, 'assignee_id' => $this->vacancy->recruiter_id]);
    }

    public function test_validation_requires_consent_contacts_and_cv_type(): void
    {
        $url = '/api/public/vacancies/'.$this->vacancy->slug.'/apply';
        $this->postJson($url, ['name' => 'X', 'email' => 'x@example.test'])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson($url, ['name' => 'X', 'email' => 'nope', 'consent' => true])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->post($url, [
            'name' => 'X', 'email' => 'x@example.test', 'consent' => '1', 'cv' => UploadedFile::fake()->create('cv.exe', 10),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('cv');
        $this->post($url, [
            'name' => 'X', 'email' => 'x@example.test', 'consent' => '1', 'cv' => UploadedFile::fake()->create('cv.pdf', 3000),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('cv');
        $this->assertSame(0, Candidate::query()->count());
    }

    public function test_honeypot_and_rate_limit(): void
    {
        $url = '/api/public/vacancies/'.$this->vacancy->slug.'/apply';
        $this->postJson($url, ['name' => 'Bot', 'email' => 'bot@example.test', 'consent' => true, 'website' => 'http://spam.test'])
            ->assertCreated();
        $this->assertSame(0, Candidate::query()->count());

        for ($i = 0; $i < CareerSiteService::MAX_PER_HOUR; $i++) {
            $this->postJson($url, ['name' => 'P'.$i, 'email' => "p$i@example.test", 'consent' => true])->assertCreated();
        }
        $this->postJson($url, ['name' => 'Late', 'email' => 'late@example.test', 'consent' => true])
            ->assertStatus(429)->assertJsonPath('message', 'too_many_requests');
    }

    public function test_dedup_by_email_reuses_candidate_and_application(): void
    {
        $existing = Candidate::factory()->create(['email' => 'olena.sample@example.test', 'full_name' => 'Olena Old']);
        $url = '/api/public/vacancies/'.$this->vacancy->slug.'/apply';

        $this->postJson($url, ['name' => 'Olena New', 'email' => 'OLENA.sample@example.test', 'consent' => true])->assertCreated();
        $this->postJson($url, ['name' => 'Olena New', 'email' => 'olena.sample@example.test', 'consent' => true])->assertCreated();

        $this->assertSame(1, Candidate::query()->count());
        $this->assertSame(1, $existing->applications()->count());
        $this->assertSame(2, CareerSubmission::query()->where('candidate_id', $existing->id)->count());
    }

    public function test_disabled_recruiting_hides_public_pages(): void
    {
        ModuleSetting::query()->updateOrCreate(['module' => 'recruiting'], ['enabled' => false, 'roles' => UserRole::values()]);
        $this->app->forgetScopedInstances();
        Cache::forget(ModuleAccess::CACHE_KEY);

        $this->getJson('/api/public/vacancies')->assertNotFound();
        $this->postJson('/api/public/vacancies/'.$this->vacancy->slug.'/apply', [])->assertNotFound();
    }
}
