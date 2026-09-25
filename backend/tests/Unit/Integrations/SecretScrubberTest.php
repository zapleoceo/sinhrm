<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Support\SecretScrubber;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class SecretScrubberTest extends TestCase
{
    public function test_redacts_bot_tokens_bearer_values_and_known_secrets(): void
    {
        $scrubber = new SecretScrubber;
        $scrubber->remember('fake-known-secret-5555');

        $out = $scrubber->scrub('GET https://api.telegram.org/bot123:FAKE_tok-en/getMe; Authorization: Bearer fake.jwt.value; key=fake-known-secret-5555');

        $this->assertStringNotContainsString('FAKE_tok-en', $out);
        $this->assertStringNotContainsString('fake.jwt.value', $out);
        $this->assertStringNotContainsString('fake-known-secret-5555', $out);
        $this->assertStringContainsString('bot[redacted]', $out);
    }

    public function test_reported_exception_with_token_is_logged_redacted(): void
    {
        $log = Log::spy();
        $scrubber = $this->app->make(SecretScrubber::class);
        $scrubber->remember('fake-vault-value-8080');
        // What the vault registers when it decrypts a malformed (space/newline) token.
        $scrubber->remember("123:FAKE tok\n");

        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(new InvalidArgumentException("Unable to parse URI: https://api.telegram.org/bot123:FAKE tok\n/getMe"));
        $handler->report(new RuntimeException('outer', 0, new RuntimeException('inner has fake-vault-value-8080')));

        $log->shouldHaveReceived('error')->twice()->withArgs(function (string $message, array $context): bool {
            $dump = $message.json_encode($context);

            return ! str_contains($dump, 'FAKE') && ! str_contains($dump, ' tok') && ! str_contains($dump, 'fake-vault-value-8080')
                && $context['redacted'] === true;
        });
    }

    public function test_exceptions_without_secrets_are_reported_normally(): void
    {
        $log = Log::spy();
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('plain failure'));

        $log->shouldNotHaveReceived('error', fn (string $m, array $c = []): bool => ($c['redacted'] ?? false) === true);
    }
}
