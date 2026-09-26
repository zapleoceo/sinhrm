<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\DTO\MeetingData;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Support\MimeText;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\People\Models\Employee;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepSnapshot;
use App\Modules\Workflows\Enums\AssigneeRule;
use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Models\WorkflowTemplate;
use App\Modules\Workflows\Services\AssigneeResolver;
use App\Modules\Workflows\Support\ExecutorRegistry;
use App\Modules\Workflows\Support\WebhookSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\Support\GoogleFixtures;
use Tests\TestCase;

/** Step executors in isolation (registry, Google, webhook guard/signature), assignee rules. Synthetic data. */
final class ExecutorsTest extends TestCase
{
    use GoogleFixtures, RefreshDatabase;

    public function test_every_action_has_exactly_one_executor_with_config_rules(): void
    {
        $registry = $this->app->make(ExecutorRegistry::class);

        $this->assertSame(StepAction::cases(), $registry->actions());
        foreach (StepAction::cases() as $action) {
            $this->assertSame($action, $registry->for($action)->action());
        }
    }

    public function test_snapshot_round_trip_and_typed_config_access(): void
    {
        $snapshot = new StepSnapshot('T', StepAction::AddCalendarEvent, -3, AssigneeRule::SpecificUser, 7, ['time' => '09:30', 'online' => 'true', 'duration_minutes' => '45', 'title' => ' ']);

        $copy = StepSnapshot::fromArray($snapshot->toArray());

        $this->assertEquals($snapshot, $copy);
        $this->assertSame('09:30', $copy->string('time'));
        $this->assertNull($copy->string('title'));
        $this->assertTrue($copy->bool('online'));
        $this->assertSame(45, $copy->int('duration_minutes'));
    }

    public function test_signature_is_hmac_sha256_of_the_body(): void
    {
        $this->assertSame('sha256='.hash_hmac('sha256', '{"a":1}', 'k-0123456789abcdef'), WebhookSecrets::sign('{"a":1}', 'k-0123456789abcdef'));
    }

    public function test_calendar_event_uses_the_due_day_time_and_attendees(): void
    {
        $this->configureGoogleClient();
        $admin = User::factory()->withRole(UserRole::Admin)->create(['email' => 'hr@example.test']);
        $this->connectGoogle(GoogleService::Calendar, $admin->id);
        $calendar = new class implements CalendarClient
        {
            /** @var list<array{meeting: MeetingData, attendees: list<string>}> */
            public array $calls = [];

            public function insertEvent(MeetingData $meeting, array $attendees, string $requestId): array
            {
                $this->calls[] = ['meeting' => $meeting, 'attendees' => $attendees];

                return ['event_id' => 'evt-1', 'html_link' => null, 'meet_link' => null];
            }
        };
        $this->app->instance(CalendarClient::class, $calendar);
        $context = $this->context(new StepSnapshot('Intro', StepAction::AddCalendarEvent, 0, AssigneeRule::HrAdmin, null, ['time' => '14:30', 'duration_minutes' => 30, 'online' => true]), $admin->id, ['work_email' => 'New.Person@Example.test']);

        $outcome = $this->app->make(ExecutorRegistry::class)->for(StepAction::AddCalendarEvent)->execute($context);

        $this->assertSame(['event_id' => 'evt-1'], $outcome->result);
        $this->assertSame('done', $outcome->status->value);
        $call = $calendar->calls[0];
        $this->assertSame('2026-10-05 14:30', $call['meeting']->start->format('Y-m-d H:i'));
        $this->assertSame(30, $call['meeting']->durationMinutes);
        $this->assertTrue($call['meeting']->isOnline());
        $this->assertSame(['new.person@example.test', 'hr@example.test'], $call['attendees']);
    }

    public function test_read_only_gmail_asks_to_reconnect(): void
    {
        $this->configureGoogleClient();
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->connectGoogle(GoogleService::Gmail, $admin->id, scopes: GoogleService::Gmail->requiredScopes());
        Http::fake();

        $outcome = $this->app->make(ExecutorRegistry::class)->for(StepAction::SendEmailTemplate)
            ->execute($this->context(new StepSnapshot('Mail', StepAction::SendEmailTemplate, 0, AssigneeRule::HrAdmin, null, ['subject' => 's', 'body' => 'b'])));

        $this->assertSame(['reason' => 'reconnect_to_send'], $outcome->result);
        Http::assertNothingSent();
    }

    public function test_send_email_step_sends_through_gmail(): void
    {
        $this->configureGoogleClient();
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->connectGoogle(GoogleService::Gmail, $admin->id);
        Http::preventStrayRequests();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'sent-1', 'threadId' => 'thr-1'])]);
        $snapshot = new StepSnapshot('Mail', StepAction::SendEmailTemplate, 0, AssigneeRule::HrAdmin, null, ['subject' => 'Вітаємо, {{name}}', 'body' => 'Перший день <b>завтра</b>']);

        $outcome = $this->app->make(ExecutorRegistry::class)->for(StepAction::SendEmailTemplate)
            ->execute($this->context($snapshot, null, ['full_name' => 'Олена Тест', 'work_email' => 'Olena@Example.test']));

        $this->assertSame('done', $outcome->status->value);
        $this->assertSame(['message_id' => 'sent-1'], $outcome->result);
        Http::assertSent(function (Request $r): bool {
            $raw = MimeText::decodeBase64Url((string) $r['raw']);

            return $r->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
                && str_contains($raw, 'To: =?UTF-8?B?')
                && str_contains($raw, '<olena@example.test>')
                && str_contains($raw, 'Subject: =?UTF-8?B?'.base64_encode('Вітаємо, Олена Тест').'?=')
                && str_contains((string) base64_decode($this->htmlPart($raw)), '&lt;b&gt;завтра&lt;/b&gt;');
        });
    }

    public function test_send_email_step_without_recipient_is_skipped(): void
    {
        $this->configureGoogleClient();
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->connectGoogle(GoogleService::Gmail, $admin->id);
        Http::fake();

        $outcome = $this->app->make(ExecutorRegistry::class)->for(StepAction::SendEmailTemplate)
            ->execute($this->context(new StepSnapshot('Mail', StepAction::SendEmailTemplate, 0, AssigneeRule::HrAdmin, null, ['subject' => 's', 'body' => 'b']), null, ['work_email' => null, 'personal_email' => null]));

        $this->assertSame(['reason' => 'no_recipient'], $outcome->result);
        Http::assertNothingSent();
    }

    public function test_webhook_guard_codes(): void
    {
        Http::fake();
        $this->app->instance(HostResolver::class, new FakeHostResolver(['meta.example.test' => ['169.254.169.254']]));
        $executor = $this->app->make(ExecutorRegistry::class)->for(StepAction::Webhook);
        $cases = [
            'http://hooks.example.test/x' => 'invalid_url',
            'https://hooks.example.test:8443/x' => 'blocked_port',
            'https://meta.example.test/x' => 'blocked_host',
            'https://127.0.0.1/x' => 'blocked_host',
        ];
        foreach ($cases as $url => $code) {
            $outcome = $executor->execute($this->context(new StepSnapshot('Hook', StepAction::Webhook, 0, AssigneeRule::HrAdmin, null, ['url' => $url])));
            $this->assertSame(['error' => $code], $outcome->result, $url);
        }
        Http::assertNothingSent();
    }

    public function test_assignee_rules(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();
        $blocked = User::factory()->blocked()->create();
        $managerUser = User::factory()->create();
        $manager = Employee::factory()->create(['user_id' => $managerUser->id]);
        $employee = Employee::factory()->create(['user_id' => $blocked->id, 'manager_id' => $manager->id]);
        $resolver = $this->app->make(AssigneeResolver::class);
        $rule = static fn (AssigneeRule $r, ?int $user = null): StepSnapshot => new StepSnapshot('x', StepAction::CreateTask, 0, $r, $user, []);

        $this->assertNull($resolver->resolve($rule(AssigneeRule::Employee), $employee, null), 'blocked login is nobody');
        $this->assertSame($managerUser->id, $resolver->resolve($rule(AssigneeRule::Manager), $employee, null));
        $this->assertSame($admin->id, $resolver->resolve($rule(AssigneeRule::HrAdmin), $employee, $recruiter), 'non-admin starter → first admin');
        $this->assertSame($recruiter->id, $resolver->resolve($rule(AssigneeRule::SpecificUser, $recruiter->id), $employee, null));
        $this->assertNull($resolver->resolve($rule(AssigneeRule::SpecificUser, $blocked->id), $employee, null));
    }

    /** @param  array<string, mixed>  $employeeAttributes */
    private function context(StepSnapshot $snapshot, ?int $assigneeId = null, array $employeeAttributes = []): StepContext
    {
        $now = Carbon::parse('2026-10-05 12:00:00');
        $employee = Employee::factory()->create($employeeAttributes);
        $template = WorkflowTemplate::query()->create(['name' => 'T', 'kind' => 'custom']);
        $run = WorkflowRun::query()->create(['template_id' => $template->id, 'employee_id' => $employee->id, 'template_name' => 'T', 'anchor_date' => '2026-10-05']);
        $step = WorkflowRunStep::query()->create([
            'run_id' => $run->id, 'position' => 0, 'snapshot' => $snapshot->toArray(), 'assignee_id' => $assigneeId,
            'due_at' => $now->copy()->startOfDay(),
        ]);

        return new StepContext($run, $step, $snapshot, $employee, $now);
    }

    /** Base64 body of the HTML part of a raw MIME message. */
    private function htmlPart(string $raw): string
    {
        $rest = substr($raw, (int) strpos($raw, 'Content-Type: text/html'));
        $start = (int) strpos($rest, "\r\n\r\n") + 4;

        return str_replace("\r\n", '', substr($rest, $start, (int) strpos($rest, "\r\n--", $start) - $start));
    }
}
