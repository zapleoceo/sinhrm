<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class OpsJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret']);
    }

    public function test_runs_every_registered_job_and_reports_a_failing_one(): void
    {
        $this->app->bind('test.job.ok', fn () => new class implements ScheduledJob
        {
            public function name(): string
            {
                return 'ok_job';
            }

            public function run(Carbon $now): array
            {
                return ['done' => 3];
            }
        });
        $this->app->bind('test.job.broken', fn () => new class implements ScheduledJob
        {
            public function name(): string
            {
                return 'broken_job';
            }

            public function run(Carbon $now): array
            {
                throw new RuntimeException('boom');
            }
        });
        $this->app->tag(['test.job.ok', 'test.job.broken'], ScheduledJob::class);

        $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('jobs.ok_job', ['ok' => true, 'done' => 3])
            ->assertJsonPath('jobs.broken_job.ok', false)
            ->assertJsonPath('jobs.broken_job.error', RuntimeException::class)
            ->assertJsonPath('jobs.followups.ok', true);
    }

    public function test_requires_the_secret(): void
    {
        $this->postJson('/api/ops/jobs/run')->assertUnauthorized();
    }
}
