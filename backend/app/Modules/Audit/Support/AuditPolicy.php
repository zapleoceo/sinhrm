<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use LogicException;

/**
 * What the audit log may keep. ALLOW-LIST: per entity type only explicitly safe fields keep their values;
 * every other field (document bodies, file paths, salary, contacts, notes, secrets, anything added later)
 * is recorded only as "changed" with the mask "***".
 *  - anonymity modules (Safe Speak, Pulse surveys/mood) are never logged, not even "something changed";
 *  - technical noise (timestamps, touch counters) is not logged at all.
 */
final class AuditPolicy
{
    public const string MASK = '***';

    /**
     * Fields whose values are shown, per entity type: ids, statuses, dates of decisions, titles, flags.
     * A field that is not here is masked. Adding a field here = a deliberate "this is not personal" decision.
     */
    public const array SAFE_FIELDS = [
        'user' => ['name', 'status', 'locale', 'invited_by', 'role'],
        'integration' => ['key', 'status'],
        'ai_prompt_version' => ['purpose', 'version', 'base_version', 'author_id', 'is_active', 'activated_by', 'activated_at'],
        'employee' => [
            'user_id', 'hired_at', 'fired_at', 'status', 'employment_type', 'branch_id', 'department_id',
            'position_id', 'manager_id', 'candidate_id', 'application_id',
        ],
        'vacancy' => [
            'title', 'branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id',
            'status', 'opened_at', 'closed_at',
        ],
        'candidate' => ['city_id', 'source', 'channel_id', 'added_via', 'owner_id', 'created_by'],
        'application' => ['candidate_id', 'vacancy_id', 'stage_id', 'status', 'reject_reason_id', 'closed_at'],
        'leave_request' => [
            'employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'half_day', 'days', 'status', 'balance_override',
            'approver_id', 'decided_at', 'created_by',
        ],
        'document' => ['employee_id', 'template_id', 'category', 'status', 'created_by', 'sent_at'],
        'hiring_request' => [
            'title', 'branch_id', 'department_id', 'position_id', 'headcount', 'reason', 'replaced_employee_id',
            'desired_start_date', 'priority', 'status', 'requester_id', 'recruiter_id', 'vacancy_id', 'submitted_at',
            'decided_at', 'closed_at',
        ],
        'hiring_approval' => [
            'hiring_request_id', 'position', 'name', 'kind', 'role', 'approver_id', 'sla_days', 'status', 'activated_at',
            'due_at', 'decided_by', 'decided_at',
        ],
        'workflow_template' => ['name', 'kind', 'trigger', 'active', 'probation_days', 'created_by'],
    ];

    /** Namespaces whose models must never reach the log (anonymous by design). */
    private const array EXCLUDED_NAMESPACES = ['App\Modules\SafeSpeak', 'App\Modules\Pulse'];

    /** Entity types that are refused even when recorded by hand. */
    private const array EXCLUDED_ENTITY_PREFIXES = ['safe_speak', 'pulse', 'survey', 'mood'];

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

    public function isSafe(string $entityType, string $field): bool
    {
        return in_array($field, self::SAFE_FIELDS[$entityType] ?? [], true);
    }

    /**
     * Drops ignored fields, masks every field outside the entity's allow-list, normalises values to JSON-safe data.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function sanitize(string $entityType, array $changes): array
    {
        $clean = [];
        foreach ($changes as $field => $pair) {
            if ($this->isIgnored($field)) {
                continue;
            }
            if (! $this->isSafe($entityType, $field)) {
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

        return is_scalar($value) || is_array($value) || $value === null ? $value : (string) json_encode($value);
    }
}
