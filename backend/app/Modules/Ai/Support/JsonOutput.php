<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Exceptions\InvalidAiOutput;

/**
 * Robust extraction of the JSON object a model was asked for, plus small typed readers for handlers.
 * Accepts the object wrapped in ```json fences or surrounded by stray text (reasoning models sometimes add it);
 * anything that is not one JSON object is invalid.
 */
final class JsonOutput
{
    /** @return array<string, mixed>|null */
    public static function decode(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true, 32);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param  array<string, mixed>  $json */
    public static function bool(array $json, string $key): bool
    {
        $value = $json[$key] ?? null;
        if (! is_bool($value)) {
            throw InvalidAiOutput::because("{$key}_not_bool");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $json */
    public static function int(array $json, string $key, int $min, int $max): int
    {
        $value = $json[$key] ?? null;
        if (! is_int($value) && ! (is_float($value) && floor($value) === $value) && ! (is_string($value) && ctype_digit($value))) {
            throw InvalidAiOutput::because("{$key}_not_int");
        }

        return max($min, min($max, (int) $value));
    }

    /** @param  array<string, mixed>  $json */
    public static function number(array $json, string $key, float $min, float $max): float
    {
        $value = $json[$key] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            throw InvalidAiOutput::because("{$key}_not_number");
        }

        return max($min, min($max, (float) $value));
    }

    /**
     * Trimmed string limited to $max characters; null/empty → null.
     *
     * @param  array<string, mixed>  $json
     */
    public static function text(array $json, string $key, int $max): ?string
    {
        $value = $json[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw InvalidAiOutput::because("{$key}_not_string");
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * List of non-empty strings (each limited to $maxLength), at most $maxItems.
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    public static function strings(array $json, string $key, int $maxItems, int $maxLength): array
    {
        $value = $json[$key] ?? [];
        if (! is_array($value)) {
            throw InvalidAiOutput::because("{$key}_not_list");
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw InvalidAiOutput::because("{$key}_item_not_string");
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = mb_substr($item, 0, $maxLength);
            }
        }

        return array_slice($out, 0, $maxItems);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    public static function objects(array $json, string $key): array
    {
        $value = $json[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidAiOutput::because("{$key}_not_list");
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item) && $item !== []) {
                throw InvalidAiOutput::because("{$key}_item_not_object");
            }
            /** @var array<string, mixed> $item */
            $out[] = $item;
        }

        return $out;
    }

    /**
     * One of the allowed values.
     *
     * @param  array<string, mixed>  $json
     * @param  list<string>  $allowed
     */
    public static function oneOf(array $json, string $key, array $allowed): string
    {
        $value = $json[$key] ?? null;
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw InvalidAiOutput::because("{$key}_not_allowed");
        }

        return $value;
    }
}
