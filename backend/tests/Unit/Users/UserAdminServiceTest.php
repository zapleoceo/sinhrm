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
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UserAdminServiceTest extends TestCase
{
    private UserAdminRepository&MockObject $repo;

    private UserAdminService $service;

    private Gate&Stub $gate;

    private bool $actorIsSuperadmin = true;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(UserAdminRepository::class);
        $this->repo->method('transaction')->willReturnCallback(fn (callable $cb): mixed => $cb());
        // Gate manage-superadmins: allowed unless a test says otherwise.
        $this->gate = $this->createStub(Gate::class);
        $this->gate->method('forUser')->willReturnSelf();
        $this->gate->method('allows')->willReturnCallback(fn (): bool => $this->actorIsSuperadmin);
        $this->service = new UserAdminService($this->repo, new NullLogger, $this->createStub(AuditLogger::class), $this->gate);
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
        $this->repo->method('rolesOf')->willReturn([UserRole::Superadmin]);
        $this->repo->method('countActiveSuperadmins')->willReturn(1);
        $this->repo->expects($this->never())->method('setStatus');
        $this->repo->expects($this->never())->method('setRoles');
        $this->repo->expects($this->never())->method('revokeCredentials');

        foreach ([[null, UserStatus::Blocked], [[UserRole::Admin], null]] as [$role, $status]) {
            $e = $this->catch(fn () => $this->service->update($this->user(1), $this->user(2), $role, $status));
            $this->assertSame('last_superadmin', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_superadmin_can_be_blocked_when_another_is_active(): void
    {
        $target = $this->user(2);
        $this->repo->method('rolesOf')->willReturn([UserRole::Superadmin]);
        $this->repo->method('countActiveSuperadmins')->willReturn(2);
        $this->repo->expects($this->once())->method('setStatus')->with($target, UserStatus::Blocked);
        $this->repo->expects($this->once())->method('lockAndRefresh')->with($target);
        $this->repo->expects($this->once())->method('revokeCredentials')->with($target);

        $this->service->update($this->user(1), $target, null, UserStatus::Blocked);
    }

    public function test_role_and_status_are_applied(): void
    {
        $target = $this->user(2);
        $this->repo->method('rolesOf')->willReturn([UserRole::Viewer]);
        $this->repo->expects($this->once())->method('setRoles')->with($target, [UserRole::Admin]);
        $this->repo->expects($this->once())->method('setStatus')->with($target, UserStatus::Active);
        $this->repo->expects($this->never())->method('revokeCredentials');

        $this->assertSame($target, $this->service->update($this->user(1), $target, [UserRole::Admin], UserStatus::Active));
    }

    public function test_repeated_explicit_block_still_revokes_credentials(): void
    {
        $target = $this->user(2)->forceFill(['status' => UserStatus::Blocked]);
        $this->repo->method('rolesOf')->willReturn([UserRole::Viewer]);
        $this->repo->expects($this->once())->method('revokeCredentials')->with($target);

        $this->service->update($this->user(1), $target, null, UserStatus::Blocked);
    }

    public function test_revocation_failure_propagates_out_of_the_transaction(): void
    {
        $target = $this->user(2);
        $this->repo->method('rolesOf')->willReturn([UserRole::Viewer]);
        $this->repo->expects($this->once())->method('setStatus')->with($target, UserStatus::Blocked);
        $this->repo->method('revokeCredentials')->willThrowException(new \RuntimeException('synthetic-revocation-failure'));
        $this->expectException(\RuntimeException::class);

        $this->service->update($this->user(1), $target, null, UserStatus::Blocked);
    }

    public function test_several_roles_are_applied_and_audited_with_names(): void
    {
        $target = $this->user(2);
        $audit = $this->createMock(AuditLogger::class);
        $service = new UserAdminService($this->repo, new NullLogger, $audit, $this->gate);
        $this->repo->method('rolesOf')->willReturn([UserRole::Recruiter]);
        $this->repo->expects($this->once())->method('setRoles')->with($target, [UserRole::HrManager, UserRole::Recruiter]);
        $audit->expects($this->once())->method('record')
            ->with('user', 2, $this->anything(), ['role' => ['from' => 'recruiter', 'to' => 'hr_manager, recruiter']], null, 1);

        $service->update($this->user(1), $target, [UserRole::Recruiter, UserRole::HrManager], null);
    }

    public function test_superadmin_gives_and_takes_superadmin_with_audit(): void
    {
        $target = $this->user(2);
        $audit = $this->createMock(AuditLogger::class);
        $service = new UserAdminService($this->repo, new NullLogger, $audit, $this->gate);
        $this->repo->method('rolesOf')->willReturnOnConsecutiveCalls([UserRole::Admin], [UserRole::Superadmin, UserRole::Admin]);
        $this->repo->method('countActiveSuperadmins')->willReturn(2);
        $this->repo->expects($this->exactly(2))->method('setRoles');
        $audit->expects($this->exactly(2))->method('record');

        $service->update($this->user(1), $target, [UserRole::Admin, UserRole::Superadmin], null);
        $service->update($this->user(1), $target, [UserRole::Admin], null);
    }

    public function test_only_an_actor_acting_as_superadmin_touches_the_superadmin_role(): void
    {
        $admin = $this->user(1);
        $this->actorIsSuperadmin = false;
        $this->repo->expects($this->never())->method('setRoles');
        $this->repo->expects($this->never())->method('invite');
        $this->repo->method('rolesOf')->willReturnOnConsecutiveCalls([UserRole::Admin], [UserRole::Superadmin]);

        foreach ([[UserRole::Superadmin, UserRole::Admin], [UserRole::Admin]] as $roles) {
            $e = $this->catch(fn () => $this->service->update($admin, $this->user(2), $roles, null));
            $this->assertSame('superadmin_forbidden', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
        $this->assertSame('superadmin_forbidden', $this->catch(fn () => $this->service->invite($admin, 'a@example.com', 'A', UserRole::Superadmin))->errorCode);
    }

    public function test_last_superadmin_keeps_superadmin_when_roles_are_added(): void
    {
        $target = $this->user(2);
        $this->repo->method('rolesOf')->willReturn([UserRole::Superadmin]);
        $this->repo->method('countActiveSuperadmins')->willReturn(1);
        $this->repo->expects($this->once())->method('setRoles')->with($target, [UserRole::Superadmin, UserRole::Recruiter]);

        $this->service->update($this->user(1), $target, [UserRole::Recruiter, UserRole::Superadmin], null);
        $this->assertSame('last_superadmin', $this->catch(fn () => $this->service->update($this->user(1), $target, [UserRole::Recruiter], null))->errorCode);
    }

    public function test_branches_are_synced_only_when_sent(): void
    {
        $target = $this->user(2);
        $this->repo->method('rolesOf')->willReturn([UserRole::Recruiter]);
        $this->repo->expects($this->once())->method('syncBranches')->with($target, [3, 5]);
        $this->repo->expects($this->never())->method('setRoles');

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
