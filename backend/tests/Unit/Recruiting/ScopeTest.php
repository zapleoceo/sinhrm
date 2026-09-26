<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\DTO\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function test_unrestricted_allows_any_vacancy(): void
    {
        $this->assertTrue((new Scope(1, null))->allowsVacancy(5, 9));
    }

    public function test_branch_or_managed_vacancy(): void
    {
        $scope = new Scope(1, [3], [7], [11]);

        $this->assertTrue($scope->allowsVacancy(1, 3));
        $this->assertTrue($scope->allowsVacancy(7, 99));
        $this->assertFalse($scope->allowsVacancy(8, 99));
        $this->assertTrue($scope->hasContextualAccess());
        $this->assertFalse((new Scope(1, []))->hasContextualAccess());
    }
}
