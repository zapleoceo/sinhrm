<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Repositories;

use App\Modules\Pulse\Contracts\WaveMemberRepository;
use Illuminate\Support\Facades\DB;

final class EloquentWaveMemberRepository implements WaveMemberRepository
{
    private const string TABLE = 'survey_wave_members';

    public function snapshot(int $waveId, array $members): void
    {
        // Sorted by fingerprint, so even the insert order says nothing about when anyone answered.
        usort($members, static fn (array $a, array $b): int => $a['member'] <=> $b['member']);
        DB::transaction(static function () use ($waveId, $members): void {
            DB::table(self::TABLE)->where('wave_id', $waveId)->delete();
            foreach (array_chunk($members, 500) as $chunk) {
                DB::table(self::TABLE)->insert(array_map(static fn (array $m): array => ['wave_id' => $waveId] + $m, $chunk));
            }
        });
    }

    public function membersOf(array $waveIds): array
    {
        if ($waveIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table(self::TABLE)->whereIn('wave_id', $waveIds)->orderBy('member')->get() as $row) {
            $out[(int) $row->wave_id][] = [
                'member' => (string) $row->member,
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'department_id' => $row->department_id === null ? null : (int) $row->department_id,
            ];
        }

        return $out;
    }
}
