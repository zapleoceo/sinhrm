<?php

declare(strict_types=1);

namespace Tests\Feature\MailAgent;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\MailAgent\Models\MailMessage;
use App\Modules\MailAgent\Models\MailSyncRun;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Models\UnknownSender;
use App\Modules\MailAgent\Services\MailSyncJob;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Scripts\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\Support\MailFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Gmail sync end to end with a faked Gmail API and invented mail. */
final class MailSyncTest extends TestCase
{
    use GoogleFixtures;
    use MailFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;

    private const string BODY_MARKER = 'PRIVATE-BODY-MARKER-4242';

    private User $superadmin;

    private User $recruiter;

    private Vacancy $vacancy;

    private Candidate $known;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $this->captureLogs();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
        $branch = Branch::factory()->create();
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $this->vacancy = $this->vacancyIn($branch, $this->recruiter);
        $this->vacancy->update(['title' => 'Менеджер з продажу']);
        $this->known = $this->applied($this->vacancy, ['email' => 'taras.known@example.test'])->candidate;

        SenderRule::query()->create(['pattern' => '@jobs.example.test', 'kind' => 'job_board', 'parser' => 'generic']);
        SenderRule::query()->create(['pattern' => '@news.example.test', 'kind' => 'newsletter']);
    }

    public function test_sync_handles_every_kind_and_is_idempotent(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);
        $this->seedMailbox();
        $this->fakeGmail();

        $counts = $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk()->json('data');

        $this->assertSame(6, $counts['listed']);
        $this->assertSame(6, $counts['processed']);
        $this->assertSame(1, $counts['application']);
        $this->assertSame(1, $counts['touchpoint']);
        $this->assertSame(1, $counts['inbox']);
        $this->assertSame(2, $counts['skipped']);
        $this->assertSame(1, $counts['unknown']);
        $this->assertSame(1, $counts['tasks']);

        // Job-board application → candidate + application + e-mail touchpoint + "call within 1 hour" task.
        $candidate = Candidate::query()->where('email', 'olena.testova@example.test')->firstOrFail();
        $this->assertSame('Олена Тестова', $candidate->full_name);
        $this->assertSame('+380675551234', $candidate->phone);
        $this->assertSame('other', $candidate->source->value);
        $application = Application::query()->where('candidate_id', $candidate->id)->firstOrFail();
        $this->assertSame($this->vacancy->id, $application->vacancy_id);
        $touch = Touchpoint::query()->where('external_id', 'm-app')->firstOrFail();
        $this->assertSame($candidate->id, $touch->candidate_id);
        $this->assertSame('email', $touch->channel->value);
        $this->assertSame('in', $touch->direction->value);
        $this->assertSame('google_gmail', $touch->integration_key);
        $this->assertFalse($touch->via_product);
        $this->assertSame('https://jobs.example.test/resumes/000111', $touch->meta['cv_url']);
        $task = Task::query()->where('candidate_id', $candidate->id)->firstOrFail();
        $this->assertSame('new_applicant', $task->type->value);
        $this->assertSame($this->recruiter->id, $task->assignee_id);
        $this->assertSame($application->id, $task->application_id);
        $this->assertEqualsWithDelta(
            MailMessage::query()->where('gmail_id', 'm-app')->firstOrFail()->received_at->addHour()->timestamp,
            $task->due_at->timestamp,
            1,
        );

        // Mail from a known candidate → touchpoint on their card.
        $this->assertSame($this->known->id, Touchpoint::query()->where('external_id', 'm-cand')->firstOrFail()->candidate_id);
        // Unknown vacancy → Inbox with the parsed fields.
        $inbox = Touchpoint::query()->where('external_id', 'm-inbox')->firstOrFail();
        $this->assertNull($inbox->candidate_id);
        $this->assertSame('Андрій Вигаданий', $inbox->meta['full_name']);
        $this->assertSame('Невідома посада', $inbox->meta['vacancy_title']);
        $this->assertFalse(Candidate::query()->where('full_name', 'Андрій Вигаданий')->exists());
        // Newsletter and our own sent mail → nothing stored but the log line.
        $this->assertFalse(Touchpoint::query()->whereIn('external_id', ['m-news', 'm-sent'])->exists());
        // Unknown sender → queue with address + subject only.
        $unknown = UnknownSender::query()->where('email', 'partner@unknown.example.test')->firstOrFail();
        $this->assertSame('Пропозиція співпраці', $unknown->sample_subject);
        $this->assertSame(1, $unknown->count);
        $this->assertStringNotContainsString(self::BODY_MARKER, (string) json_encode(UnknownSender::query()->get()->toArray()));
        $this->assertStringNotContainsString(self::BODY_MARKER, (string) json_encode(MailMessage::query()->get()->toArray()));
        $this->assertFalse(Touchpoint::query()->where('body', 'like', '%'.self::BODY_MARKER.'%')->exists());
        $this->assertSame(2, SenderRule::query()->where('pattern', '@jobs.example.test')->value('hits'));
        $this->assertLogsDoNotContain(self::BODY_MARKER, self::ACCESS_TOKEN, self::REFRESH_TOKEN, 'olena.testova');

        // Second run: nothing new.
        $before = [Candidate::query()->count(), Touchpoint::query()->count(), Task::query()->count(), Application::query()->count()];
        $again = $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk()->json('data');
        $this->assertSame(6, $again['duplicates']);
        $this->assertSame(0, $again['processed']);
        $this->assertSame($before, [Candidate::query()->count(), Touchpoint::query()->count(), Task::query()->count(), Application::query()->count()]);
        $this->assertSame(1, UnknownSender::query()->value('count'));
        $this->assertStringStartsWith('newer_than:2d -in:chats after:', $this->listQueries[1]);
        $this->assertSame('newer_than:2d -in:chats', $this->listQueries[0]);
    }

    public function test_same_message_ingested_twice_even_without_the_log_is_not_duplicated(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);
        $this->jobBoardMail('m-app', 'Менеджер з продажу', 'Олена Тестова', '+38 (067) 555-12-34', 'olena.testova@example.test');
        $this->fakeGmail();

        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();
        MailMessage::query()->delete();
        MailSyncRun::query()->delete();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();

        $this->assertSame(1, Touchpoint::query()->where('external_id', 'm-app')->count());
        $this->assertSame(1, Task::query()->count());
        $this->assertSame(1, Candidate::query()->where('email', 'olena.testova@example.test')->count());
    }

    public function test_tasks_list_puts_the_overdue_new_applicant_call_first(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);
        Task::query()->create([
            'assignee_id' => $this->recruiter->id, 'type' => 'manual', 'title' => 'Later task', 'due_at' => Carbon::now()->addDay(),
        ]);
        $this->jobBoardMail('m-app', 'Менеджер з продажу', 'Олена Тестова', '0675551234', 'olena.testova@example.test', minutesAgo: 90);
        $this->fakeGmail();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();

        $this->actingAs($this->recruiter)->getJson('/api/tasks?mine=1')->assertOk()
            ->assertJsonPath('data.0.type', 'new_applicant')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.1.title', 'Later task');
    }

    public function test_not_connected_and_access(): void
    {
        Http::fake();
        $admin = User::factory()->withRole(UserRole::Admin)->create();

        $this->postJson('/api/mail/sync')->assertUnauthorized();
        $this->actingAs($admin)->postJson('/api/mail/sync')->assertForbidden();
        $this->actingAs($this->recruiter)->getJson('/api/mail/status')->assertForbidden();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')
            ->assertStatus(422)->assertJsonPath('code', 'google_gmail_not_connected');
        $this->assertSame(['skipped' => 'not_connected'], $this->app->make(MailSyncJob::class)->run(Carbon::now()));
        Http::assertNothingSent();
    }

    public function test_cron_job_runs_the_sync_as_the_connecting_superadmin(): void
    {
        config(['ops.secret' => 'test-ops-secret']);
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);
        $this->jobBoardMail('m-app', 'Менеджер з продажу', 'Олена Тестова', '0675551234', 'olena.testova@example.test');
        $this->fakeGmail();

        $jobs = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-ops-secret'])->assertOk()->json('jobs');

        $this->assertTrue($jobs['mail.sync']['ok']);
        $this->assertSame(1, $jobs['mail.sync']['application']);
        $this->assertSame('not_connected', $jobs['sheets.sync']['skipped']);

        $this->assertSame($this->superadmin->id, Candidate::query()->where('email', 'olena.testova@example.test')->value('created_by'));
        $this->assertSame('cron', MailSyncRun::query()->value('trigger'));
    }

    public function test_revoked_refresh_token_stops_the_sync_with_reconnect_required(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id, expired: true);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertStatus(409)->assertJsonPath('code', 'reconnect_required');

        $this->assertSame('reconnect_required', MailSyncRun::query()->value('error'));
        $this->actingAs($this->superadmin)->getJson('/api/mail/status')->assertOk()
            ->assertJsonPath('data.connection.connected', false)
            ->assertJsonPath('data.connection.error', 'reconnect_required')
            ->assertJsonPath('data.last_sync.error', 'reconnect_required');
        $this->actingAs($this->superadmin)->getJson('/api/dashboard')->assertJsonPath('data.warnings.0.code', 'google_reconnect_required');
    }

    public function test_enabled_ai_still_calls_no_provider(): void
    {
        $this->app->instance(AiPolicy::class, new class implements AiPolicy
        {
            public function enabled(): bool
            {
                return true;
            }
        });
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);
        $this->seedMailbox();
        $this->fakeGmail();

        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk()->assertJsonPath('data.unknown', 1);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://gmail.googleapis.com/'));
        Http::assertNotSent(fn (Request $r): bool => ! str_starts_with($r->url(), 'https://gmail.googleapis.com/'));
    }

    private function seedMailbox(): void
    {
        $this->jobBoardMail('m-app', 'Менеджер з продажу', 'Олена Тестова', '+38 (067) 555-12-34', 'olena.testova@example.test', 180);
        $this->addMail('m-cand', 'Тарас <Taras.Known@example.test>', 'Re: співбесіда', 'Дякую, буду завтра о 10:00.', 170);
        $this->addMail('m-news', 'Digest <digest@news.example.test>', 'Щотижневий дайджест', 'Новини ринку праці.', 160);
        $this->addMail('m-unknown', 'Partner <partner@unknown.example.test>', 'Пропозиція співпраці', 'Текст листа '.self::BODY_MARKER, 150);
        $this->jobBoardMail('m-inbox', 'Невідома посада', 'Андрій Вигаданий', '+380931234567', 'andrii.vyhadanyi@example.test', 140);
        $this->addMail('m-sent', 'Recruiter <recruiting-box@example.test>', 'Запрошення', 'Вихідний лист.', 130, ['SENT']);
    }
}
