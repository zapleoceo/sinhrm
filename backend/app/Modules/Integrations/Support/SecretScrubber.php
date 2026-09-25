<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use SensitiveParameter;

/**
 * Redacts secrets from text before it is logged: Telegram-style bot tokens, Bearer values and every secret
 * value decrypted during this request (the vault registers them). Used by the exception reporter in
 * bootstrap/app.php, so a library exception that embeds a token in its message never reaches the log.
 */
final class SecretScrubber
{
    public const string REDACTED = '[redacted]';

    private const int MIN_KNOWN_LENGTH = 4;

    /** @var array<string, true> */
    private array $known = [];

    public function remember(#[SensitiveParameter] string $secret): void
    {
        if (mb_strlen($secret) >= self::MIN_KNOWN_LENGTH) {
            $this->known[$secret] = true;
        }
    }

    public function scrub(string $text): string
    {
        // Longest first, so a secret that contains another one is fully replaced.
        $known = array_map('strval', array_keys($this->known));
        usort($known, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $text = str_replace($known, self::REDACTED, $text);

        return (string) preg_replace(
            ['/bot\d+:[A-Za-z0-9_-]+/', '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i'],
            ['bot'.self::REDACTED, 'Bearer '.self::REDACTED],
            $text,
        );
    }
}
