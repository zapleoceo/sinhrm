<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Enums\EmployeeDirectoryGatewayState;
use App\Modules\Integrations\Exceptions\EmployeeDirectoryUnavailable;
use App\Modules\Integrations\Services\EmployeeDirectoryPreview;
use App\Modules\Integrations\Services\PendingEmployeeDirectoryGateway;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmployeeDirectoryPreviewTest extends TestCase
{
    public function test_gateway_is_dependency_pending_and_fetch_fails_without_network_fallback(): void
    {
        $gateway = new PendingEmployeeDirectoryGateway;

        self::assertSame(EmployeeDirectoryGatewayState::DependencyPending, $gateway->status()->status);
        self::assertNotEmpty($gateway->status()->missingInputs);
        try {
            $gateway->fetchCompleteSnapshot();
            self::fail('The pending gateway must not produce a source snapshot.');
        } catch (EmployeeDirectoryUnavailable $exception) {
            self::assertSame('dependency_pending', $exception->reason);
        }
    }

    public function test_preview_collapses_identical_duplicates_and_is_deterministic(): void
    {
        $preview = new EmployeeDirectoryPreview;
        $payload = $this->payload([
            $this->profile(),
            ['status_code' => 'active', 'position_key' => null, 'source_id' => 'profile-1', 'branch_key' => 'branch-a', 'display_name' => 'Example Person'],
        ]);

        $first = $preview->build($payload, 'company-a');
        self::assertSame($first, $preview->build($payload, 'company-a'));
        self::assertSame(1, $first['duplicate_count']);
        self::assertCount(1, $first['profiles']);
        self::assertSame('manual_identity_review', $first['profiles'][0]['link_action']);
        self::assertSame('unconfirmed', $first['profiles'][0]['mapping_state']);
    }

    public function test_numeric_source_ids_remain_opaque_strings(): void
    {
        $profile = [...$this->profile(), 'source_id' => '123'];
        $result = (new EmployeeDirectoryPreview)->build($this->payload([$profile]), 'company-a');

        self::assertSame('123', $result['profiles'][0]['source_id']);
    }

    public function test_conflicting_duplicate_ids_are_reported_without_a_link_plan_for_the_duplicate(): void
    {
        $result = (new EmployeeDirectoryPreview)->build($this->payload([
            $this->profile(),
            [...$this->profile(), 'display_name' => 'Different Example'],
        ]), 'company-a');

        self::assertSame('conflicts_found', $result['status']);
        self::assertSame('duplicate_id_conflicting_record', $result['conflicts'][0]['code']);
        self::assertCount(0, $result['profiles']);
    }

    public function test_duplicate_conflicts_and_exact_repeat_counts_do_not_depend_on_row_order(): void
    {
        $firstVariant = $this->profile();
        $secondVariant = [...$firstVariant, 'display_name' => 'Other Example'];
        $preview = new EmployeeDirectoryPreview;
        $forward = $preview->build($this->payload([$firstVariant, $secondVariant, $secondVariant]), 'company-a');
        $reverse = $preview->build($this->payload([$secondVariant, $secondVariant, $firstVariant]), 'company-a');

        self::assertSame($forward, $reverse);
        self::assertSame(1, $forward['duplicate_count']);
        self::assertCount(1, $forward['conflicts']);
        self::assertCount(0, $forward['profiles']);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidSnapshots')]
    public function test_unknown_or_incomplete_snapshot_is_rejected(string $reason, array $payload): void
    {
        try {
            (new EmployeeDirectoryPreview)->build($payload, 'company-a');
            self::fail('Invalid snapshots must not yield a preview plan.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function invalidSnapshots(): array
    {
        $valid = ['namespace' => 'company-a', 'complete' => true, 'profiles' => []];
        $unknownShape = [...$valid, 'tenant' => 'guessed'];
        $incomplete = [...$valid, 'complete' => false];
        $wrongNamespace = [...$valid, 'namespace' => 'another-company'];
        $invalidId = ['namespace' => 'company-a', 'complete' => true, 'profiles' => [[
            'source_id' => '', 'display_name' => 'Example Person', 'branch_key' => null, 'position_key' => null, 'status_code' => 'active',
        ]]];
        $controlId = ['namespace' => 'company-a', 'complete' => true, 'profiles' => [[
            'source_id' => "profile-1\n", 'display_name' => 'Example Person', 'branch_key' => null, 'position_key' => null, 'status_code' => 'active',
        ]]];

        return [
            'unknown envelope field' => ['snapshot_shape_unknown', $unknownShape],
            'incomplete page set' => ['snapshot_incomplete', $incomplete],
            'unconfigured namespace' => ['namespace_scope_mismatch', $wrongNamespace],
            'invalid source id' => ['profile_source_id_invalid', $invalidId],
            'source id with a control character' => ['profile_source_id_invalid', $controlId],
        ];
    }

    /** @param list<array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    private function payload(array $profiles): array
    {
        return ['namespace' => 'company-a', 'complete' => true, 'profiles' => $profiles];
    }

    /** @return array{source_id: string, display_name: string, branch_key: string, position_key: null, status_code: string} */
    private function profile(): array
    {
        return ['source_id' => 'profile-1', 'display_name' => 'Example Person', 'branch_key' => 'branch-a', 'position_key' => null, 'status_code' => 'active'];
    }
}
