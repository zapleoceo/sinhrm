<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Enums\DocumentVariable;

/**
 * {Variable} substitution in document templates. Pure: values come from the caller (DocumentVariables).
 * A variable without a value becomes "—" and is reported in $missing, so HR sees what to fill in by hand.
 */
final class TemplateFiller
{
    public const string EMPTY = '—';

    private const string TOKEN = '/\{([^{}\n]{1,40})\}/u';

    /**
     * {tokens} that are not known variables (a typo would otherwise stay in every generated document).
     *
     * @return list<string>
     */
    public static function unknown(string $body): array
    {
        preg_match_all(self::TOKEN, $body, $m);
        $known = DocumentVariable::values();

        return array_values(array_unique(array_filter($m[1], static fn (string $t): bool => ! in_array($t, $known, true))));
    }

    /**
     * @param  array<string, string|null>  $values  variable value (DocumentVariable::value) → text
     * @return array{text: string, missing: list<string>}
     */
    public static function fill(string $body, array $values): array
    {
        $missing = [];
        $text = (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($values, &$missing): string {
            $name = $m[1];
            if (DocumentVariable::tryFrom($name) === null) {
                return $m[0];
            }
            $value = $values[$name] ?? null;
            if ($value === null || trim($value) === '') {
                $missing[] = $name;

                return self::EMPTY;
            }

            return $value;
        }, $body);

        return ['text' => $text, 'missing' => array_values(array_unique($missing))];
    }
}
