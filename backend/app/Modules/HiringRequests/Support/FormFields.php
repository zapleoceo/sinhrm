<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Support;

use App\Modules\HiringRequests\Exceptions\HiringException;

/**
 * The configurable part of the request form (tz2 "форма заявки настраивается в админке": fields, a "required" flag,
 * order = array order). Pure: definition check, value cleaning, required check.
 */
final class FormFields
{
    public const array TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

    public const int MAX_FIELDS = 30;

    /**
     * Keeps only declared keys and checks each value's type. Empty values are dropped.
     *
     * @param  list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string|int|float|bool>
     *
     * @throws HiringException invalid_field
     */
    public static function clean(array $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = match ($field['type']) {
                'number' => is_numeric($value) ? $value + 0 : throw HiringException::invalidField($key),
                'checkbox' => is_bool($value) ? $value : throw HiringException::invalidField($key),
                'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : throw HiringException::invalidField($key),
                'select' => is_string($value) && in_array($value, $field['options'] ?? [], true) ? $value : throw HiringException::invalidField($key),
                default => is_scalar($value) && mb_strlen((string) $value) <= 5000 ? (string) $value : throw HiringException::invalidField($key),
            };
        }

        return $out;
    }

    /**
     * Keys of required fields without a value (a checkbox counts as filled only when true).
     *
     * @param  list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>  $fields
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missing(array $fields, array $values): array
    {
        $missing = [];
        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;
            if ($field['required'] && ($value === null || $value === '' || $value === false)) {
                $missing[] = $field['key'];
            }
        }

        return $missing;
    }
}
