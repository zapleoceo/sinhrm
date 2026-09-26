<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Support;

/**
 * The reporter's access code: 16 random characters of Crockford base32 (80 bits), shown as XXXX-XXXX-XXXX-XXXX
 * exactly once. The database keeps only an HMAC-SHA256 with the application key: a leaked table cannot be turned
 * back into codes, and a lookup needs no scan (the hash is unique and indexed).
 */
final class AccessCode
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 16; $i++) {
            $chars .= self::ALPHABET[random_int(0, 31)];
        }

        return implode('-', str_split($chars, 4));
    }

    /** Upper-case, separators and spaces removed, look-alikes mapped (O→0, I/L→1) as Crockford base32 does. */
    public static function normalize(string $code): string
    {
        $clean = strtoupper((string) preg_replace('/[\s-]+/', '', $code));

        return strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    public static function hash(string $code, string $key): string
    {
        return hash_hmac('sha256', 'safe-speak:'.self::normalize($code), $key);
    }

    /** Anything that is not 16 alphabet characters after normalization is refused without a lookup. */
    public static function wellFormed(string $code): bool
    {
        return preg_match('/^['.self::ALPHABET.']{16}$/', self::normalize($code)) === 1;
    }
}
