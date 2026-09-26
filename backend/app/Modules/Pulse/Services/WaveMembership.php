<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Modules\Core\Support\MembershipDifferencing;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Contracts\WaveMemberRepository;
use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Support\RespondentHash;
use App\Modules\Pulse\Support\WaveAudience;

/**
 * Audience snapshots of closed waves and the differencing guard built on them (see MembershipDifferencing).
 *
 * At close the wave's audience (who was ASKED, not who answered) is stored as member fingerprints with their
 * branch/department. Nothing ties a fingerprint to an answer: responses carry no employee id and no fingerprint,
 * and the snapshot is taken at close, sorted by fingerprint. Groups compared: a segment "s:<id>" (its members) and
 * its complement "c:<id>" (everyone else in the wave): showing a segment next to the wave total shows both.
 */
final readonly class WaveMembership
{
    public function __construct(
        private WaveMemberRepository $members,
        private SurveyRepository $surveys,
        private EmployeeRepository $employees,
        private RespondentHash $hash,
    ) {}

    public function snapshot(SurveyWave $wave): void
    {
        if ($wave->isLifecycle()) {
            return; // one known subject, results are never compared or broken down
        }
        $rows = $this->employees->working()
            ->filter(static fn (Employee $e): bool => WaveAudience::includes($wave, $e))
            ->map(fn (Employee $e): array => [
                'member' => $this->hash->member($e->id),
                'branch_id' => $e->branch_id,
                'department_id' => $e->department_id,
            ])->values()->all();
        $this->members->snapshot($wave->id, $rows);
    }

    /**
     * Segments of $wave (by $key = branch_id | department_id) that must not be shown because an earlier visible
     * release of the same segment (or of its complement) in this survey had a membership a handful of people away.
     * Empty when the wave has no snapshot (closed before snapshots existed, or not closed yet).
     *
     * @return list<int>
     */
    public function hiddenSegments(SurveyWave $wave, string $key): array
    {
        $series = [...$this->surveys->closedWavesBefore($wave), $wave];
        $snapshots = $this->members->membersOf(array_map(static fn (SurveyWave $w): int => $w->id, $series));
        if (! isset($snapshots[$wave->id])) {
            return [];
        }
        $releases = [];
        $ids = [];
        foreach ($series as $w) {
            if (isset($snapshots[$w->id])) {
                $releases[] = ['min' => $w->min_group_size, 'groups' => self::groups($snapshots[$w->id], $key)];
            }
        }
        foreach ($snapshots[$wave->id] as $m) {
            if ($m[$key] !== null) {
                $ids[$m[$key]] = true;
            }
        }
        $visible = MembershipDifferencing::visibility($releases);
        $last = $visible[array_key_last($visible)];
        $hidden = [];
        foreach (array_keys($ids) as $id) {
            if (! ($last['s:'.$id] ?? true) || ! ($last['c:'.$id] ?? true)) {
                $hidden[] = (int) $id;
            }
        }
        sort($hidden);

        return $hidden;
    }

    /**
     * Symmetric differences between the audiences of two waves: whole scope, and per segment + complement.
     * null when either wave has no snapshot (then only answer counts are compared, as before).
     *
     * @return array{total: int, segments: array<int, array{own: int, rest: int}>}|null
     */
    public function differences(SurveyWave $a, SurveyWave $b, string $key, ?int $department): ?array
    {
        $snap = $this->members->membersOf([$a->id, $b->id]);
        if (! isset($snap[$a->id], $snap[$b->id])) {
            return null;
        }
        $scope = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $m): bool => $department === null || $m['department_id'] === $department,
        ));
        $ma = $scope($snap[$a->id]);
        $mb = $scope($snap[$b->id]);
        $ga = self::groups($ma, $key);
        $gb = self::groups($mb, $key);
        $segments = [];
        foreach (array_unique([...array_column($ma, $key), ...array_column($mb, $key)]) as $id) {
            if ($id === null) {
                continue;
            }
            $segments[(int) $id] = [
                'own' => MembershipDifferencing::symmetricDifference($ga['s:'.$id] ?? [], $gb['s:'.$id] ?? []),
                'rest' => MembershipDifferencing::symmetricDifference($ga['c:'.$id] ?? [], $gb['c:'.$id] ?? []),
            ];
        }

        return [
            'total' => MembershipDifferencing::symmetricDifference(array_column($ma, 'member'), array_column($mb, 'member')),
            'segments' => $segments,
        ];
    }

    /**
     * @param  list<array{member: string, branch_id: int|null, department_id: int|null}>  $rows
     * @return array<string, list<string>> "s:<id>" segment members, "c:<id>" everyone else in the wave
     */
    private static function groups(array $rows, string $key): array
    {
        $all = array_column($rows, 'member');
        $by = [];
        foreach ($rows as $m) {
            if ($m[$key] !== null) {
                $by[(int) $m[$key]][] = $m['member'];
            }
        }
        $out = [];
        foreach ($by as $id => $members) {
            $out['s:'.$id] = $members;
            $out['c:'.$id] = array_values(array_diff($all, $members));
        }

        return $out;
    }
}
