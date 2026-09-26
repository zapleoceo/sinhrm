<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

/**
 * Pure UTM → channel matching (tz3 "link markup"). A rule matches when every non-null field of the rule equals the
 * candidate's value (trimmed, case-insensitive). Precedence among matching rules:
 *   1. more specific first (number of non-null fields: campaign+medium+source beats source only);
 *   2. then lower priority;
 *   3. then lower id (older rule).
 * A rule with no fields at all never matches (it would catch everything).
 */
final class UtmMatcher
{
    public const array KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

    /**
     * @param  list<array{id: int, channel_id: int, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, priority: int}>  $rules
     * @param  array<string, mixed>|null  $utm  keys with or without the "utm_" prefix
     * @return array{id: int, channel_id: int, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, priority: int}|null
     */
    public static function match(array $rules, ?array $utm): ?array
    {
        $values = self::normalize($utm);
        if ($values === []) {
            return null;
        }
        $best = null;
        $bestKey = null;
        foreach ($rules as $rule) {
            $specificity = 0;
            foreach (self::KEYS as $key) {
                $expected = $rule[$key];
                if ($expected === null || $expected === '') {
                    continue;
                }
                if (($values[$key] ?? null) !== mb_strtolower(trim($expected))) {
                    continue 2;
                }
                $specificity++;
            }
            if ($specificity === 0) {
                continue;
            }
            $key = [-$specificity, $rule['priority'], $rule['id']];
            if ($bestKey === null || $key < $bestKey) {
                $best = $rule;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * "source" / "utm_source" → "utm_source"; values trimmed and lowercased, empty dropped.
     *
     * @param  array<string, mixed>|null  $utm
     * @return array<string, string>
     */
    public static function normalize(?array $utm): array
    {
        $out = [];
        foreach ($utm ?? [] as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $name = str_starts_with($key, 'utm_') ? $key : 'utm_'.$key;
            $v = mb_strtolower(trim((string) $value));
            if (in_array($name, self::KEYS, true) && $v !== '') {
                $out[$name] = $v;
            }
        }

        return $out;
    }
}
