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
 * branch/department, and the per-segment decision is made once and stored on the wave (segment_visibility):
 * a closed wave never changes, so reads are O(1) and a close compares only against waves already decided.
 * Groups: a segment "s:<id>" (its members) and its complement "c:<id>" (everyone else in the wave).
 *
 * Fail-closed on key rotation: fingerprints are HMAC(APP_KEY). A snapshot made with another key is re-keyed through
 * APP_PREVIOUS_KEYS (and saved); if that key is unknown, the comparison is "unknown" and the group is hidden.
 */
final readonly class WaveMembership
{
    public const array KEYS = ['department_id', 'branch_id'];

    public function __construct(
        private WaveMemberRepository $members,
        private SurveyRepository $surveys,
        private EmployeeRepository $employees,
        private RespondentHash $hash,
    ) {}

    /** At close: store the audience snapshot, then decide which segments this wave may show. */
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
        $this->members->snapshot($wave->id, $rows, $this->hash->keyId());
        $this->decide($wave, $rows);
    }

    /**
     * Segments of $wave (by $key = branch_id | department_id) hidden by the differencing guard (decided at close).
     * Empty when the wave has no decision (closed before snapshots existed, or not closed yet).
     *
     * @return list<int>
     */
    public function hiddenSegments(SurveyWave $wave, string $key): array
    {
        $hidden = [];
        foreach ($wave->segment_visibility[$key] ?? [] as $group => $visible) {
            if (! $visible) {
                $hidden[(int) substr($group, 2)] = true;
            }
        }
        $ids = array_keys($hidden);
        sort($ids);

        return $ids;
    }

    /**
     * Symmetric differences between the audiences of two waves: whole scope, and per segment + complement.
     * null when either wave has no snapshot (then only answer counts are compared, as before);
     * ['unknown' => true] when a snapshot was made with a key that can no longer be matched (hide everything).
     *
     * @return array{unknown: bool, total: int, segments: array<int, array{own: int, rest: int}>}|null
     */
    public function differences(SurveyWave $a, SurveyWave $b, string $key, ?int $department): ?array
    {
        $snap = $this->members->membersOf([$a->id, $b->id]);
        if (! isset($snap[$a->id], $snap[$b->id])) {
            return null;
        }
        $ra = $this->current($a->id, $snap[$a->id]);
        $rb = $this->current($b->id, $snap[$b->id]);
        if ($ra === null || $rb === null) {
            return ['unknown' => true, 'total' => 0, 'segments' => []];
        }
        $scope = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $m): bool => $department === null || $m['department_id'] === $department,
        ));
        $ma = $scope($ra);
        $mb = $scope($rb);
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
            'unknown' => false,
            'total' => MembershipDifferencing::symmetricDifference(array_column($ma, 'member'), array_column($mb, 'member')),
            'segments' => $segments,
        ];
    }

    /**
     * A group is visible in this wave only if, for every already decided wave of the survey in which it was
     * visible, the member sets are equal or at least min apart. An earlier snapshot that cannot be matched
     * (missing or unknown key) where the group was visible → hidden (fail-closed). Decided waves are compared in
     * close order, so a wave closed late is still checked against every wave closed before it.
     *
     * @param  list<array{member: string, branch_id: int|null, department_id: int|null}>  $rows
     */
    private function decide(SurveyWave $wave, array $rows): void
    {
        $earlier = $this->surveys->decidedWaves($wave);
        $snap = $this->members->membersOf(array_map(static fn (SurveyWave $w): int => $w->id, $earlier));
        $theirGroups = [];
        foreach ($earlier as $e) {
            $theirs = isset($snap[$e->id]) ? $this->current($e->id, $snap[$e->id]) : null;
            foreach (self::KEYS as $key) {
                $theirGroups[$e->id][$key] = $theirs === null ? null : self::groups($theirs, $key);
            }
        }
        $decision = [];
        foreach (self::KEYS as $key) {
            $mine = self::groups($rows, $key);
            $decision[$key] = [];
            foreach ($mine as $group => $members) {
                $ok = true;
                foreach ($earlier as $e) {
                    if (! ($e->segment_visibility[$key][$group] ?? false)) {
                        continue; // not shown there: not a base
                    }
                    $theirs = $theirGroups[$e->id][$key];
                    if ($theirs === null) {
                        $ok = false;
                        break;
                    }
                    $d = MembershipDifferencing::symmetricDifference($members, $theirs[$group] ?? []);
                    if (! MembershipDifferencing::allowed($d, max($wave->min_group_size, $e->min_group_size))) {
                        $ok = false;
                        break;
                    }
                }
                $decision[$key][$group] = $ok;
            }
        }
        $this->surveys->updateWave($wave, ['segment_visibility' => $decision]);
        $wave->segment_visibility = $decision;
    }

    /**
     * Snapshot rows under the current key: as stored, or re-keyed (and saved) from a previous key; null if the key
     * that made them is unknown.
     *
     * @param  array{key_id: string, members: list<array{member: string, branch_id: int|null, department_id: int|null}>}  $snapshot
     * @return list<array{member: string, branch_id: int|null, department_id: int|null}>|null
     */
    private function current(int $waveId, array $snapshot): ?array
    {
        if ($snapshot['key_id'] === $this->hash->keyId()) {
            return $snapshot['members'];
        }
        $old = $this->hash->previousKey($snapshot['key_id']);
        if ($old === null) {
            return null;
        }
        $map = [];
        foreach ($this->members->allEmployeeIds() as $id) {
            $map[$this->hash->member($id, $old)] = $this->hash->member($id);
        }
        $rows = [];
        foreach ($snapshot['members'] as $m) {
            if (! isset($map[$m['member']])) {
                return null; // an employee row is gone: cannot re-key everyone, stay closed
            }
            $rows[] = ['member' => $map[$m['member']]] + $m;
        }
        $this->members->snapshot($waveId, $rows, $this->hash->keyId());

        return $rows;
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
