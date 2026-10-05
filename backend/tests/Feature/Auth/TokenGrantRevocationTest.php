<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\DTO\TokenKind;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Services\PersonalTokens;
use App\Modules\Users\Services\UserAdminService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Real DI path: an active request snapshots its actor, then block commits before its token grant resumes. */
final class TokenGrantRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_after_block_rechecks_persisted_status_and_cannot_leave_a_token_for_unblock(): void
    {
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $staleActor = User::query()->findOrFail($target->id);
        $tokens = app(PersonalTokens::class);
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Blocked);
        $this->assertTrue($staleActor->isActive());

        foreach (['extension', 'mcp'] as $name) {
            try {
                $tokens->issue($staleActor, new TokenKind($name, 'synthetic-ability', 1, 'synthetic_token'));
                $this->fail('A stale authenticated actor must not obtain a token after block');
            } catch (AuthorizationException $error) {
                $this->assertSame('blocked', $error->getMessage());
            }
        }
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Active);
        $this->assertSame(0, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
    }

    public function test_grant_before_block_is_revoked_and_a_fresh_grant_after_unblock_is_allowed(): void
    {
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $kind = new TokenKind('extension', 'synthetic-ability', 1, 'synthetic_token');
        $tokens = app(PersonalTokens::class);
        $old = $tokens->issue($target, $kind)->plainText;
        $this->assertNotNull($old);
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Blocked);
        $this->assertSame(0, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Active);
        $new = $tokens->issue($target->refresh(), $kind)->plainText;
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertSame(1, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
    }
}
