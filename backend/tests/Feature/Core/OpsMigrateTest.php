<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\MigrationRunner;
use Illuminate\Cache\RateLimiter;
use RuntimeException;
use Tests\TestCase;

final class OpsMigrateTest extends TestCase
{
    private FakeMigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret']);
        $this->runner = new FakeMigrationRunner;
        $this->app->instance(MigrationRunner::class, $this->runner);
    }

    public function test_hidden_when_secret_not_configured(): void
    {
        config(['ops.secret' => null]);

        $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => 'anything'])->assertNotFound();
        $this->assertSame([], $this->runner->calls);
    }

    public function test_rejects_missing_or_wrong_secret(): void
    {
        $this->postJson('/api/ops/migrate')->assertUnauthorized();
        $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => 'wrong'])->assertUnauthorized();
        $this->assertSame([], $this->runner->calls);
    }

    public function test_runs_migrations_with_valid_secret(): void
    {
        $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fresh', false);

        $this->assertSame(['migrate'], $this->runner->calls);
    }

    public function test_fresh_rebuild_outside_production(): void
    {
        $this->postJson('/api/ops/migrate?fresh=1', [], ['X-Ops-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('fresh', true);

        $this->assertSame(['rebuild'], $this->runner->calls);
    }

    public function test_guessing_the_secret_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => "guess-{$i}"])->assertUnauthorized();
        }

        $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => 'guess-11'])->assertTooManyRequests();
    }

    public function test_valid_secret_never_touches_the_rate_limiter(): void
    {
        // Simulates an empty database: any cache access explodes.
        $this->app->instance(RateLimiter::class, new BrokenRateLimiter);

        $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => 'test-secret'])->assertOk();
        $this->assertSame(['migrate'], $this->runner->calls);
    }

    public function test_wrong_secret_still_rejected_when_limiter_is_unavailable(): void
    {
        $this->app->instance(RateLimiter::class, new BrokenRateLimiter);

        $this->postJson('/api/ops/migrate', [], ['X-Ops-Secret' => 'wrong'])->assertUnauthorized();
        $this->assertSame([], $this->runner->calls);
    }

    public function test_fresh_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('/api/ops/migrate?fresh=1', [], ['X-Ops-Secret' => 'test-secret'])->assertForbidden();
        $this->assertSame([], $this->runner->calls);
    }
}

final class BrokenRateLimiter extends RateLimiter
{
    public function __construct() {}

    public function tooManyAttempts($key, $maxAttempts)
    {
        throw new RuntimeException('cache table missing');
    }

    public function hit($key, $decaySeconds = 60)
    {
        throw new RuntimeException('cache table missing');
    }
}

final class FakeMigrationRunner implements MigrationRunner
{
    /** @var list<string> */
    public array $calls = [];

    public function migrate(): string
    {
        $this->calls[] = 'migrate';

        return 'Nothing to migrate.';
    }

    public function rebuildWithSeed(): string
    {
        $this->calls[] = 'rebuild';

        return 'Seeded.';
    }
}
