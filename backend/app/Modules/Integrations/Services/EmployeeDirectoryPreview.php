<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\DTO\EmployeeDirectorySnapshot;
use InvalidArgumentException;

/** Validates a canonical snapshot and produces a read-only source-identity review plan. */
final class EmployeeDirectoryPreview
{
    private const array ENVELOPE_KEYS = ['namespace', 'complete', 'profiles'];

    private const array PROFILE_KEYS = ['source_id', 'display_name', 'branch_key', 'position_key', 'status_code'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, namespace: string, profiles: list<array<string, mixed>>, duplicate_count: int, conflicts: list<array{source_id: string|null, code: string}>}
     */
    public function build(array $payload, string $expectedNamespace): array
    {
        $this->assertExactKeys($payload, self::ENVELOPE_KEYS, 'snapshot_shape_unknown');
        $namespace = $payload['namespace'] ?? null;
        if (! is_string($namespace) || trim($namespace) === '') {
            throw new InvalidArgumentException('namespace_required');
        }
        if ($expectedNamespace === '' || trim($namespace) !== $expectedNamespace) {
            throw new InvalidArgumentException('namespace_scope_mismatch');
        }
        if (($payload['complete'] ?? null) !== true) {
            throw new InvalidArgumentException('snapshot_incomplete');
        }
        $rawProfiles = $payload['profiles'] ?? null;
        if (! is_array($rawProfiles) || ! array_is_list($rawProfiles)) {
            throw new InvalidArgumentException('profiles_must_be_list');
        }

        $profiles = array_map($this->normalizeProfile(...), $rawProfiles);
        $snapshot = new EmployeeDirectorySnapshot(trim($namespace), true, $profiles);
        /** @var array<string, array{source_id: string, first_profile: array{source_id: string, display_name: string, branch_key: string|null, position_key: string|null, status_code: string}, variants: array<string, true>}> $recordsById */
        $recordsById = [];
        $duplicates = 0;

        foreach ($snapshot->profiles as $profile) {
            $sourceId = $profile['source_id'];
            $identityKey = 'id:'.$sourceId;
            $recordKey = hash('sha256', serialize($profile));
            if (!isset($recordsById[$identityKey])) {
                $recordsById[$identityKey] = ['source_id' => $sourceId, 'first_profile' => $profile, 'variants' => []];
            }
            if (isset($recordsById[$identityKey]['variants'][$recordKey])) {
                $duplicates++;
                continue;
            }
            $recordsById[$identityKey]['variants'][$recordKey] = true;
        }

        $profiles = [];
        $conflicts = [];
        ksort($recordsById, SORT_STRING);
        foreach ($recordsById as $record) {
            if (count($record['variants']) !== 1) {
                $conflicts[] = ['source_id' => $record['source_id'], 'code' => 'duplicate_id_conflicting_record'];
                continue;
            }
            $profile = $record['first_profile'];
            $profiles[] = [
                'source_id' => $profile['source_id'],
                'link_action' => 'manual_identity_review',
                'mapping_state' => 'unconfirmed',
                'mapping_conflicts' => ['branch_mapping_unconfirmed', 'position_mapping_unconfirmed', 'status_mapping_unconfirmed'],
                'display_name' => $profile['display_name'],
                'branch_key' => $profile['branch_key'] ?? '',
                'position_key' => $profile['position_key'] ?? '',
                'status_code' => $profile['status_code'],
            ];
        }

        return [
            'status' => $conflicts === [] ? 'preview_only' : 'conflicts_found',
            'namespace' => $snapshot->namespace,
            'profiles' => $profiles,
            'duplicate_count' => $duplicates,
            'conflicts' => $conflicts,
        ];
    }

    /** @param array<array-key, mixed> $value
     *  @param list<string> $expected
     */
    private function assertExactKeys(array $value, array $expected, string $reason): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException($reason);
        }
    }

    /** @return array{source_id: string, display_name: string, branch_key: string|null, position_key: string|null, status_code: string} */
    private function normalizeProfile(mixed $profile): array
    {
        if (! is_array($profile)) {
            throw new InvalidArgumentException('profile_shape_invalid');
        }
        $this->assertExactKeys($profile, self::PROFILE_KEYS, 'profile_shape_unknown');

        $sourceId = $profile['source_id'] ?? null;
        $displayName = $profile['display_name'] ?? null;
        $statusCode = $profile['status_code'] ?? null;
        $branchKey = $profile['branch_key'] ?? null;
        $positionKey = $profile['position_key'] ?? null;
        if (! is_string($sourceId) || trim($sourceId) === '' || strlen(trim($sourceId)) > 255 || preg_match('/[\x00-\x1F\x7F]/', $sourceId) === 1) {
            throw new InvalidArgumentException('profile_source_id_invalid');
        }
        foreach (['display_name' => $displayName, 'status_code' => $statusCode] as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('profile_'.$field.'_invalid');
            }
        }
        if ($branchKey !== null && ! is_string($branchKey)) {
            throw new InvalidArgumentException('profile_branch_key_invalid');
        }
        if ($positionKey !== null && ! is_string($positionKey)) {
            throw new InvalidArgumentException('profile_position_key_invalid');
        }

        return [
            'source_id' => trim($sourceId),
            'display_name' => trim($displayName),
            'branch_key' => $branchKey === null ? null : trim($branchKey),
            'position_key' => $positionKey === null ? null : trim($positionKey),
            'status_code' => trim($statusCode),
        ];
    }
}
