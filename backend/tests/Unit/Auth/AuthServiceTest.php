<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Enums\AppLocale;
use App\Modules\Auth\Enums\LoginDenial;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Exceptions\LoginDenied;
use App\Modules\Auth\Services\AuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private UserRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(UserRepository::class);
    }

    public function test_profile_normalizes_email(): void
    {
        $this->assertSame('a.b@example.com', $this->profile('  A.B@Example.COM ')->email);
    }

    public function test_superadmin_email_bootstraps_superadmin(): void
    {
        $created = $this->user(UserStatus::Active);
        $this->repo->method('findByGoogleId')->willReturn(null);
        $this->repo->method('findByEmail')->willReturn(null);
        $this->repo->expects($this->once())->method('createSuperadmin')->willReturn($created);

        $this->assertSame($created, $this->service(' Owner@Example.com ')->handleGoogle($this->profile('owner@example.com')));
    }

    public function test_unknown_email_is_not_invited(): void
    {
        $this->repo->method('findByGoogleId')->willReturn(null);
        $this->repo->method('findByEmail')->willReturn(null);
        $this->repo->expects($this->never())->method('createSuperadmin');

        $this->assertDenied(LoginDenial::NotInvited, fn () => $this->service('owner@example.com')->handleGoogle($this->profile('x@example.com')));
    }

    public function test_empty_superadmin_config_never_matches(): void
    {
        $this->repo->method('findByGoogleId')->willReturn(null);
        $this->repo->method('findByEmail')->willReturn(null);

        $this->assertDenied(LoginDenial::NotInvited, fn () => $this->service(null)->handleGoogle($this->profile('')));
    }

    public function test_blocked_user_is_denied(): void
    {
        $this->repo->method('findByGoogleId')->willReturn($this->user(UserStatus::Blocked));
        $this->repo->expects($this->never())->method('recordGoogleLogin');

        $this->assertDenied(LoginDenial::Blocked, fn () => $this->service(null)->handleGoogle($this->profile('b@example.com')));
    }

    public function test_unverified_email_is_denied_before_lookup(): void
    {
        $this->repo->expects($this->never())->method('findByGoogleId');

        $this->assertDenied(
            LoginDenial::EmailUnverified,
            fn () => $this->service('owner@example.com')->handleGoogle($this->profile('owner@example.com', false)),
        );
    }

    public function test_existing_user_found_by_email_is_linked(): void
    {
        $user = $this->user(UserStatus::Active);
        $this->repo->method('findByGoogleId')->willReturn(null);
        $this->repo->method('findByEmail')->with('u@example.com')->willReturn($user);
        $this->repo->expects($this->once())->method('recordGoogleLogin')->willReturn($user);

        $this->assertSame($user, $this->service(null)->handleGoogle($this->profile('U@example.com')));
    }

    public function test_change_locale_delegates_to_repository(): void
    {
        $user = $this->user(UserStatus::Active);
        $this->repo->expects($this->once())->method('updateLocale')->with($user, AppLocale::En)->willReturn($user);

        $this->assertSame($user, $this->service(null)->changeLocale($user, AppLocale::En));
    }

    private function service(?string $superadmin): AuthService
    {
        return new AuthService($this->repo, $superadmin);
    }

    private function profile(string $email, bool $verified = true): GoogleProfile
    {
        return new GoogleProfile('g-1', $email, 'Name', null, $verified);
    }

    private function user(UserStatus $status): User
    {
        return (new User)->forceFill(['id' => 1, 'status' => $status]);
    }

    private function assertDenied(LoginDenial $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('LoginDenied expected');
        } catch (LoginDenied $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
