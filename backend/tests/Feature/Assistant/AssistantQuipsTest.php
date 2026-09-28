<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Ai\QuipsPrompt;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\AiFixtures;
use Tests\TestCase;

/** GET /api/assistant/quips — one shared AI batch per situation + language, cached; "none" when AI is off. */
final class AssistantQuipsTest extends TestCase
{
    use AiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow('2026-10-08 12:00:00');
    }

    public function test_a_batch_is_generated_once_and_shared_from_the_cache(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(['jokes' => ['Я не впав — я провів аудит підлоги.', '  «Гравітація: 1, Стік: 0.»  ', str_repeat('x', 200), '']])]]);
        $a = User::factory()->withRole(UserRole::Employee)->create();
        $b = User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($a)->getJson('/api/assistant/quips?situation=thrown&locale=uk')->assertOk()
            ->assertJsonPath('data.source', 'ai')
            ->assertJsonPath('data.jokes', ['Я не впав — я провів аудит підлоги.', 'Гравітація: 1, Стік: 0.']);
        $this->actingAs($b)->getJson('/api/assistant/quips?situation=thrown&locale=uk')->assertOk()
            ->assertJsonPath('data.jokes.0', 'Я не впав — я провів аудит підлоги.');

        $this->assertCount(1, $this->brokerSubmits, 'Second user served from the cache.');
        $body = $this->brokerSubmits[0];
        $this->assertSame(QuipsPrompt::system(), $body['messages'][0]['content']);
        $this->assertStringContainsString('Ukrainian', $body['messages'][1]['content']);
        $this->assertStringContainsString('threw him', $body['messages'][1]['content']);
        $this->assertSame('sinhrm.assistant_quips', $body['workflow']);
    }

    public function test_ai_off_or_bad_parameters(): void
    {
        $user = User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($user)->getJson('/api/assistant/quips?situation=fall&locale=en')->assertOk()
            ->assertJsonPath('data.source', 'none')->assertJsonPath('data.jokes', []);
        Http::assertNothingSent();

        $this->actingAs($user)->getJson('/api/assistant/quips?situation=explode&locale=de')->assertUnprocessable()
            ->assertJsonValidationErrors(['situation', 'locale']);
    }

    public function test_a_failed_generation_is_not_retried_immediately(): void
    {
        $this->enableAi();
        $this->fakeBroker([[self::doneAnswer(['jokes' => []])], [self::doneAnswer(['jokes' => []])]]);
        $user = User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($user)->getJson('/api/assistant/quips?situation=slip&locale=ru')->assertOk()->assertJsonPath('data.source', 'none');
        $this->actingAs($user)->getJson('/api/assistant/quips?situation=slip&locale=ru')->assertOk()->assertJsonPath('data.source', 'none');

        $this->assertCount(2, $this->brokerSubmits, 'One request + its one retry, then no new request within RETRY_AFTER.');
    }
}
