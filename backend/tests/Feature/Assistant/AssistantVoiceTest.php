<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\TestCase;

/** POST /api/assistant/transcribe — dictation through the broker's Whisper queue (/v1/transcribe/jobs). */
final class AssistantVoiceTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    private string $answer = 'pending';

    private string $transcript = 'Скільки в мене днів відпустки?';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow('2026-10-08 12:00:00');
    }

    public function test_a_recording_becomes_text_through_the_broker(): void
    {
        $this->enableAi();
        $this->answer = 'done';
        $this->fakeWhisper();
        $user = User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($user)->post('/api/assistant/transcribe', ['audio' => self::recording()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.text', $this->transcript);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === self::BROKER.'/v1/transcribe/jobs?workflow=sinhrm.assistant_voice'
            && $r->isMultipart()
            && $r->header('X-Project-Key') === [self::PROJECT_KEY]);
        $row = AiRequest::query()->sole();
        $this->assertSame('transcription', $row->capability);
        $this->assertSame('assistant_voice_user', $row->subject_type);
    }

    public function test_a_slow_transcript_is_pending_and_collected_by_its_owner_only(): void
    {
        $this->enableAi();
        $this->fakeWhisper();
        $user = User::factory()->withRole(UserRole::Employee)->create();
        $other = User::factory()->withRole(UserRole::Employee)->create();

        $id = $this->actingAs($user)->post('/api/assistant/transcribe', ['audio' => self::recording()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.state', 'pending')->json('data.request_id');

        $this->actingAs($other)->getJson("/api/assistant/transcriptions/{$id}")->assertNotFound();
        $this->answer = 'done';
        $this->actingAs($user)->getJson("/api/assistant/transcriptions/{$id}")
            ->assertOk()->assertJsonPath('data.state', 'done')->assertJsonPath('data.text', $this->transcript);
        $this->actingAs($user)->getJson("/api/assistant/transcriptions/{$id}")->assertJsonPath('data.text', $this->transcript);
    }

    public function test_silence_is_reported_as_empty_transcript(): void
    {
        $this->enableAi();
        $this->answer = 'done';
        $this->transcript = '  ';
        $this->fakeWhisper();

        $this->actingAs(User::factory()->withRole(UserRole::Employee)->create())
            ->post('/api/assistant/transcribe', ['audio' => self::recording()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.error', 'empty_transcript');
    }

    public function test_voice_follows_the_assistant_switch_and_validates_the_file(): void
    {
        $user = User::factory()->withRole(UserRole::Employee)->create();
        $this->actingAs($user)->post('/api/assistant/transcribe', ['audio' => self::recording()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.error', 'ai_disabled');

        $this->enableAi(['ai_assistant_chat' => 'off']);
        $this->actingAs($user)->post('/api/assistant/transcribe', ['audio' => self::recording()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.error', 'ai_purpose_disabled');
        Http::assertNothingSent();

        $this->actingAs($user)->post('/api/assistant/transcribe', ['audio' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
        $this->actingAs($user)->post('/api/assistant/transcribe', ['audio' => UploadedFile::fake()->create('big.webm', 5000, 'audio/webm')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
    }

    private function fakeWhisper(): void
    {
        Http::fake(function (Request $r) {
            if ($r->method() === 'POST') {
                return Http::response(['job_id' => 2001, 'poll_url' => '/v1/jobs/2001', 'poll_after_s' => 2], 202);
            }

            return Http::response($this->answer === 'done'
                ? ['status' => 'done', 'text' => $this->transcript, 'model' => 'synthetic-whisper', 'cost_usd' => 0.001, 'finish_reason' => null]
                : self::pendingAnswer());
        });
    }

    private static function recording(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('voice.webm', "\x1A\x45\xDF\xA3".str_repeat("\0", 64));
    }
}
