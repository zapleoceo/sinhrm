<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * Google test helpers. All tokens, e-mails and ids are synthetic. The OAuth client is a fake one set in config;
 * requests are answered by Http::fake.
 */
trait GoogleFixtures
{
    protected const string CLIENT_ID = 'fake-client-id.apps.example.test';

    protected const string CLIENT_SECRET = 'fake-client-secret-5566';

    protected const string REFRESH_TOKEN = 'fake-refresh-token-1122';

    protected const string ACCESS_TOKEN = 'fake-access-token-3344';

    /** @var list<string> every log line (message + context) written during the test */
    protected array $logged = [];

    protected function configureGoogleClient(): void
    {
        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.client_secret' => self::CLIENT_SECRET,
            'services.google.connect_redirect' => 'https://sinhrm.example.test/api/google/connect/callback',
        ]);
    }

    /** Stores a grant as the callback would; the access token is valid for an hour unless $expired. */
    /** @param  list<string>|null  $scopes  granted scopes (default: everything the service asks for) */
    protected function connectGoogle(GoogleService $service, int $userId, bool $expired = false, ?array $scopes = null): void
    {
        $this->app->make(GoogleConnectionStore::class)->connect(
            $service,
            self::REFRESH_TOKEN,
            self::ACCESS_TOKEN,
            $expired ? Carbon::now()->subMinute() : Carbon::now()->addHour(),
            'recruiting-box@example.test',
            $scopes ?? $service->scopes(),
            $userId,
        );
    }

    protected function captureLogs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    protected function assertLogsDoNotContain(string ...$needles): void
    {
        $all = implode("\n", $this->logged);
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }

    /** A fake id_token whose payload carries the e-mail (signature is irrelevant: it is only a label). */
    protected function idToken(string $email): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['email' => $email, 'email_verified' => true]).'.sig';
    }

    protected static function base64url(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
