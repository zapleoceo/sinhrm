<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\MigrationRunner;
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

    public function test_fresh_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('/api/ops/migrate?fresh=1', [], ['X-Ops-Secret' => 'test-secret'])->assertForbidden();
        $this->assertSame([], $this->runner->calls);
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
