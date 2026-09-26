<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Repositories;

use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Contracts\WaveMemberRepository;
use Illuminate\Support\Facades\DB;

final class EloquentWaveMemberRepository implements WaveMemberRepository
{
    private const string TABLE = 'survey_wave_members';

    public function snapshot(int $waveId, array $members, string $keyId): void
    {
        // Sorted by fingerprint, so even the insert order says nothing about when anyone answered.
        usort($members, static fn (array $a, array $b): int => $a['member'] <=> $b['member']);
        DB::transaction(static function () use ($waveId, $members, $keyId): void {
            DB::table(self::TABLE)->where('wave_id', $waveId)->delete();
            foreach (array_chunk($members, 500) as $chunk) {
                DB::table(self::TABLE)->insert(array_map(
                    static fn (array $m): array => ['wave_id' => $waveId, 'key_id' => $keyId] + $m,
                    $chunk,
                ));
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
            $id = (int) $row->wave_id;
            $out[$id]['key_id'] = (string) $row->key_id;
            $out[$id]['members'][] = [
                'member' => (string) $row->member,
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'department_id' => $row->department_id === null ? null : (int) $row->department_id,
            ];
        }

        return $out;
    }

    public function allEmployeeIds(): array
    {
        return Employee::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
