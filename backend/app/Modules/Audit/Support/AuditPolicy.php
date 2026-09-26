<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use LogicException;

/**
 * What the audit log may keep. One place for the whole policy:
 *  - anonymity modules (Safe Speak, Pulse surveys/mood) are never logged — not even "something changed";
 *  - personal and sensitive fields (salary, contacts, secrets, free-text notes) keep only the fact of a change: "***";
 *  - technical noise (timestamps, touch counters) is not logged at all.
 */
final class AuditPolicy
{
    public const string MASK = '***';

    /** Namespaces whose models must never reach the log (anonymous by design). */
    private const array EXCLUDED_NAMESPACES = ['App\Modules\SafeSpeak', 'App\Modules\Pulse'];

    /** Entity types that are refused even when recorded by hand. */
    private const array EXCLUDED_ENTITY_PREFIXES = ['safe_speak', 'pulse', 'survey', 'mood'];

    /** Field names (substring, case-insensitive) whose values are replaced with the mask. */
    private const string MASKED_PATTERN = '/salary|compensation|password|secret|token|value|phone|email|telegram|address|emergency|birth|personal|custom_fields|note|reason|comment|body|utm|avatar|google_id/i';

    /** Fields never written to the log. */
    private const array IGNORED = [
        'id', 'created_at', 'updated_at', 'remember_token', 'last_login_at', 'last_touch_at', 'stage_entered_at',
        'last_checked_at', 'last_error', 'notified', 'escalated',
    ];

    public function assertTrackable(string $modelClass): void
    {
        foreach (self::EXCLUDED_NAMESPACES as $ns) {
            if (str_starts_with($modelClass, $ns.'\\')) {
                throw new LogicException("Audit: {$modelClass} belongs to an anonymous module and must not be logged.");
            }
        }
    }

    public function assertEntityType(string $entityType): void
    {
        foreach (self::EXCLUDED_ENTITY_PREFIXES as $prefix) {
            if (str_starts_with($entityType, $prefix)) {
                throw new LogicException("Audit: entity type {$entityType} is anonymous and must not be logged.");
            }
        }
    }

    public function isIgnored(string $field): bool
    {
        return in_array($field, self::IGNORED, true);
    }

    public function isMasked(string $field): bool
    {
        return preg_match(self::MASKED_PATTERN, $field) === 1;
    }

    /**
     * Drops ignored fields, masks sensitive ones, normalises values to JSON-safe scalars/arrays.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function sanitize(array $changes): array
    {
        $clean = [];
        foreach ($changes as $field => $pair) {
            if ($this->isIgnored($field)) {
                continue;
            }
            if ($this->isMasked($field)) {
                $clean[$field] = [
                    'from' => $pair['from'] === null ? null : self::MASK,
                    'to' => $pair['to'] === null ? null : self::MASK,
                ];

                continue;
            }
            $clean[$field] = ['from' => $this->scalar($pair['from']), 'to' => $this->scalar($pair['to'])];
        }

        return $clean;
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500).'…';
        }
        if (is_string($value) && ($value === '' || ! in_array($value[0], ['{', '['], true))) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $value;
        }

        return is_scalar($value) || is_array($value) || $value === null ? $value : (string) json_encode($value);
    }
}
