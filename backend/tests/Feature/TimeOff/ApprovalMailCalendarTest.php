<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\Core\Contracts\UserNotifier;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Approval e-mails (Core UserNotifier → Gmail, faked) and the Google Calendar event of an approved leave. */
final class ApprovalMailCalendarTest extends TestCase
{
    use GoogleFixtures, PeopleFixtures, RefreshDatabase;

    private const string SEND = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const string EVENTS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events*';

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $this->org = $this->org();
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        LedgerEntry::query()->create([
            'employee_id' => $this->org['worker']->id, 'leave_type_id' => $this->vacation->id, 'delta' => 30, 'reason' => 'adjustment',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_request_mails_the_manager_approval_adds_the_event_and_cancel_deletes_it(): void
    {
        $admin = $this->userOf($this->org['head']);
        $this->connectGoogle(GoogleService::Gmail, $admin->id);
        $this->connectGoogle(GoogleService::Calendar, $admin->id);
        Http::fake([
            self::SEND => Http::response(['id' => 'm1', 'threadId' => 't1']),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/evt-leave-1*' => Http::response(null, 204),
            self::EVENTS => Http::response(['id' => 'evt-leave-1']),
        ]);
        $worker = $this->userOf($this->org['worker']);
        $lead = $this->userOf($this->org['lead']);

        $id = $this->actingAs($worker)->postJson('/api/timeoff/requests', [
            'leave_type_id' => $this->vacation->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16',
        ])->assertCreated()->json('data.id');
        $this->assertSame([$lead->email], $this->mailRecipients());

        $this->actingAs($lead)->postJson("/api/timeoff/requests/$id/approve")->assertOk();
        $this->assertSame([$lead->email, $worker->email], $this->mailRecipients());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_starts_with($r->url(), 'https://www.googleapis.com/calendar/')
            && $r['summary'] === 'Відпустка: Worker Person'
            && $r['start'] === ['date' => '2026-10-12'] && $r['end'] === ['date' => '2026-10-17']);
        $this->assertSame('evt-leave-1', LeaveRequest::query()->findOrFail($id)->calendar_event_id);

        $this->actingAs($worker)->postJson("/api/timeoff/requests/$id/cancel")->assertOk();
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/events/evt-leave-1'));
        $this->assertNull(LeaveRequest::query()->findOrFail($id)->calendar_event_id);
    }

    public function test_opt_out_disabled_module_and_missing_connection_skip_silently(): void
    {
        $lead = $this->userOf($this->org['lead']);
        $this->actingAs($lead)->patchJson('/api/auth/me/notifications', ['approval_emails' => false])
            ->assertOk()->assertJsonPath('approval_emails', false);
        $this->actingAs($lead)->patchJson('/api/auth/me/notifications', ['approval_emails' => 'x'])->assertUnprocessable();

        // Not connected: no Google call at all, the request still goes through.
        Http::fake();
        $this->actingAs($this->userOf($this->org['worker']))->postJson('/api/timeoff/requests', [
            'leave_type_id' => $this->vacation->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13',
        ])->assertCreated();
        Http::assertNothingSent();

        $this->connectGoogle(GoogleService::Gmail, $lead->id);
        $notifier = $this->app->make(UserNotifier::class);
        // Opted out.
        $notifier->notify($lead->id, 'time', 'S', 'B', '/time');
        // Module switched off.
        $access = $this->app->make(ModuleAccess::class);
        $module = $this->app->make(ModuleRegistry::class)->find('time');
        $this->assertNotNull($module);
        $access->save($module, false, []);
        $notifier->notify($this->userOf($this->org['peer'])->id, 'time', 'S', 'B', '/time');
        Http::assertNothingSent();
    }

    /** @return list<string> */
    private function mailRecipients(): array
    {
        return Http::recorded(fn (Request $r): bool => $r->url() === self::SEND)
            ->map(static function (array $pair): string {
                $raw = base64_decode(strtr((string) $pair[0]['raw'], '-_', '+/'), true);
                preg_match('/^To: (?:.*<)?([^>\r\n]+)>?\r?$/m', (string) $raw, $m);

                return $m[1] ?? '';
            })->values()->all();
    }
}
