<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Definitions\AiBrokerDefinition;
use App\Modules\Integrations\Definitions\TelegramBusinessDefinition;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/** AbstractHttpCheckedDefinition::probe — the outbound rules shared by the HTTP connection checks. */
final class HttpCheckedDefinitionTest extends TestCase
{
    public function test_blocked_url_is_refused_without_a_request(): void
    {
        Http::fake();
        $result = $this->broker(['internal.test' => ['10.0.0.1']])
            ->check(new IntegrationConfig('ai_broker', ['base_url' => 'https://internal.test'], []));

        self::assertSame(IntegrationStatus::Error, $result->status);
        self::assertSame('blocked_host', $result->message);
        Http::assertNothingSent();
    }

    public function test_request_accepts_json_and_maps_the_answer(): void
    {
        Http::fake(['broker.test/*' => Http::sequence()->push(['ok' => true])->push([], 503)]);
        $definition = $this->broker();
        $config = new IntegrationConfig('ai_broker', ['base_url' => 'https://broker.test/'], []);

        self::assertSame(IntegrationStatus::Connected, $definition->check($config)->status);
        self::assertSame('http_503', $definition->check($config)->message);
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://broker.test/v1/health' && $r->hasHeader('Accept', 'application/json'));
    }

    public function test_transport_error_is_connection_failed_without_the_url(): void
    {
        Http::fake(static fn () => throw new ConnectionException('cURL error for https://api.telegram.org/bot1:secret/getMe'));
        $definition = new TelegramBusinessDefinition($this->app->make(Factory::class), new OutboundUrlGuard(new FakeHostResolver));

        $result = $definition->check(new IntegrationConfig('telegram_business', [], ['bot_token' => '1:secret']));

        self::assertSame(IntegrationStatus::Error, $result->status);
        self::assertSame('connection_failed', $result->message);
    }

    /** @param  array<string, list<string>>  $hosts */
    private function broker(array $hosts = []): AiBrokerDefinition
    {
        return new AiBrokerDefinition($this->app->make(Factory::class), new OutboundUrlGuard(new FakeHostResolver($hosts)));
    }
}
