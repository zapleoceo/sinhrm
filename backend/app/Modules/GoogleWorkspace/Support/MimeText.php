<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Support;

/**
 * Plain text of a Gmail "format=full" payload: walks the MIME tree, prefers text/plain, falls back to text/html
 * converted to text. Bodies are base64url; the charset from Content-Type is converted to UTF-8.
 * HTML is never rendered or stored: scripts/styles are dropped, tags stripped, entities decoded.
 */
final class MimeText
{
    public const int MAX_LENGTH = 20000;

    /** @param  array<string, mixed>  $payload */
    public static function extract(array $payload): string
    {
        $plain = self::find($payload, 'text/plain');
        if ($plain !== null && trim($plain) !== '') {
            return self::limit(self::normalize($plain));
        }
        $html = self::find($payload, 'text/html');

        return $html === null ? '' : self::limit(self::htmlToText($html));
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);
        $html = (string) preg_replace('#<\s*br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</\s*(p|div|tr|li|h[1-6]|table)\s*>#i', "\n", $html);
        $html = (string) preg_replace('#<\s*(td|th)\b[^>]*>#i', ' ', $html);
        // Keep link targets: a CV link often lives only in href.
        $html = (string) preg_replace('#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 $1', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalize($text);
    }

    public static function decodeBase64Url(string $data): string
    {
        // Non-strict mode skips invalid characters and never fails.
        return base64_decode(strtr($data, '-_', '+/'), false);
    }

    /**
     * Value of a header (case-insensitive) from payload.headers.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function header(array $payload, string $name): ?string
    {
        foreach (is_array($payload['headers'] ?? null) ? $payload['headers'] : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && strcasecmp($header['name'], $name) === 0) {
                return is_string($header['value'] ?? null) ? $header['value'] : null;
            }
        }

        return null;
    }

    /**
     * "Name <a@b.c>" / "a@b.c" → [email lowercased, name].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function parseAddress(?string $from): array
    {
        if ($from === null) {
            return [null, null];
        }
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/u', $from, $m) === 1) {
            $email = filter_var(trim($m[2]), FILTER_VALIDATE_EMAIL);

            return [$email === false ? null : mb_strtolower($email), trim($m[1]) === '' ? null : trim($m[1])];
        }
        $email = filter_var(trim($from), FILTER_VALIDATE_EMAIL);

        return [$email === false ? null : mb_strtolower($email), null];
    }

    /** @param  array<string, mixed>  $part */
    private static function find(array $part, string $mime): ?string
    {
        $type = is_string($part['mimeType'] ?? null) ? strtolower($part['mimeType']) : '';
        $data = is_array($part['body'] ?? null) && is_string($part['body']['data'] ?? null) ? $part['body']['data'] : null;
        if ($type === $mime && $data !== null) {
            return self::toUtf8(self::decodeBase64Url($data), self::charset($part));
        }
        foreach (is_array($part['parts'] ?? null) ? $part['parts'] : [] as $child) {
            if (is_array($child)) {
                $found = self::find($child, $mime);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $part */
    private static function charset(array $part): ?string
    {
        $contentType = self::header($part, 'Content-Type') ?? '';

        return preg_match('/charset="?([A-Za-z0-9_\-]+)"?/i', $contentType, $m) === 1 ? $m[1] : null;
    }

    private static function toUtf8(string $text, ?string $charset): string
    {
        if ($charset !== null && strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    private static function limit(string $text): string
    {
        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
