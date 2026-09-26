<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Directory\Models\Branch;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFile;
use App\Modules\MailAgent\Models\MailMessage;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Models\PrivacySettings;
use App\Modules\Privacy\Services\RetentionJob;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\CandidateScreening;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/**
 * Personal-data rights (Law No. 2297-VI): export completeness, erase wipes every personal field (incl. files),
 * report rows survive, access control, retention job. Synthetic data only.
 */
final class PersonalDataApiTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->branch = Branch::factory()->create(['name' => 'Synthetic Branch']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A candidate with everything: contacts, tags, profile link, note, message, meeting, screening, task, mail log. */
    private function fullCandidate(): Application
    {
        $application = $this->applied($this->vacancyIn($this->branch), [
            'full_name' => 'Synthetic Person', 'phone' => '+380500000001', 'email' => 'synthetic@example.test',
            'telegram_username' => 'synthetic_tg', 'tags' => ['senior'],
        ]);
        $candidateId = $application->candidate_id;
        $application->update(['rejected_note' => 'Private reject note']);
        StageChange::query()->where('application_id', $application->id)->update(['reason' => 'Private stage comment']);
        DB::table('candidate_profile_urls')->insert(['candidate_id' => $candidateId, 'site' => 'linkedin', 'url' => 'https://linkedin.test/in/synthetic', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['note', 'Private note'], ['telegram', 'Private message'], ['meeting', 'Interview']] as [$channel, $body]) {
            Touchpoint::query()->create([
                'candidate_id' => $candidateId, 'application_id' => $application->id, 'channel' => $channel, 'direction' => 'out',
                'occurred_at' => now(), 'body' => $body, 'meta' => ['meet_link' => 'https://meet.test/abc'], 'external_id' => 'ext-'.$channel,
            ]);
        }
        CandidateScreening::query()->create([
            'application_id' => $application->id, 'candidate_id' => $candidateId, 'vacancy_id' => $application->vacancy_id,
            'status' => 'done', 'trigger' => 'manual', 'score' => 80, 'verdict' => 'fit', 'summary' => 'Private summary',
            'strengths' => ['Private strength'], 'gaps' => [], 'questions' => [], 'prompt_version' => 'screening.v4',
        ]);
        Task::query()->create([
            'assignee_id' => User::factory()->create()->id, 'candidate_id' => $candidateId, 'application_id' => $application->id,
            'type' => 'manual', 'title' => 'Call Synthetic Person', 'due_at' => now(),
        ]);
        MailMessage::query()->create([
            'gmail_id' => 'g-1', 'received_at' => now(), 'sender' => 'synthetic@example.test', 'subject' => 'CV of Synthetic Person',
            'outcome' => 'application', 'candidate_id' => $candidateId,
        ]);

        return $application;
    }

    public function test_only_superadmin_and_admin_have_access(): void
    {
        $id = $this->fullCandidate()->candidate_id;
        $this->getJson("/api/privacy/candidate/{$id}/export")->assertUnauthorized();
        foreach ([UserRole::Recruiter, UserRole::HrManager, UserRole::Viewer, UserRole::Employee] as $role) {
            $user = $this->userWith($role, [$this->branch]);
            $this->actingAs($user)->getJson("/api/privacy/candidate/{$id}/export")->assertForbidden();
            $this->actingAs($user)->postJson("/api/privacy/candidate/{$id}/erase", ['reason' => 'x request', 'confirm' => true])->assertForbidden();
            $this->actingAs($user)->putJson('/api/privacy/settings', ['retention_rejected_months' => 6])->assertForbidden();
        }
        $this->actingAs($this->userWith(UserRole::Admin))->getJson("/api/privacy/candidate/{$id}/export")->assertOk();
        $this->actingAs($this->userWith(UserRole::Superadmin))->getJson("/api/privacy/candidate/{$id}/export")->assertOk();
        $this->actingAs($this->userWith(UserRole::Admin))->getJson('/api/privacy/candidate/999999/export')->assertNotFound();
    }

    public function test_export_contains_every_section_as_json_and_html(): void
    {
        $id = $this->fullCandidate()->candidate_id;
        $admin = $this->userWith(UserRole::Admin);

        $response = $this->actingAs($admin)->get("/api/privacy/candidate/{$id}/export");
        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="personal-data-candidate-'.$id.'.json"');
        $export = json_decode((string) $response->getContent(), true);
        $r = $export['sections']['recruiting'];
        $this->assertSame('Synthetic Person', $r['profile']['full_name']);
        $this->assertSame('+380500000001', $r['profile']['phone']);
        $this->assertSame('https://linkedin.test/in/synthetic', $r['profile_urls'][0]['url']);
        $this->assertSame('Private reject note', $r['applications'][0]['rejected_note']);
        $this->assertSame('Private stage comment', $r['applications'][0]['stage_history'][0]['reason']);
        $this->assertSame('Private note', $r['notes'][0]['body']);
        $this->assertSame('Interview', $r['meetings'][0]['body']);
        $this->assertContains('Private message', array_column($r['touchpoints'], 'body'));
        $this->assertSame('Private summary', $r['screenings'][0]['summary']);
        $this->assertSame('Call Synthetic Person', $export['sections']['tasks_and_evaluations']['tasks'][0]['title']);
        $this->assertSame('CV of Synthetic Person', $export['sections']['mail_log'][0]['subject']);
        $this->assertSame([], $export['sections']['documents']);

        $html = $this->actingAs($admin)->get("/api/privacy/candidate/{$id}/export?format=html");
        $html->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('Synthetic Person', (string) $html->getContent());
        $this->assertStringContainsString('Private note', (string) $html->getContent());

        $this->assertSame(2, PrivacyRequest::query()->where('action', 'export')->where('actor_id', $admin->id)->count());
    }

    public function test_erase_needs_reason_and_confirmation(): void
    {
        $id = $this->fullCandidate()->candidate_id;
        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($admin)->postJson("/api/privacy/candidate/{$id}/erase", ['reason' => 'request'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->actingAs($admin)->postJson("/api/privacy/candidate/{$id}/erase", ['confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('Synthetic Person', Candidate::query()->findOrFail($id)->full_name);
    }

    public function test_erase_wipes_every_personal_field_and_keeps_stats(): void
    {
        $application = $this->fullCandidate();
        $id = $application->candidate_id;
        $touchpoints = Touchpoint::query()->where('candidate_id', $id)->count();
        $admin = $this->userWith(UserRole::Superadmin);

        $this->actingAs($admin)->postJson("/api/privacy/candidate/{$id}/erase", ['reason' => 'Written request of 2026-10-09', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.erased', true);

        $c = Candidate::query()->findOrFail($id);
        $this->assertSame('Видалений кандидат #'.$id, $c->full_name);
        $this->assertNull($c->phone);
        $this->assertNull($c->email);
        $this->assertNull($c->telegram_username);
        $this->assertNull($c->tags);
        $this->assertNotNull($c->anonymized_at);
        $this->assertSame(0, DB::table('candidate_profile_urls')->where('candidate_id', $id)->count());
        $this->assertNull(Application::query()->findOrFail($application->id)->rejected_note);
        $this->assertSame(0, StageChange::query()->where('application_id', $application->id)->whereNotNull('reason')->count());
        $this->assertSame(0, Touchpoint::query()->where('candidate_id', $id)
            ->where(fn ($q) => $q->whereNotNull('body')->orWhereNotNull('meta')->orWhereNotNull('external_id'))->count());
        $this->assertSame(0, CandidateScreening::query()->where('candidate_id', $id)->count());
        $this->assertSame('Видалений кандидат #'.$id, Task::query()->where('candidate_id', $id)->value('title'));
        $mail = MailMessage::query()->where('candidate_id', $id)->firstOrFail();
        $this->assertNull($mail->sender);
        $this->assertNull($mail->subject);

        // Nothing personal is left anywhere in the candidate's rows.
        $dump = json_encode([
            Candidate::query()->whereKey($id)->get()->toArray(),
            Application::query()->where('candidate_id', $id)->get()->toArray(),
            Touchpoint::query()->where('candidate_id', $id)->get()->toArray(),
            Task::query()->where('candidate_id', $id)->get()->toArray(),
            MailMessage::query()->where('candidate_id', $id)->get()->toArray(),
        ], JSON_UNESCAPED_UNICODE);
        foreach (['Synthetic', 'synthetic', '380500000001', 'Private', 'meet.test', 'ext-'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $dump, $needle);
        }

        // Stats survive: application (vacancy, stage, status), stage history and touchpoint rows.
        $kept = Application::query()->findOrFail($application->id);
        $this->assertSame($application->vacancy_id, $kept->vacancy_id);
        $this->assertSame($application->stage_id, $kept->stage_id);
        $this->assertSame($touchpoints, Touchpoint::query()->where('candidate_id', $id)->count());
        $this->assertSame(1, StageChange::query()->where('application_id', $application->id)->count());

        $log = PrivacyRequest::query()->where('action', 'erase')->firstOrFail();
        $this->assertSame('Written request of 2026-10-09', $log->reason);
        $this->assertSame($admin->id, $log->actor_id);

        // Idempotent.
        $this->actingAs($admin)->postJson("/api/privacy/candidate/{$id}/erase", ['reason' => 'again', 'confirm' => true])->assertOk();
    }

    public function test_hired_candidate_and_active_employee_are_not_erased(): void
    {
        $application = $this->fullCandidate();
        $employee = Employee::factory()->create(['candidate_id' => $application->candidate_id, 'branch_id' => $this->branch->id]);
        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($admin)->postJson("/api/privacy/candidate/{$application->candidate_id}/erase", ['reason' => 'request', 'confirm' => true])
            ->assertStatus(409)->assertJsonPath('code', 'hired');
        $this->actingAs($admin)->postJson("/api/privacy/employee/{$employee->id}/erase", ['reason' => 'request', 'confirm' => true])
            ->assertStatus(409)->assertJsonPath('code', 'not_terminated');
        $this->assertSame('Synthetic Person', Candidate::query()->findOrFail($application->candidate_id)->full_name);
    }

    public function test_former_employee_export_and_erase_including_files(): void
    {
        $employee = Employee::factory()->create([
            'full_name' => 'Former Synthetic', 'personal_email' => 'former@example.test', 'phone' => '+380500000002',
            'address' => 'Private address', 'emergency_contact' => 'Private contact', 'birth_date' => '1990-01-01',
            'status' => EmployeeStatus::Terminated->value, 'fired_at' => '2026-09-01', 'branch_id' => $this->branch->id,
        ]);
        EmployeeChangeRequest::query()->create(['employee_id' => $employee->id, 'changes' => ['phone' => '+380500000003'], 'status' => 'pending']);
        $draft = Document::query()->create(['employee_id' => $employee->id, 'title' => 'Draft of Former Synthetic', 'status' => 'draft', 'content_md' => 'Private text']);
        DocumentFile::query()->create(['document_id' => $draft->id, 'filename' => 'scan.pdf', 'mime' => 'application/pdf', 'size' => 3, 'sha256' => str_repeat('a', 64), 'content' => 'YWJj']);
        $signed = Document::query()->create(['employee_id' => $employee->id, 'title' => 'Contract', 'status' => 'signed', 'content_md' => 'Contract text']);
        $admin = $this->userWith(UserRole::Admin);

        $export = json_decode((string) $this->actingAs($admin)->get("/api/privacy/employee/{$employee->id}/export")->assertOk()->getContent(), true);
        $this->assertSame('former@example.test', $export['sections']['employee']['profile']['personal_email']);
        $this->assertSame(['phone' => '+380500000003'], $export['sections']['employee']['change_requests'][0]['changes']);
        $this->assertSame(['filename' => 'scan.pdf', 'mime' => 'application/pdf', 'size' => 3], $export['sections']['documents'][0]['file']);
        $this->assertArrayNotHasKey('content', $export['sections']['documents'][0]['file']);

        $this->actingAs($admin)->postJson("/api/privacy/employee/{$employee->id}/erase", ['reason' => 'request', 'confirm' => true])->assertOk();

        $e = Employee::query()->findOrFail($employee->id);
        $this->assertSame('Видалений співробітник #'.$employee->id, $e->full_name);
        foreach (['personal_email', 'work_email', 'phone', 'address', 'emergency_contact', 'birth_date', 'avatar_url', 'custom_fields'] as $field) {
            $this->assertNull($e->{$field}, $field);
        }
        $this->assertSame('2026-09-01', $e->fired_at?->toDateString());
        $this->assertSame(0, EmployeeChangeRequest::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(0, DocumentFile::query()->where('document_id', $draft->id)->count());
        $this->assertNull(Document::query()->findOrFail($draft->id)->content_md);
        // Signed personnel documents are kept for the period set by law.
        $this->assertSame('Contract text', Document::query()->findOrFail($signed->id)->content_md);
    }

    public function test_retention_job_is_off_by_default_and_anonymizes_old_rejected_candidates(): void
    {
        $job = $this->app->make(RetentionJob::class);
        $this->assertContains(RetentionJob::class, array_map(static fn (ScheduledJob $j): string => $j::class, iterator_to_array($this->app->tagged(ScheduledJob::class))));

        $old = $this->fullCandidate();
        $old->update(['status' => 'rejected', 'closed_at' => '2026-01-01 00:00:00']);
        $recent = $this->applied($this->vacancyIn($this->branch), ['full_name' => 'Recent Reject']);
        $recent->update(['status' => 'rejected', 'closed_at' => '2026-09-01 00:00:00']);
        $active = $this->applied($this->vacancyIn($this->branch), ['full_name' => 'Active Candidate']);

        $this->assertSame(['enabled' => false], $job->run(Carbon::now()));
        $this->assertSame('Synthetic Person', Candidate::query()->findOrFail($old->candidate_id)->full_name);

        $this->actingAs($this->userWith(UserRole::Admin))->putJson('/api/privacy/settings', ['retention_rejected_months' => 6])
            ->assertOk()->assertJsonPath('data.retention_rejected_months', 6);
        $this->assertSame(['enabled' => true, 'erased' => 1, 'skipped' => 0], $job->run(Carbon::now()));

        $this->assertNotNull(Candidate::query()->findOrFail($old->candidate_id)->anonymized_at);
        $this->assertSame('Recent Reject', Candidate::query()->findOrFail($recent->candidate_id)->full_name);
        $this->assertSame('Active Candidate', Candidate::query()->findOrFail($active->candidate_id)->full_name);
        $this->assertSame('retention', PrivacyRequest::query()->where('action', 'erase')->value('trigger'));
        // Second run: nothing left.
        $this->assertSame(['enabled' => true, 'erased' => 0, 'skipped' => 0], $job->run(Carbon::now()));

        $this->actingAs($this->userWith(UserRole::Admin))->putJson('/api/privacy/settings', ['retention_rejected_months' => null])->assertOk();
        $this->assertNull(PrivacySettings::current()->retention_rejected_months);
    }
}
