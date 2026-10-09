<?php

declare(strict_types=1);

namespace Tests\Feature\Recruiting;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\CareerSubmission;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/**
 * GET /api/applications/{id}/cv — the CV a candidate sent from the career site. Synthetic data only: the repository
 * is public.
 */
final class CandidateCvDownloadTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    private Branch $north;

    private Vacancy $vacancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = Branch::factory()->create();
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $vacancy = $this->actingAs($recruiter)->postJson('/api/vacancies', [
            'title' => 'Synthetic CV Vacancy', 'branch_id' => $this->north->id, 'published' => true, 'public_description' => 'Synthetic',
        ])->assertCreated()->json('data');
        $this->vacancy = Vacancy::query()->findOrFail($vacancy['id']);
        $this->app['auth']->forgetGuards();
    }

    public function test_career_site_cv_reaches_the_card_and_downloads_as_an_attachment(): void
    {
        $application = $this->applyWithCv('Резюме Олени.pdf');
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);

        $card = $this->actingAs($recruiter)->getJson('/api/candidates/'.$application->candidate_id)->assertOk();
        $card->assertJsonPath('data.applications.0.cv.filename', 'Резюме Олени.pdf')
            ->assertJsonPath('data.applications.0.cv.size', strlen(self::PDF))
            ->assertJsonPath('data.applications.0.cv.mime', 'application/pdf')
            ->assertJsonMissingPath('data.applications.0.cv.content');
        $this->assertStringNotContainsString(base64_encode(self::PDF), (string) $card->getContent());

        $response = $this->actingAs($recruiter)->get('/api/applications/'.$application->id.'/cv')->assertOk();
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen(self::PDF), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('Резюме Олени.pdf'), $disposition);
    }

    public function test_access_matrix_follows_application_visibility(): void
    {
        $application = $this->applyWithCv('cv.pdf');
        $south = Branch::factory()->create();
        $manager = $this->userWith(UserRole::Employee);
        $this->vacancy->forceFill(['hiring_manager_id' => $manager->id])->save();
        $interviewer = $this->userWith(UserRole::Employee);
        $application->interviewers()->attach($interviewer->id);
        $owner = $this->userWith(UserRole::Recruiter);
        $application->candidate->forceFill(['owner_id' => $owner->id])->save();
        $otherInterviewer = $this->userWith(UserRole::Employee);
        $this->applied($this->vacancyIn($south))->interviewers()->attach($otherInterviewer->id);

        $url = '/api/applications/'.$application->id.'/cv';
        $cases = [
            'recruiter of the branch' => [$this->userWith(UserRole::Recruiter, [$this->north]), 200],
            'viewer of the branch' => [$this->userWith(UserRole::Viewer, [$this->north]), 200],
            'admin' => [$this->userWith(UserRole::Admin), 200],
            'hr manager' => [$this->userWith(UserRole::HrManager), 200],
            'hiring manager' => [$manager, 200],
            'interviewer' => [$interviewer, 200],
            'recruiter of another branch' => [$this->userWith(UserRole::Recruiter, [$south]), 404],
            'candidate owner only' => [$owner, 404],
            'interviewer of another application' => [$otherInterviewer, 404],
            'employee of the branch' => [$this->userWith(UserRole::Employee, [$this->north]), 404],
        ];
        foreach ($cases as $label => [$user, $status]) {
            /** @var User $user */
            $this->assertSame($status, $this->actingAs($user)->get($url)->getStatusCode(), $label);
        }

        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_missing_cv_is_not_found(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $withoutCv = $this->applied($this->vacancy);
        CareerSubmission::query()->create([
            'vacancy_id' => $this->vacancy->id, 'candidate_id' => $withoutCv->candidate_id, 'application_id' => $withoutCv->id,
            'consent_at' => now(), 'ip_hash' => str_repeat('a', 64),
        ]);

        $this->actingAs($recruiter)->getJson('/api/candidates/'.$withoutCv->candidate_id)->assertOk()
            ->assertJsonPath('data.applications.0.cv', null);
        $this->actingAs($recruiter)->get('/api/applications/'.$withoutCv->id.'/cv')->assertNotFound();
        $this->actingAs($recruiter)->get('/api/applications/999999/cv')->assertNotFound();

        $corrupt = $this->applied($this->vacancy);
        $this->submission($corrupt, 'broken.pdf', '!!not base64!!');
        $this->actingAs($recruiter)->get('/api/applications/'.$corrupt->id.'/cv')->assertNotFound();
    }

    public function test_newest_cv_wins_and_type_comes_from_the_bytes(): void
    {
        $recruiter = $this->userWith(UserRole::Recruiter, [$this->north]);
        $application = $this->applied($this->vacancy);
        $this->submission($application, 'old.pdf', base64_encode(self::PDF));
        // The stored MIME is not trusted: the bytes decide.
        $this->submission($application, 'new.pdf', base64_encode(self::PDF.'%new'), 'text/html');

        $response = $this->actingAs($recruiter)->get('/api/applications/'.$application->id.'/cv')->assertOk();
        $this->assertSame(self::PDF.'%new', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('new.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->actingAs($recruiter)->getJson('/api/candidates/'.$application->candidate_id)
            ->assertJsonPath('data.applications.0.cv.filename', 'new.pdf');
    }

    public function test_personal_data_export_lists_the_cv_and_erase_removes_it(): void
    {
        $application = $this->applyWithCv('cv.pdf');
        $admin = $this->userWith(UserRole::Superadmin);
        $id = $application->candidate_id;

        $export = json_decode((string) $this->actingAs($admin)->get("/api/privacy/candidate/{$id}/export")->assertOk()->getContent(), true);
        $this->assertSame(['filename' => 'cv.pdf', 'mime' => 'application/pdf', 'size' => strlen(self::PDF)],
            $export['sections']['recruiting']['career_submissions'][0]['cv']);

        $this->actingAs($admin)->postJson("/api/privacy/candidate/{$id}/erase", ['reason' => 'Synthetic request', 'confirm' => true])->assertOk();

        $this->actingAs($admin)->get('/api/applications/'.$application->id.'/cv')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/candidates/'.$id)->assertOk()->assertJsonPath('data.applications.0.cv', null);
        $row = CareerSubmission::query()->where('candidate_id', $id)->firstOrFail();
        $this->assertNull($row->cv_content);
        $this->assertNull($row->cv_filename);
        $this->assertNull($row->cv_size);
    }

    private function applyWithCv(string $name): Application
    {
        $this->post('/api/public/vacancies/'.$this->vacancy->slug.'/apply', [
            'name' => 'Synthetic Applicant', 'email' => 'synthetic.applicant@example.test', 'consent' => '1',
            'cv' => UploadedFile::fake()->createWithContent($name, self::PDF),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Application::query()->where('vacancy_id', $this->vacancy->id)->firstOrFail();
    }

    private function submission(Application $application, string $name, string $content, string $mime = 'application/pdf'): void
    {
        CareerSubmission::query()->create([
            'vacancy_id' => $application->vacancy_id, 'candidate_id' => $application->candidate_id, 'application_id' => $application->id,
            'consent_at' => now(), 'ip_hash' => str_repeat('b', 64),
            'cv_filename' => $name, 'cv_mime' => $mime, 'cv_size' => strlen($content), 'cv_sha256' => hash('sha256', $content),
            'cv_content' => $content,
        ]);
    }
}
