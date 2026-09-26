<?php

declare(strict_types=1);

namespace Tests\Feature\MailAgent;

use App\Models\User;
use App\Modules\Ai\Services\AiPollJob;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\MailAgent\Models\MailMessage;
use App\Modules\MailAgent\Models\SenderRule;
use App\Modules\MailAgent\Models\UnknownSender;
use App\Modules\MailAgent\Services\MailReprocessService;
use App\Modules\MailAgent\Services\MailSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\Support\GoogleFixtures;
use Tests\Support\MailFixtures;
use Tests\TestCase;

/**
 * AI classification of unknown senders (owner decision): ≥ 0.85 → a rule with source "ai" + re-processing of the
 * sender's queued mail; below → a suggestion in the queue. Invented mail only.
 */
final class MailAiTest extends TestCase
{
    use AiFixtures;
    use GoogleFixtures;
    use MailFixtures;
    use RefreshDatabase;

    private const string BODY_MARKER = 'PRIVATE-BODY-MARKER-4242';

    /** @var list<string> */
    protected array $logged = [];

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->configureGoogleClient();
        $this->captureLogs();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);
        $this->addMail('m-1', 'Partner <partner@unknown.example.test>', 'Пропозиція співпраці',
            "Текст листа {$this->marker()}\n\n> old quoted text QUOTED-PART\n", 150);
        $this->addMail('m-2', 'Partner <partner@unknown.example.test>', 'Ще один лист', 'Друге повідомлення.', 140);
    }

    public function test_low_confidence_answer_stays_a_suggestion_in_the_queue(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer('candidate', 0.75, 'Олена Вигадана'))]]);
        $this->fakeGmail();

        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk()->assertJsonPath('data.unknown', 2);
        $this->assertCount(1, $this->brokerSubmits, 'Asked once per sender.');
        $sent = $this->brokerSubmits[0]['messages'][1]['content'];
        $this->assertStringContainsString('"from":"partner@unknown.example.test"', $sent);
        $this->assertStringContainsString($this->marker(), $sent);
        $this->assertStringNotContainsString('QUOTED-PART', $sent, 'Quoted replies are stripped.');
        $this->assertSame('pending', UnknownSender::query()->value('ai_status'));

        $this->app->make(AiPollJob::class)->run(Carbon::now());

        $this->actingAs($this->superadmin)->getJson('/api/mail/unknown-senders')->assertOk()
            ->assertJsonPath('data.0.ai.status', 'done')
            ->assertJsonPath('data.0.ai.kind', 'candidate')
            ->assertJsonPath('data.0.ai.confidence', 0.75)
            ->assertJsonPath('data.0.ai.extracted.full_name', 'Олена Вигадана');
        $this->assertSame(0, SenderRule::query()->count());
        $this->assertLogsDoNotContain($this->marker(), self::PROJECT_KEY);
    }

    public function test_confident_answer_creates_an_ai_rule_and_reprocesses_the_queued_mail_once(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer('candidate', 0.92, 'Олена Вигадана', 'partner@unknown.example.test'))]]);
        $this->fakeGmail();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();
        $this->assertSame(['unknown', 'unknown'], MailMessage::query()->orderBy('gmail_id')->toBase()->pluck('outcome')->all());

        $this->app->make(AiPollJob::class)->run(Carbon::now());

        $rule = SenderRule::query()->sole();
        $this->assertSame(['partner@unknown.example.test', 'candidate', 'ai', null, 'mail_classify.v3'], [
            $rule->pattern, $rule->kind->value, $rule->source, $rule->created_by, $rule->prompt_version,
        ]);
        $this->assertSame('candidate', $rule->kind->value);
        $this->assertEqualsWithDelta(0.92, $rule->ai_confidence, 1e-9);
        $this->assertSame(0, UnknownSender::query()->count());
        $this->assertSame(['inbox', 'inbox'], MailMessage::query()->orderBy('gmail_id')->toBase()->pluck('outcome')->all());

        // Idempotent: nothing is left in "unknown", a second run changes nothing.
        $this->assertSame(['reprocessed' => 0, 'errors' => 0], $this->app->make(MailReprocessService::class)->reprocessSender('partner@unknown.example.test'));

        $this->actingAs($this->superadmin)->getJson('/api/mail/rules?source=ai')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'ai')->assertJsonPath('data.0.ai_confidence', 0.92);
        $this->actingAs($this->superadmin)->getJson('/api/mail/rules?source=manual')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->superadmin)->getJson('/api/mail/rules?source=robot')->assertUnprocessable();

        // Undo = delete the rule; processed messages stay as they are (documented).
        $this->actingAs($this->superadmin)->deleteJson("/api/mail/rules/{$rule->id}")->assertNoContent();
        $this->assertSame(['inbox', 'inbox'], MailMessage::query()->orderBy('gmail_id')->toBase()->pluck('outcome')->all());
    }

    public function test_confident_answer_without_a_concrete_signal_stays_in_the_queue(): void
    {
        $this->enableAi();
        // Round 1 of the experiment: a vague letter got 0.95+ — without a domain hint or contacts it is not applied.
        $this->fakeBroker([[self::doneAnswer($this->answer('colleague', 0.97))]]);
        $this->fakeGmail();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();

        $this->app->make(AiPollJob::class)->run(Carbon::now());

        $this->assertSame(0, SenderRule::query()->count());
        $this->assertSame(['done', 'colleague'], [UnknownSender::query()->value('ai_status'), UnknownSender::query()->sole()->ai_kind?->value]);
    }

    public function test_letter_without_subject_and_body_is_not_sent_to_ai(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer('ignore', 0.99))]]);
        $this->mailbox = [];
        $this->addMail('m-empty', 'X <empty@void.example.test>', '', '', 100);
        $this->fakeGmail();

        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();

        $this->assertSame([], $this->brokerSubmits);
        $this->assertSame('skipped', UnknownSender::query()->value('ai_status'));
    }

    public function test_threshold_is_inclusive_at_085_and_an_existing_manual_rule_wins(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer($this->answer('newsletter', 0.85))]]);
        $this->fakeGmail();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();
        // A person decided before the answer arrived.
        SenderRule::query()->create(['pattern' => 'partner@unknown.example.test', 'kind' => 'candidate', 'created_by' => $this->superadmin->id]);

        $this->app->make(AiPollJob::class)->run(Carbon::now());

        $this->assertSame(['manual'], SenderRule::query()->pluck('source')->all());
        $this->assertSame('done', UnknownSender::query()->value('ai_status'));
    }

    public function test_mail_sync_job_classifies_queued_senders_the_ai_never_saw(): void
    {
        // First sync with AI off: queued without a suggestion.
        $this->fakeBroker([[self::doneAnswer($this->answer('newsletter', 0.5))]]);
        $this->fakeGmail();
        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();
        $this->assertNull(UnknownSender::query()->value('ai_status'));
        $this->assertSame([], $this->brokerSubmits);

        $this->enableAi();
        $counts = $this->app->make(MailSyncJob::class)->run(Carbon::now());

        $this->assertSame(1, $counts['ai_backlog']);
        $this->assertSame('pending', UnknownSender::query()->value('ai_status'));
    }

    public function test_classification_switched_off_sends_nothing(): void
    {
        $this->enableAi(['ai_mail_classification' => 'off']);
        $this->fakeBroker([[self::doneAnswer($this->answer('newsletter', 0.99))]]);
        $this->fakeGmail();

        $this->actingAs($this->superadmin)->postJson('/api/mail/sync')->assertOk();
        $this->app->make(MailSyncJob::class)->run(Carbon::now());

        $this->assertSame([], $this->brokerSubmits);
        $this->assertNull(UnknownSender::query()->value('ai_status'));
    }

    private function marker(): string
    {
        return self::BODY_MARKER;
    }

    /** @return array<string, mixed> */
    private function answer(string $kind, float $confidence, ?string $name = null, ?string $email = null): array
    {
        return [
            'kind' => $kind,
            'parser' => null,
            'conf' => $confidence,
            'cand' => ['name' => $name, 'phone' => null, 'email' => $email, 'vacancy' => null],
        ];
    }
}
