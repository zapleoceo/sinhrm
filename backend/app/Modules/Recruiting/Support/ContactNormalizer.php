<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

use App\Modules\Recruiting\DTO\ContactKeys;

/**
 * Normalizes contacts into dedupe keys: phone → E.164 (+380… for Ukrainian local formats), e-mail → lowercase,
 * Telegram → username without "@"/t.me, lowercase. Invalid input → null (never throws).
 */
final class ContactNormalizer
{
    private const string DEFAULT_COUNTRY_CODE = '380';

    private const int MIN_DIGITS = 8;

    private const int MAX_DIGITS = 15;

    public function phone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        $plus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $plus) {
            $digits = match (true) {
                // 0XX XXX XX XX — Ukrainian national format
                strlen($digits) === 10 && str_starts_with($digits, '0') => self::DEFAULT_COUNTRY_CODE.substr($digits, 1),
                // 80XX XXX XX XX — old long-distance prefix
                strlen($digits) === 11 && str_starts_with($digits, '80') => '3'.$digits,
                // XX XXX XX XX — 9 digits without the leading 0
                strlen($digits) === 9 => self::DEFAULT_COUNTRY_CODE.$digits,
                default => $digits,
            };
        }

        $length = strlen($digits);
        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS || str_starts_with($digits, '0')) {
            return null;
        }

        return '+'.$digits;
    }

    public function email(?string $raw): ?string
    {
        $email = mb_strtolower(trim((string) $raw));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public function telegram(?string $raw): ?string
    {
        $value = mb_strtolower(trim((string) $raw));
        $value = (string) preg_replace('#^(https?://)?(t\.me|telegram\.me)/#', '', $value);
        $value = ltrim($value, '@');

        return preg_match('/^[a-z0-9_]{4,64}$/', $value) === 1 ? $value : null;
    }

    public function keys(?string $phone, ?string $email, ?string $telegram): ContactKeys
    {
        return new ContactKeys($this->phone($phone), $this->email($email), $this->telegram($telegram));
    }

    /**
     * A single raw contact of unknown type (as integrations deliver the sender): e-mail, @username/t.me link
     * or a phone number.
     */
    public function guess(?string $raw): ContactKeys
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return new ContactKeys(null, null, null);
        }
        if (str_contains($raw, '@') && ! str_starts_with($raw, '@')) {
            return new ContactKeys(null, $this->email($raw), null);
        }
        if (str_starts_with($raw, '@') || preg_match('#t(elegram)?\.me/#i', $raw) === 1 || preg_match('/[a-z_]/i', $raw) === 1) {
            return new ContactKeys(null, null, $this->telegram($raw));
        }

        return new ContactKeys($this->phone($raw), null, null);
    }
}
