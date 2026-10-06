<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

/**
 * Reject reasons report slices (tz4): reason × stage and reason × recruiter, summed from the rows of
 * ReportRepository::rejectionBreakdown. Pure math, stable order: count desc, then reason name, then the slice
 * (stage position / recruiter name), unknown values ("stage not defined", "not assigned", "hidden") last, then ids.
 */
final class RejectionBreakdown
{
    public const string RECRUITER_USER = 'user';

    /** The author has no recruiting role (e.g. a hiring manager): counted, but not named. */
    public const string RECRUITER_HIDDEN = 'hidden';

    /** No author in the history (system step or the user was deleted). */
    public const string RECRUITER_UNASSIGNED = 'unassigned';

    /**
     * @param  list<array{reject_reason_id: int|null, name: string|null, stage_id: int|null, stage_name: string|null, stage_position: int|null, user_id: int|null, user_name: string|null, count: int}>  $rows
     * @return list<array{reject_reason_id: int|null, name: string|null, stage_id: int|null, stage_name: string|null, count: int}>
     */
    public static function byStage(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $key = ($r['reject_reason_id'] ?? 0).':'.($r['stage_id'] ?? 0);
            $out[$key] ??= ['reject_reason_id' => $r['reject_reason_id'], 'name' => $r['name'], 'stage_id' => $r['stage_id'],
                'stage_name' => $r['stage_name'], 'position' => $r['stage_position'], 'count' => 0];
            $out[$key]['count'] += $r['count'];
        }
        $list = array_values($out);
        usort($list, static fn (array $a, array $b): int => self::head($a, $b)
            ?: self::nullsLast($a['position'], $b['position'])
            ?: self::nullsLast($a['stage_name'], $b['stage_name'])
            ?: ($a['stage_id'] ?? PHP_INT_MAX) <=> ($b['stage_id'] ?? PHP_INT_MAX));

        return array_map(static function (array $r): array {
            unset($r['position']);

            return $r;
        }, $list);
    }

    /**
     * @param  list<array{reject_reason_id: int|null, name: string|null, stage_id: int|null, stage_name: string|null, stage_position: int|null, user_id: int|null, user_name: string|null, count: int}>  $rows
     * @param  list<int>  $named  users with a recruiting role (only they are named)
     * @return list<array{reject_reason_id: int|null, name: string|null, recruiter_id: int|null, recruiter_name: string|null, recruiter_state: string, count: int}>
     */
    public static function byRecruiter(array $rows, array $named): array
    {
        $out = [];
        foreach ($rows as $r) {
            $state = match (true) {
                $r['user_id'] === null => self::RECRUITER_UNASSIGNED,
                in_array($r['user_id'], $named, true) => self::RECRUITER_USER,
                default => self::RECRUITER_HIDDEN,
            };
            $id = $state === self::RECRUITER_USER ? $r['user_id'] : null;
            $key = ($r['reject_reason_id'] ?? 0).':'.$state.':'.($id ?? 0);
            $out[$key] ??= ['reject_reason_id' => $r['reject_reason_id'], 'name' => $r['name'], 'recruiter_id' => $id,
                'recruiter_name' => $id === null ? null : $r['user_name'], 'recruiter_state' => $state, 'count' => 0];
            $out[$key]['count'] += $r['count'];
        }
        $rank = [self::RECRUITER_USER => 0, self::RECRUITER_HIDDEN => 1, self::RECRUITER_UNASSIGNED => 2];
        $list = array_values($out);
        usort($list, static fn (array $a, array $b): int => self::head($a, $b)
            ?: $rank[$a['recruiter_state']] <=> $rank[$b['recruiter_state']]
            ?: self::nullsLast($a['recruiter_name'], $b['recruiter_name'])
            ?: ($a['recruiter_id'] ?? 0) <=> ($b['recruiter_id'] ?? 0));

        return $list;
    }

    /**
     * Count desc, reason name (no reason last), reason id.
     *
     * @param  array{reject_reason_id: int|null, name: string|null, count: int}  $a
     * @param  array{reject_reason_id: int|null, name: string|null, count: int}  $b
     */
    private static function head(array $a, array $b): int
    {
        return $b['count'] <=> $a['count']
            ?: self::nullsLast($a['name'], $b['name'])
            ?: ($a['reject_reason_id'] ?? PHP_INT_MAX) <=> ($b['reject_reason_id'] ?? PHP_INT_MAX);
    }

    private static function nullsLast(int|string|null $a, int|string|null $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => 1,
            $b === null => -1,
            default => is_string($a) && is_string($b) ? strcmp($a, $b) : $a <=> $b,
        };
    }
}
