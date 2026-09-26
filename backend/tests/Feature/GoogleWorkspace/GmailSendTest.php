<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleWorkspace;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Services\GmailMailer;
use App\Modules\GoogleWorkspace\Support\MimeText;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** "Надіслати" on the e-mail channel of the candidate card → Gmail users.messages.send (faked) + outgoing touchpoint. */
final class GmailSendTest extends TestCase
{
    use GoogleFixtures;
    use RecruitingFixtures;
    use RefreshDatabase;

    private const string SEND = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private User $recruiter;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $branch = Branch::factory()->create();
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$branch]);
        $this->candidate = $this->applied($this->vacancyIn($branch, $this->recruiter), [
            'email' => 'olena.sample@example.test', 'full_name' => 'Олена Семпл',
        ])->candidate;
    }

    public function test_not_connected_gmail_disables_sending(): void
    {
        Http::fake();

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'Добрий день'])
            ->assertUnprocessable()->assertJsonPath('code', 'channel_not_connected');
        Http::assertNothingSent();
        $this->assertSame(0, Touchpoint::query()->where('direction', 'out')->count());
    }

    public function test_read_only_connection_asks_to_reconnect(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->recruiter->id, scopes: GoogleService::Gmail->requiredScopes());
        Http::fake();

        $channels = $this->actingAs($this->recruiter)->getJson('/api/channels')->assertOk()->collect('data')->keyBy('channel');
        $this->assertSame(['key' => 'google_gmail', 'channel' => 'email', 'mode' => 'off', 'reason' => 'reconnect_to_send'], $channels['email']);
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'Добрий день'])
            ->assertUnprocessable()->assertJsonPath('code', 'channel_not_connected');
        Http::assertNothingSent();
    }

    public function test_sends_a_new_mail_and_records_an_outgoing_touchpoint(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->recruiter->id);
        $this->captureLogs();
        Http::fake([self::SEND => Http::response(['id' => 'fake-sent-1', 'threadId' => 'fake-thread-1', 'labelIds' => ['SENT']])]);

        $channels = $this->actingAs($this->recruiter)->getJson('/api/channels')->collect('data')->keyBy('channel');
        $this->assertSame('live', $channels['email']['mode']);
        $this->actingAs($this->recruiter)->postJson($this->url(), [
            'channel' => 'email', 'subject' => 'Запрошення на співбесіду', 'text' => "Добрий день!\n<script>alert(1)</script>",
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.via_product', true)
            ->assertJsonPath('data.author.id', $this->recruiter->id);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r): bool {
            $raw = MimeText::decodeBase64Url((string) $r['raw']);

            return $r->url() === self::SEND
                && $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
                && ! isset($r['threadId'])
                && str_contains($raw, '<olena.sample@example.test>')
                && str_contains($raw, 'Subject: =?UTF-8?B?')
                && ! str_contains($raw, 'In-Reply-To')
                && ! str_contains($raw, '<script>')
                && str_contains((string) base64_decode($this->htmlPart($raw)), '&lt;script&gt;alert(1)&lt;/script&gt;');
        });
        $touch = Touchpoint::query()->where('channel', 'email')->sole();
        $this->assertSame('fake-sent-1', $touch->external_id);
        $this->assertSame('google_gmail', $touch->integration_key);
        $this->assertSame('fake-thread-1', $touch->meta['gmail_thread']);
        $this->assertSame($this->candidate->id, $touch->candidate_id);
        $this->assertLogsDoNotContain(self::ACCESS_TOKEN, self::REFRESH_TOKEN);
    }

    public function test_replies_in_the_thread_of_the_candidates_last_mail(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->recruiter->id);
        Touchpoint::query()->create([
            'candidate_id' => $this->candidate->id, 'channel' => 'email', 'direction' => 'in', 'occurred_at' => Carbon::now()->subHour(),
            'body' => 'Резюме', 'external_id' => 'fake-in-1', 'via_product' => false,
            'meta' => [
                'subject' => 'Резюме на вакансію', 'from' => 'olena.sample@example.test',
                'gmail_thread' => 'fake-thread-9', 'message_id' => '<fake-msg-9@mail.example.test>',
            ],
        ]);
        Http::fake([self::SEND => Http::response(['id' => 'fake-sent-2', 'threadId' => 'fake-thread-9'])]);

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'Дякуємо!'])->assertCreated();

        Http::assertSent(function (Request $r): bool {
            $raw = MimeText::decodeBase64Url((string) $r['raw']);

            return $r['threadId'] === 'fake-thread-9'
                && str_contains($raw, "In-Reply-To: <fake-msg-9@mail.example.test>\r\n")
                && str_contains($raw, "References: <fake-msg-9@mail.example.test>\r\n")
                && str_contains($raw, 'Subject: =?UTF-8?B?');
        });
    }

    public function test_provider_failure_and_candidate_without_email(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->recruiter->id);
        Http::fake([self::SEND => Http::response(['error' => ['message' => 'fake-provider-detail']], 500)]);

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'Привіт'])
            ->assertStatus(502)->assertJsonPath('code', 'send_failed')->assertDontSee('fake-provider-detail');
        $this->assertSame(0, Touchpoint::query()->where('direction', 'out')->count());

        $this->candidate->forceFill(['email' => null])->save();
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'Привіт'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_recipient');
    }

    public function test_sending_is_rate_limited_per_mailbox(): void
    {
        $this->connectGoogle(GoogleService::Gmail, $this->recruiter->id);
        Http::fake([self::SEND => Http::response(['id' => 'fake-sent-3'])]);
        $limiter = $this->app->make(RateLimiter::class);
        for ($i = 0; $i < GmailMailer::MAX_PER_HOUR; $i++) {
            $limiter->hit(GmailMailer::LIMITER_KEY, 3600);
        }

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'Привіт'])
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        Http::assertNothingSent();
    }

    private function url(): string
    {
        return '/api/candidates/'.$this->candidate->id.'/messages';
    }

    private function htmlPart(string $raw): string
    {
        $rest = substr($raw, (int) strpos($raw, 'Content-Type: text/html'));
        $start = (int) strpos($rest, "\r\n\r\n") + 4;

        return str_replace("\r\n", '', substr($rest, $start, (int) strpos($rest, "\r\n--", $start) - $start));
    }
}
