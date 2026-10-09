<?php

declare(strict_types=1);

namespace Tests\Unit\Channels;

use App\Modules\Channels\Adapters\BinotelAdapter;
use App\Modules\Channels\Adapters\PhonetAdapter;
use App\Modules\Channels\Adapters\RingostatAdapter;
use App\Modules\Channels\Enums\WebhookAuth;
use App\Modules\Channels\Support\WebhookCredentials;
use App\Modules\Integrations\DTO\IntegrationConfig;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** HRM-26: telephony webhook credentials. Fake tokens only. */
final class WebhookCredentialsTest extends TestCase
{
    private const string TOKEN = 'fake-telephony-token-unit-01';

    private const string BODY = '{"uniqueid":"1","duration":5}';

    /** @return iterable<string, array{array<string, string>, string, bool}> */
    public static function requests(): iterable
    {
        $sig = hash_hmac('sha256', self::BODY, self::TOKEN);
        yield 'X-Webhook-Token' => [['HTTP_X_WEBHOOK_TOKEN' => self::TOKEN], '', true];
        yield 'Authorization Bearer' => [['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN], '', true];
        yield 'X-Signature hex' => [['HTTP_X_SIGNATURE' => $sig], '', true];
        yield 'X-Signature upper-case hex' => [['HTTP_X_SIGNATURE' => strtoupper($sig)], '', true];
        yield 'X-Signature with sha256= prefix (not our format)' => [['HTTP_X_SIGNATURE' => 'sha256='.$sig], '', false];
        yield 'wrong header token' => [['HTTP_X_WEBHOOK_TOKEN' => 'fake-wrong'], '', false];
        yield 'empty header token' => [['HTTP_X_WEBHOOK_TOKEN' => ''], '', false];
        yield 'wrong bearer' => [['HTTP_AUTHORIZATION' => 'Bearer fake-wrong'], '', false];
        yield 'signature with another key' => [['HTTP_X_SIGNATURE' => hash_hmac('sha256', self::BODY, 'fake-other')], '', false];
        yield 'nothing' => [[], '', false];
        yield 'query token, flag off' => [[], '?token='.self::TOKEN, false];
        yield 'query token + valid header, flag off' => [['HTTP_X_WEBHOOK_TOKEN' => self::TOKEN], '?token='.self::TOKEN, false];
    }

    /** @param  array<string, string>  $server */
    #[DataProvider('requests')]
    public function test_telephony_verify(array $server, string $query, bool $ok): void
    {
        foreach ([PhonetAdapter::class, RingostatAdapter::class, BinotelAdapter::class] as $class) {
            $adapter = $this->app->make($class);
            $this->assertSame(WebhookAuth::HeaderToken, $adapter->auth());
            $this->assertSame($ok, $adapter->verify($this->request($server, $query), $this->config()), $class);
        }
    }

    public function test_query_token_only_behind_the_flag(): void
    {
        $adapter = $this->app->make(PhonetAdapter::class);
        $on = $this->config(['webhook_query_token' => 'on']);

        $this->assertTrue($adapter->queryTokenAllowed($on));
        $this->assertFalse($adapter->queryTokenAllowed($this->config()));
        $this->assertTrue($adapter->verify($this->request([], '?token='.self::TOKEN), $on));
        $this->assertTrue($adapter->usesQueryToken($this->request([], '?token='.self::TOKEN)));
        $this->assertFalse($adapter->verify($this->request([], '?token=fake-wrong'), $on));
        $this->assertFalse($adapter->verify($this->request([], '?token='), $on));
        // A URL token is judged by the legacy rule only: a wrong one is not rescued by a valid header.
        $this->assertFalse($adapter->verify($this->request(['HTTP_X_WEBHOOK_TOKEN' => self::TOKEN], '?token=fake-wrong'), $on));
        // Header still works while the flag is on.
        $this->assertTrue($adapter->verify($this->request(['HTTP_X_WEBHOOK_TOKEN' => self::TOKEN]), $on));
        $this->assertFalse($adapter->usesQueryToken($this->request(['HTTP_X_WEBHOOK_TOKEN' => self::TOKEN])));
    }

    public function test_no_configured_token_rejects_everything(): void
    {
        $adapter = $this->app->make(RingostatAdapter::class);
        $none = new IntegrationConfig('ringostat', ['webhook_query_token' => 'on'], []);

        $this->assertFalse($adapter->verify($this->request(['HTTP_X_WEBHOOK_TOKEN' => self::TOKEN]), $none));
        $this->assertFalse($adapter->verify($this->request([], '?token='.self::TOKEN), $none));
        $this->assertFalse(WebhookCredentials::tokenMatches('', ''));
        $this->assertFalse(WebhookCredentials::signatureMatches($this->request(['HTTP_X_SIGNATURE' => hash_hmac('sha256', self::BODY, '')]), ''));
    }

    /** @param  array<string, string>  $server */
    private function request(array $server, string $query = ''): Request
    {
        return Request::create('/api/webhooks/phonet'.$query, 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], self::BODY);
    }

    /** @param  array<string, string>  $settings */
    private function config(array $settings = []): IntegrationConfig
    {
        return new IntegrationConfig('phonet', $settings + ['webhook_query_token' => 'off'], ['webhook_token' => self::TOKEN]);
    }
}
