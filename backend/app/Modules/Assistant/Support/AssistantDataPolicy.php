<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

/**
 * Automatic API → AI boundary, independent of (and stricter than) the caller's API permissions.
 * Exact resource positions only: a familiar field name inside custom JSON is never sufficient.
 * IDs are pseudonymous references, not anonymous data. No strings, dates, money or free text pass.
 */
final class AssistantDataPolicy
{
    private const array PEOPLE_IDS = ['id', 'user_id', 'candidate_id', 'branch_id', 'department_id', 'position_id', 'manager_id'];

    private const array VACANCY_IDS = ['id', 'branch_id', 'department_id', 'position_id', 'recruiter_id', 'hiring_manager_id', 'pipeline_id', 'category_id', 'city_id'];

    private const array APPLICATION_IDS = ['id', 'candidate_id', 'vacancy_id', 'stage_id', 'reject_reason_id'];

    private const array PAGINATION = ['current_page', 'last_page', 'per_page', 'from', 'to', 'total'];

    public static function readPath(string $path): ?string
    {
        $clean = ApiPath::normalize($path);

        return $clean !== null && self::resource($clean) !== null ? $clean : null;
    }

    /** Known UI routes only; no arbitrary path segments or query values in model context. */
    public static function pagePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $static = ['/', '/tasks', '/vacancies', '/candidates', '/people', '/people/org-chart', '/me', '/me/documents',
            '/inbox', '/timeoff', '/timeoff/approvals', '/timeoff/calendar', '/time', '/time/approvals', '/time/team',
            '/hiring-requests', '/hiring-requests/new', '/hiring-requests/inbox', '/workflows/runs',
            '/perform/one-on-ones', '/perform/objectives', '/perform/feedback', '/perform/reviews', '/pulse', '/pulse/mood',
            '/knowledge', '/desk', '/desk/queue', '/safe-speak', '/reports', '/reports/catalog', '/reports/builder',
            '/settings/extension', '/docs', '/admin/users', '/admin/modules', '/admin/integrations', '/admin/scripts',
            '/admin/workflows', '/admin/directory', '/admin/audit', '/admin/privacy', '/admin/timeoff', '/admin/time',
            '/admin/pulse', '/admin/assets', '/admin/mail', '/admin/errors'];
        if (in_array($path, $static, true) || preg_match('~^/(people|candidates|vacancies|tasks|hiring-requests|knowledge|desk/cases)/[1-9][0-9]*$~D', $path) === 1) {
            return $path;
        }

        return '/';
    }

    /** @return array<string, mixed> */
    public static function project(string $path, mixed $payload): array
    {
        $resource = self::resource($path);
        if ($resource === null || ! is_array($payload)) {
            return [];
        }
        if ($path === 'auth/me') {
            return self::ids($payload, ['id']);
        }
        $out = [];
        if (array_key_exists('data', $payload) && is_array($payload['data'])) {
            $out['data'] = self::rows($payload['data'], $resource);
        }
        if (is_array($payload['meta'] ?? null)) {
            $out['meta'] = [];
            foreach (self::PAGINATION as $key) {
                $value = $payload['meta'][$key] ?? null;
                if (is_int($value) && $value >= 0) {
                    $out['meta'][$key] = $value;
                }
            }
        }

        return $out;
    }

    private static function resource(string $path): ?string
    {
        return match (true) {
            $path === 'auth/me' => 'auth',
            in_array($path, ['people', 'people/search', 'people/lookup', 'me/employee'], true),
            preg_match('~^people/[1-9][0-9]*$~D', $path) === 1 => 'people',
            $path === 'candidates', preg_match('~^candidates/[1-9][0-9]*$~D', $path) === 1 => 'candidate',
            $path === 'vacancies', preg_match('~^vacancies/[1-9][0-9]*$~D', $path) === 1 => 'vacancy',
            $path === 'tasks', preg_match('~^tasks/[1-9][0-9]*$~D', $path) === 1 => 'task',
            $path === 'pipelines' => 'pipeline',
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private static function rows(array $value, string $resource): array
    {
        if (! array_is_list($value)) {
            return self::record($value, $resource);
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row) && ! array_is_list($row)) {
                $out[] = self::record($row, $resource);
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    private static function record(array $row, string $resource): array
    {
        $ids = match ($resource) {
            'people' => self::PEOPLE_IDS,
            'candidate' => ['id', 'city_id', 'channel_id', 'owner_id'],
            'vacancy' => self::VACANCY_IDS,
            'task' => ['id', 'assignee_id', 'application_id'],
            'application' => self::APPLICATION_IDS,
            default => ['id'],
        };
        $out = self::ids($row, $ids);
        $refs = match ($resource) {
            'people' => ['branch', 'department', 'position', 'manager'],
            'candidate' => ['city', 'channel', 'owner'],
            'vacancy' => ['branch', 'department', 'position', 'recruiter', 'hiring_manager', 'category', 'city'],
            'task' => ['employee', 'candidate', 'vacancy'],
            'application' => ['candidate', 'vacancy', 'stage'],
            default => [],
        };
        foreach ($refs as $key) {
            if (is_array($row[$key] ?? null) && ! array_is_list($row[$key])) {
                $out[$key] = self::ids($row[$key], ['id']);
            }
        }
        $lists = match ($resource) {
            'candidate' => ['applications' => 'application'],
            'vacancy', 'pipeline' => ['stages' => 'reference'],
            'application' => ['stages' => 'reference', 'interviewers' => 'reference'],
            default => [],
        };
        foreach ($lists as $key => $type) {
            if (is_array($row[$key] ?? null) && array_is_list($row[$key])) {
                $out[$key] = self::rows($row[$key], $type);
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private static function ids(array $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_int($value) && $value > 0) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
