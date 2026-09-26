<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Support;

/**
 * Who may read a published article (pure):
 * - {"type":"all"} — every active user;
 * - {"type":"branches","ids":[..]} — users whose employee record or whose working branches are in the list;
 * - {"type":"roles","roles":[..]} — users with one of the roles.
 * Admins (HR) read everything, drafts included — decided by the caller.
 */
final class Audience
{
    public const string ALL = 'all';

    public const string BRANCHES = 'branches';

    public const string ROLES = 'roles';

    /**
     * @param  array<string, mixed>  $audience
     * @return array{type: string, ids?: list<int>, roles?: list<string>}
     */
    public static function normalize(array $audience): array
    {
        $type = (string) ($audience['type'] ?? self::ALL);
        if ($type === self::BRANCHES) {
            $ids = array_values(array_unique(array_map(intval(...), (array) ($audience['ids'] ?? []))));
            sort($ids);

            return ['type' => self::BRANCHES, 'ids' => $ids];
        }
        if ($type === self::ROLES) {
            $roles = array_values(array_unique(array_map(strval(...), (array) ($audience['roles'] ?? []))));
            sort($roles);

            return ['type' => self::ROLES, 'roles' => $roles];
        }

        return ['type' => self::ALL];
    }

    /**
     * @param  array<string, mixed>  $audience
     * @param  list<int>  $branchIds  the reader's employee branch and working branches
     * @param  list<string>  $roles
     */
    public static function allows(array $audience, array $branchIds, array $roles): bool
    {
        $a = self::normalize($audience);

        return match ($a['type']) {
            self::BRANCHES => array_intersect($a['ids'] ?? [], $branchIds) !== [],
            self::ROLES => array_intersect($a['roles'] ?? [], $roles) !== [],
            default => true,
        };
    }
}
