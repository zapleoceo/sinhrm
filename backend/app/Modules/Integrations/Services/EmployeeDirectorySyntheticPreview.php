<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

/** Stable fake-only fixture for exercising preview UI without connecting to a source system. */
final class EmployeeDirectorySyntheticPreview
{
    public function __construct(private readonly EmployeeDirectoryPreview $preview) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $namespace = 'synthetic-demo';

        return $this->preview->build([
            'namespace' => $namespace,
            'complete' => true,
            'profiles' => [
                ['source_id' => 'demo-employee-001', 'display_name' => 'Demo Employee One', 'branch_key' => 'demo-branch', 'position_key' => 'demo-position', 'status_code' => 'demo-active'],
                ['source_id' => 'demo-employee-001', 'display_name' => 'Demo Employee One', 'branch_key' => 'demo-branch', 'position_key' => 'demo-position', 'status_code' => 'demo-active'],
                ['source_id' => 'demo-employee-002', 'display_name' => 'Demo Employee Two', 'branch_key' => null, 'position_key' => null, 'status_code' => 'demo-unknown'],
                ['source_id' => 'demo-employee-003', 'display_name' => 'Demo Employee Three', 'branch_key' => 'demo-branch', 'position_key' => null, 'status_code' => 'demo-active'],
                ['source_id' => 'demo-employee-003', 'display_name' => 'Conflicting Demo Record', 'branch_key' => 'demo-other-branch', 'position_key' => null, 'status_code' => 'demo-active'],
            ],
        ], $namespace);
    }
}
