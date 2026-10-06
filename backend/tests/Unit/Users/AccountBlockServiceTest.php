<?php

declare(strict_types=1);

namespace Tests\Unit\Users;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\Services\AccountBlockService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Lifecycle block/unblock (People termination and restore) over the users admin repository. */
final class AccountBlockServiceTest extends TestCase
{
    private UserAdminRepository&MockObject $repo;

    private AccountBlockService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(UserAdminRepository::class);
        $this->repo->method('transaction')->willReturnCallback(fn (callable $cb): mixed => $cb());
        $this->service = new AccountBlockService($this->repo, new NullLogger);
    }

    public function test_block_sets_status_revokes_credentials_and_returns_the_new_version(): void
    {
        $user = $this->user(UserStatus::Active, 3);
        $this->repo->method('rolesOf')->willReturn([UserRole::Recruiter]);
        $this->repo->expects($this->once())->method('lockAndRefresh')->with($user);
        $this->repo->expects($this->once())->method('setStatus')->with($user, UserStatus::Blocked);
        $this->repo->expects($this->once())->method('revokeCredentials')->with($user)
            ->willReturnCallback(static function (User $u): void {
                $u->credential_version++;
            });

        $this->assertSame(4, $this->service->block($user, 1));
    }

    public function test_block_skips_an_already_blocked_user_and_the_last_superadmin(): void
    {
        $this->repo->expects($this->never())->method('setStatus');
        $this->repo->expects($this->never())->method('revokeCredentials');
        $this->assertNull($this->service->block($this->user(UserStatus::Blocked, 2), null));

        $this->repo->method('rolesOf')->willReturn([UserRole::Superadmin]);
        $this->repo->method('countActiveSuperadmins')->willReturn(1);
        $this->assertNull($this->service->block($this->user(UserStatus::Active, 0), null));
    }

    public function test_unblock_only_while_the_block_is_still_ours(): void
    {
        $this->repo->expects($this->once())->method('setStatus')->with($this->anything(), UserStatus::Active);

        $this->assertTrue($this->service->unblockIfBlockedBy($this->user(UserStatus::Blocked, 5), 5, 1));
        // blocked again by hand since (version moved on), or already active: untouched
        $this->assertFalse($this->service->unblockIfBlockedBy($this->user(UserStatus::Blocked, 6), 5, 1));
        $this->assertFalse($this->service->unblockIfBlockedBy($this->user(UserStatus::Active, 5), 5, 1));
    }

    private function user(UserStatus $status, int $version): User
    {
        $user = new User;
        $user->forceFill(['id' => 7, 'status' => $status, 'credential_version' => $version]);

        return $user;
    }
}
