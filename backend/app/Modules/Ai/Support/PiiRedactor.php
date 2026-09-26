<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * Data minimisation before text leaves for an AI provider: e-mail addresses, phone numbers, URLs, Telegram handles
 * and the given names are replaced by placeholders. The model needs the meaning (experience, skills, what was said),
 * not the contacts. Not a guarantee — free text can still identify a person; docs/modules/ai.md lists what is sent.
 */
final class PiiRedactor
{
    /** @param  list<string|null>  $names  e.g. the candidate's full name and its parts (≥ 3 characters) */
    public static function redact(string $text, array $names = []): string
    {
        $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[email]', $text);
        $text = (string) preg_replace('~https?://\S+|www\.\S+~iu', '[link]', $text);
        $text = (string) preg_replace('/(?<![\w@])@[A-Za-z0-9_]{4,32}\b/u', '[telegram]', $text);
        // Phone numbers: 9+ digits with optional +, spaces, dashes, dots and brackets between them.
        $text = (string) preg_replace('/\+?\d[\d\s\-().]{7,}\d/u', '[phone]', $text);

        $parts = [];
        foreach ($names as $name) {
            foreach (preg_split('/\s+/u', trim((string) $name)) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $parts[] = $part;
                }
            }
        }
        usort($parts, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_unique($parts) as $part) {
            $text = (string) preg_replace('/(?<!\w)'.preg_quote($part, '/').'(?!\w)/iu', '[name]', $text);
        }

        return $text;
    }
}
