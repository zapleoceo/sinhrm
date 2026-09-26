<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Contracts;

/** Audience snapshots of closed waves (fingerprints only). System use: never exposed through the API. */
interface WaveMemberRepository
{
    /**
     * Replaces the snapshot of a wave.
     *
     * @param  list<array{member: string, branch_id: int|null, department_id: int|null}>  $members
     */
    public function snapshot(int $waveId, array $members): void;

    /**
     * Snapshots of the given waves; a wave without a snapshot (closed before snapshots existed) is absent.
     *
     * @param  list<int>  $waveIds
     * @return array<int, list<array{member: string, branch_id: int|null, department_id: int|null}>>
     */
    public function membersOf(array $waveIds): array;
}
