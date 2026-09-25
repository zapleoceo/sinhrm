<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Support;

use App\Modules\Scripts\Enums\TemplateVariable;

/** Fills {Variable} tokens of a template text. Unknown value → the token stays in the text for the recruiter to edit. */
final class TemplateRenderer
{
    private const string TOKEN = '/\{([^{}\r\n]{1,40})\}/u';

    /**
     * @param  array<string, string|null>  $values  variable name (TemplateVariable value) → value
     * @return array{text: string, missing: list<string>}
     */
    public static function render(string $text, array $values): array
    {
        $missing = [];
        $out = preg_replace_callback(self::TOKEN, static function (array $m) use ($values, &$missing): string {
            $name = $m[1];
            $value = $values[$name] ?? null;
            if (TemplateVariable::tryFrom($name) === null) {
                return $m[0];
            }
            if ($value === null || trim($value) === '') {
                if (! in_array($name, $missing, true)) {
                    $missing[] = $name;
                }

                return $m[0];
            }

            return trim($value);
        }, $text);

        return ['text' => $out ?? $text, 'missing' => $missing];
    }

    /**
     * Tokens in the text that are not known variables (a typo like {Імя}); used to reject a draft.
     *
     * @return list<string>
     */
    public static function unknownTokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $m);
        $unknown = array_filter($m[1], static fn (string $name): bool => TemplateVariable::tryFrom($name) === null);

        return array_values(array_unique($unknown));
    }
}
