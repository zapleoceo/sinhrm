<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Modules\Auth\Enums\UserRole;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    public function test_standard_global_roles(): void
    {
        $this->assertSame(['superadmin', 'admin', 'hr_manager', 'recruiter', 'employee', 'viewer'], UserRole::values());
    }

    public function test_superadmin_is_not_invitable(): void
    {
        $this->assertSame(['admin', 'hr_manager', 'recruiter', 'employee', 'viewer'], UserRole::invitableValues());
    }

    public function test_role_groups(): void
    {
        $this->assertSame(['superadmin', 'admin', 'hr_manager'], UserRole::valuesOf(UserRole::hrStaff()));
        $this->assertSame(['superadmin', 'admin', 'recruiter'], UserRole::valuesOf(UserRole::recruitingWriters()));
        $this->assertNotContains(UserRole::Employee, UserRole::recruitingReaders());
        $this->assertNotContains(UserRole::HrManager, UserRole::recruitingWriters());
    }
}
