<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleWorkspace;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** "Schedule a meeting" from the candidate card → Calendar v3 events.insert (faked) + meeting touchpoint. */
final class MeetingTest extends TestCase
{
    use GoogleFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;

    private const string EVENTS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events*';

    private User $recruiter;

    private Candidate $candidate;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $this->branch = Branch::factory()->create();
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $this->candidate = $this->applied($this->vacancyIn($this->branch, $this->recruiter), ['email' => 'olena.sample@example.test'])->candidate;
    }

    public function test_calendar_not_connected_disables_scheduling(): void
    {
        Http::fake();

        $this->actingAs($this->recruiter)->getJson('/api/google/calendar')->assertOk()->assertJsonPath('data.connected', false);
        $this->actingAs($this->recruiter)->postJson($this->url(), $this->body())
            ->assertStatus(422)->assertJsonPath('code', 'google_calendar_not_connected');
        Http::assertNothingSent();
    }

    public function test_online_meeting_creates_event_with_meet_and_a_touchpoint(): void
    {
        $this->connect();
        Http::fake([self::EVENTS => Http::response([
            'id' => 'fake-event-1',
            'htmlLink' => 'https://calendar.google.com/event?eid=fake',
            'hangoutLink' => 'https://meet.google.com/fak-emee-tng',
        ])]);

        $response = $this->actingAs($this->recruiter)->postJson($this->url(), $this->body(['invite_candidate' => true]))
            ->assertCreated()
            ->assertJsonPath('data.event_id', 'fake-event-1')
            ->assertJsonPath('data.meet_link', 'https://meet.google.com/fak-emee-tng')
            ->assertJsonPath('data.touchpoint.channel', 'meeting')
            ->assertJsonPath('data.touchpoint.meta.meet_link', 'https://meet.google.com/fak-emee-tng')
            ->assertJsonPath('data.touchpoint.meta.meeting_type', 'online');

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            $attendees = array_column($r['attendees'], 'email');

            return $r->method() === 'POST'
                && $query['conferenceDataVersion'] === '1'
                && $query['sendUpdates'] === 'none'
                && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
                && is_string($r['conferenceData']['createRequest']['requestId'])
                && $r['summary'] === 'Interview: synthetic'
                && $r['start']['dateTime'] === '2026-10-05T10:00:00+00:00'
                && $r['end']['dateTime'] === '2026-10-05T10:45:00+00:00'
                && in_array(mb_strtolower($this->recruiter->email), $attendees, true)
                && in_array('olena.sample@example.test', $attendees, true)
                && $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN);
        });
        $touch = Touchpoint::query()->findOrFail($response->json('data.touchpoint.id'));
        $this->assertSame($this->candidate->id, $touch->candidate_id);
        $this->assertSame('fake-event-1', $touch->meta['event_id']);
        $this->assertSame($this->recruiter->id, $touch->author_id);
        $this->actingAs($this->recruiter)->getJson('/api/candidates/'.$this->candidate->id.'/timeline')
            ->assertOk()->assertSee('fake-event-1');
    }

    public function test_branch_meeting_has_no_conference_and_candidate_is_not_invited_by_default(): void
    {
        $this->connect();
        Http::fake([self::EVENTS => Http::response(['id' => 'fake-event-2', 'htmlLink' => 'https://calendar.google.com/event?eid=fake2'])]);

        $this->actingAs($this->recruiter)->postJson($this->url(), $this->body(['type' => 'branch', 'location' => 'Synthetic office']))
            ->assertCreated()->assertJsonPath('data.meet_link', null);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return $query['conferenceDataVersion'] === '0' && ! isset($r['conferenceData'])
                && $r['location'] === 'Synthetic office'
                && array_column($r['attendees'], 'email') === [mb_strtolower($this->recruiter->email)];
        });
    }

    public function test_access_follows_the_candidate_scope(): void
    {
        $this->connect();
        Http::fake();
        $other = $this->userWith(UserRole::Recruiter, [Branch::factory()->create()]);
        $viewer = $this->userWith(UserRole::Viewer, [$this->branch]);

        $this->postJson($this->url(), $this->body())->assertUnauthorized();
        $this->actingAs($other)->postJson($this->url(), $this->body())->assertForbidden();
        $this->actingAs($viewer)->postJson($this->url(), $this->body())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_validation(): void
    {
        $this->connect();
        Http::fake();

        $this->actingAs($this->recruiter)->postJson($this->url(), $this->body(['type' => 'phone', 'duration_minutes' => 5, 'start' => 'soon']))
            ->assertStatus(422)->assertJsonValidationErrors(['type', 'duration_minutes', 'start']);
    }

    public function test_google_error_is_a_code_and_no_touchpoint_is_stored(): void
    {
        $this->connect();
        Http::fake([self::EVENTS => Http::response(['error' => ['message' => 'Forbidden']], 403)]);
        $before = Touchpoint::query()->count();

        $this->actingAs($this->recruiter)->postJson($this->url(), $this->body())
            ->assertStatus(502)->assertJsonPath('code', 'google_forbidden');
        $this->assertSame($before, Touchpoint::query()->count());
    }

    private function connect(): void
    {
        $owner = User::factory()->withRole(UserRole::Superadmin)->create();
        $this->connectGoogle(GoogleService::Calendar, $owner->id);
    }

    private function url(): string
    {
        return '/api/google/candidates/'.$this->candidate->id.'/meetings';
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function body(array $override = []): array
    {
        return $override + [
            'title' => 'Interview: synthetic',
            'start' => '2026-10-05T10:00:00+00:00',
            'duration_minutes' => 45,
            'type' => 'online',
        ];
    }
}
