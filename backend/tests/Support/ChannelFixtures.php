<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\Integration;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Channel integration setup for tests. Fake secrets only: the repository is public. */
trait ChannelFixtures
{
    protected const string TG_BOT_TOKEN = '123456:FAKE-bot-token-for-channel-tests';

    protected const string TG_SECRET = 'fake-telegram-webhook-secret-0001';

    protected const string WA_APP_SECRET = 'fake-whatsapp-app-secret-0002';

    protected const string WA_VERIFY = 'fake-verify-token-0003';

    protected const string WA_TOKEN = 'fake-whatsapp-access-token-0004';

    protected const string VIBER_TOKEN = 'fake-viber-token-0005';

    protected const string PHONE_TOKEN = 'fake-telephony-token-0006';

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $settings
     */
    protected function channel(string $key, IntegrationStatus $status, array $secrets = [], array $settings = []): void
    {
        Integration::query()->updateOrCreate(['key' => $key], ['status' => $status, 'settings' => $settings]);
        $vault = $this->app->make(SecretVault::class);
        foreach ($secrets as $name => $value) {
            $vault->put($key, $name, $value);
        }
    }

    protected function telegram(IntegrationStatus $status = IntegrationStatus::Connected): void
    {
        $this->channel('telegram_business', $status, ['bot_token' => self::TG_BOT_TOKEN, 'webhook_secret' => self::TG_SECRET]);
    }

    protected function whatsapp(IntegrationStatus $status = IntegrationStatus::Connected): void
    {
        $this->channel('whatsapp_cloud', $status, [
            'access_token' => self::WA_TOKEN, 'app_secret' => self::WA_APP_SECRET, 'verify_token' => self::WA_VERIFY,
        ], ['phone_number_id' => '1098765432', 'waba_id' => '2098765432']);
    }

    protected function viber(IntegrationStatus $status = IntegrationStatus::Connected): void
    {
        $this->channel('viber', $status, ['token' => self::VIBER_TOKEN]);
    }

    /**
     * @param  array<string, mixed>  $update
     * @return TestResponse<Response>
     */
    protected function telegramWebhook(array $update, string $secret = self::TG_SECRET): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram_business', $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    /**
     * Raw JSON body, so an HMAC over exactly these bytes can be sent.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function rawPost(string $uri, string $body, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function whatsappWebhook(array $payload, ?string $secret = self::WA_APP_SECRET): TestResponse
    {
        $body = (string) json_encode($payload);
        $headers = $secret === null ? [] : ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

        return $this->rawPost('/api/webhooks/whatsapp_cloud', $body, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function viberWebhook(array $payload, string $token = self::VIBER_TOKEN): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->rawPost('/api/webhooks/viber', $body, ['X-Viber-Content-Signature' => hash_hmac('sha256', $body, $token)]);
    }

    /** @return array<string, mixed> */
    protected function tgMessage(int $fromId, int $chatId, int $messageId, string $text, ?string $username = 'cand_user'): array
    {
        $from = ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Test'];
        if ($username !== null && $fromId === $chatId) {
            $from['username'] = $username;
        }
        $chat = ['id' => $chatId, 'type' => 'private', 'first_name' => 'Test'];
        if ($username !== null) {
            $chat['username'] = $username;
        }

        return ['update_id' => $messageId, 'business_message' => [
            'business_connection_id' => 'bc-1',
            'message_id' => $messageId,
            'from' => $from,
            'chat' => $chat,
            'date' => now()->subMinute()->getTimestamp(),
            'text' => $text,
        ]];
    }

    /** @return array<string, mixed> */
    protected function waMessage(string $waId, string $id, string $text, string $phoneNumberId = '1098765432'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'x', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'contacts' => [['profile' => ['name' => 'Test Person'], 'wa_id' => $waId]],
            'messages' => [['from' => $waId, 'id' => $id, 'timestamp' => (string) now()->subMinute()->getTimestamp(),
                'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }
}
