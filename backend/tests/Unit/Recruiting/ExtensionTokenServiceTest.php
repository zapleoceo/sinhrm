<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Models\User;
use App\Modules\Recruiting\Contracts\ExtensionTokenRepository;
use App\Modules\Recruiting\Services\ExtensionTokenService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Log\NullLogger;
use Tests\TestCase;

/** Tests\TestCase only for the app container (model date casts); no database. */
final class ExtensionTokenServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_issue_revokes_previous_and_creates_a_clipper_token_for_90_days(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $user = new User;
        $token = new PersonalAccessToken;
        $token->created_at = Carbon::now();
        $token->expires_at = Carbon::now()->addDays(90);

        $repo = $this->createMock(ExtensionTokenRepository::class);
        $repo->expects($this->once())->method('deleteAll')->with($user, 'extension')->willReturn(1);
        $repo->expects($this->once())->method('create')
            ->with($user, 'extension', ['clipper'], $this->callback(static fn (Carbon $at): bool => $at->eq(Carbon::parse('2026-12-30 10:00:00'))))
            ->willReturn(new NewAccessToken($token, '5|synthetic'));

        $status = (new ExtensionTokenService($repo, new NullLogger))->issue($user)->toArray();

        $this->assertTrue($status['active']);
        $this->assertSame('5|synthetic', $status['token']);
    }

    public function test_status_of_an_expired_or_missing_token_is_inactive_without_plaintext(): void
    {
        $expired = new PersonalAccessToken;
        $expired->created_at = Carbon::now()->subDays(100);
        $expired->expires_at = Carbon::now()->subDays(10);
        $repo = $this->createStub(ExtensionTokenRepository::class);
        $repo->method('find')->willReturnOnConsecutiveCalls($expired, null);
        $service = new ExtensionTokenService($repo, new NullLogger);

        $first = $service->status(new User)->toArray();
        $this->assertFalse($first['active']);
        $this->assertArrayNotHasKey('token', $first);
        $this->assertFalse($service->status(new User)->active);
    }
}
