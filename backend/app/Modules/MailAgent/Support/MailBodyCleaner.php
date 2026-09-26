<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Support;

/**
 * The part of an e-mail worth sending to the AI classifier: the new text only. Drops quoted lines ("> …"), everything
 * after a reply header ("On … wrote:", "… пише:", "-----Original Message-----") or a signature delimiter ("-- "),
 * collapses whitespace and keeps the first LIMIT characters.
 */
final class MailBodyCleaner
{
    public const int LIMIT = 1500;

    private const array CUT_MARKERS = [
        '/^--\s*$/u',
        '/^_{5,}\s*$/u',
        '/^-{3,}\s*(original message|forwarded message|пересланное сообщение|переслане повідомлення)/iu',
        '/^(on|am|le)\s.+(wrote|schrieb|a écrit):\s*$/iu',
        '/.+\s(пише|пишет|написав|написал|написала)\s*:\s*$/u',
        '/^(sent from my|надіслано з|отправлено с)\b/iu',
    ];

    public static function clean(string $text): string
    {
        $kept = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $trimmed = trim($line);
            foreach (self::CUT_MARKERS as $marker) {
                if (preg_match($marker, $trimmed) === 1) {
                    break 2;
                }
            }
            if (str_starts_with($trimmed, '>')) {
                continue;
            }
            $kept[] = $trimmed;
        }
        $body = trim((string) preg_replace('/\s+/u', ' ', implode("\n", $kept)));

        return mb_substr($body, 0, self::LIMIT);
    }
}
