<?php

declare(strict_types=1);

namespace Tests\Unit\Users;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\DTO\UserFilter;
use App\Modules\Users\Exceptions\UserAdminException;
use App\Modules\Users\Services\UserAdminService;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UserAdminServiceTest extends TestCase
{
    private UserAdminRepository&MockObject $repo;

    private UserAdminService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(UserAdminRepository::class);
        $this->repo->method('transaction')->willReturnCallback(fn (callable $cb): mixed => $cb());
        $this->service = new UserAdminService($this->repo, new NullLogger, $this->createStub(AuditLogger::class));
    }

    public function test_list_delegates_filter(): void
    {
        $filter = new UserFilter(q: 'a', perPage: 5);
        $page = $this->createStub(LengthAwarePaginator::class);
        $this->repo->expects($this->once())->method('paginate')->with($filter)->willReturn($page);

        $this->assertSame($page, $this->service->list($filter));
    }

    public function test_invite_rejects_existing_email_with_409(): void
    {
        $this->repo->method('emailExists')->willReturn(true);
        $this->repo->expects($this->never())->method('invite');

        $e = $this->catch(fn () => $this->service->invite($this->user(1), 'a@example.com', 'A', UserRole::Viewer));
        $this->assertSame(409, $e->status);
        $this->assertSame('email_taken', $e->errorCode);
    }

    public function test_invite_creates_user(): void
    {
        $actor = $this->user(1);
        $created = $this->user(2);
        $this->repo->method('emailExists')->willReturn(false);
        $this->repo->expects($this->once())->method('invite')->with('a@example.com', 'A', UserRole::Recruiter, $actor)->willReturn($created);

        $this->assertSame($created, $this->service->invite($actor, 'a@example.com', 'A', UserRole::Recruiter));
    }

    public function test_self_change_is_422(): void
    {
        $me = $this->user(1);
        $this->repo->expects($this->never())->method('setStatus');

        $e = $this->catch(fn () => $this->service->update($me, $me, null, UserStatus::Blocked));
        $this->assertSame(422, $e->status);
        $this->assertSame('self_change_forbidden', $e->errorCode);
    }

    public function test_empty_update_is_noop(): void
    {
        $me = $this->user(1);
        $this->repo->expects($this->never())->method('transaction');

        $this->assertSame($me, $this->service->update($me, $me, null, null));
    }

    public function test_last_active_superadmin_cannot_be_blocked_or_demoted(): void
    {
        $this->repo->method('roleOf')->willReturn(UserRole::Superadmin);
        $this->repo->method('countActiveSuperadmins')->willReturn(1);
        $this->repo->expects($this->never())->method('setStatus');
        $this->repo->expects($this->never())->method('setRole');

        foreach ([[null, UserStatus::Blocked], [UserRole::Admin, null]] as [$role, $status]) {
            $e = $this->catch(fn () => $this->service->update($this->user(1), $this->user(2), $role, $status));
            $this->assertSame('last_superadmin', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_superadmin_can_be_blocked_when_another_is_active(): void
    {
        $target = $this->user(2);
        $this->repo->method('roleOf')->willReturn(UserRole::Superadmin);
        $this->repo->method('countActiveSuperadmins')->willReturn(2);
        $this->repo->expects($this->once())->method('setStatus')->with($target, UserStatus::Blocked);

        $this->service->update($this->user(1), $target, null, UserStatus::Blocked);
    }

    public function test_role_and_status_are_applied(): void
    {
        $target = $this->user(2);
        $this->repo->method('roleOf')->willReturn(UserRole::Viewer);
        $this->repo->expects($this->once())->method('setRole')->with($target, UserRole::Admin);
        $this->repo->expects($this->once())->method('setStatus')->with($target, UserStatus::Active);

        $this->assertSame($target, $this->service->update($this->user(1), $target, UserRole::Admin, UserStatus::Active));
    }

    public function test_branches_are_synced_only_when_sent(): void
    {
        $target = $this->user(2);
        $this->repo->method('roleOf')->willReturn(UserRole::Recruiter);
        $this->repo->expects($this->once())->method('syncBranches')->with($target, [3, 5]);
        $this->repo->expects($this->never())->method('setRole');

        $this->assertSame($target, $this->service->update($this->user(1), $target, null, null, [3, 5]));
        $this->service->update($this->user(1), $target, null, null, null);
    }

    public function test_own_branches_cannot_be_changed(): void
    {
        $me = $this->user(1);
        $this->repo->expects($this->never())->method('syncBranches');

        $this->assertSame('self_change_forbidden', $this->catch(fn () => $this->service->update($me, $me, null, null, []))->errorCode);
    }

    public function test_exception_renders_json_with_status(): void
    {
        $response = UserAdminException::emailTaken()->render();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['message' => 'email_taken', 'code' => 'email_taken'], $response->getData(true));
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id, 'status' => UserStatus::Active]);
    }

    private function catch(callable $call): UserAdminException
    {
        try {
            $call();
        } catch (UserAdminException $e) {
            return $e;
        }
        $this->fail('UserAdminException expected');
    }
}
