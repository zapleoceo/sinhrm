<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Support;

/** Sender rule patterns: "name@domain.tld" (exact) or "@domain.tld" (the domain and its subdomains). */
final class SenderPattern
{
    public const string REGEX = '/^(?:[a-z0-9._%+\-]+)?@[a-z0-9\-]+(?:\.[a-z0-9\-]+)+$/';

    public static function normalize(string $pattern): string
    {
        return mb_strtolower(trim($pattern));
    }

    public static function isValid(string $pattern): bool
    {
        return preg_match(self::REGEX, self::normalize($pattern)) === 1;
    }

    /**
     * 2 = exact address, 1 = domain match, 0 = no match. Exact rules win over domain rules; a longer domain wins.
     */
    public static function specificity(string $pattern, string $email): int
    {
        $pattern = self::normalize($pattern);
        $email = mb_strtolower($email);
        if (! str_starts_with($pattern, '@')) {
            return $pattern === $email ? 2 : 0;
        }
        $domain = substr($pattern, 1);
        $emailDomain = substr($email, (int) strrpos($email, '@') + 1);

        return $emailDomain === $domain || str_ends_with($emailDomain, '.'.$domain) ? 1 : 0;
    }

    public static function domainOf(string $email): string
    {
        return mb_strtolower(substr($email, (int) strrpos($email, '@') + 1));
    }
}
