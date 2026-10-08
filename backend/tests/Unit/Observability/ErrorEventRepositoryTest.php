<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Modules\Observability\Contracts\ErrorEventRepository;
use App\Modules\Observability\Models\ErrorEvent;
use App\Modules\Observability\Repositories\EloquentErrorEventRepository;
use App\Modules\Observability\Services\ErrorLogPruneJob;
use App\Modules\Observability\Services\ErrorRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/** The error log goes through ErrorEventRepository: the binding, and that recorder/job delegate to it (no DB). */
final class ErrorEventRepositoryTest extends TestCase
{
    public function test_the_contract_is_bound_to_the_eloquent_repository(): void
    {
        $this->assertInstanceOf(EloquentErrorEventRepository::class, $this->app->make(ErrorEventRepository::class));
    }

    public function test_the_recorder_upserts_a_cleaned_group_row(): void
    {
        $fake = $this->fake();
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->app->make(ErrorRecorder::class)->recordClient('TypeError', 'mail me at a@b.co', 'main.js:1:1', '/people', 7);

        $this->assertCount(1, $fake->rows);
        $row = $fake->rows[0];
        $this->assertSame(hash('sha256', 'web|TypeError|main.js:1:1|'), $row['fingerprint']);
        $this->assertSame('mail me at [email]', $row['message']);
        $this->assertSame(7, $row['last_user_id']);
        $this->assertSame(1, $row['count']);
        $this->assertNull($row['resolved_at']);
    }

    public function test_a_repository_failure_never_escapes_the_recorder(): void
    {
        $fake = $this->fake();
        $fake->fail = true;

        $this->app->make(ErrorRecorder::class)->recordException(new RuntimeException('boom'));

        $this->assertSame([], $fake->rows);
    }

    public function test_the_prune_job_deletes_groups_older_than_the_retention(): void
    {
        $fake = $this->fake();

        $result = $this->app->make(ErrorLogPruneJob::class)->run(Carbon::parse('2026-10-31 12:00:00'));

        $this->assertSame(['errors_pruned' => 3], $result);
        $this->assertSame('2026-10-01 12:00:00', $fake->prunedBefore?->toDateTimeString());
    }

    private function fake(): FakeErrorEventRepository
    {
        $fake = new FakeErrorEventRepository;
        $this->app->instance(ErrorEventRepository::class, $fake);
        $this->app->forgetInstance(ErrorRecorder::class);

        return $fake;
    }
}

final class FakeErrorEventRepository implements ErrorEventRepository
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $fail = false;

    public ?Carbon $prunedBefore = null;

    public function list(?bool $resolved, int $limit): Collection
    {
        return new Collection;
    }

    public function findOrFail(int $id): ErrorEvent
    {
        throw new RuntimeException('not used');
    }

    public function save(ErrorEvent $event): void {}

    public function upsertGroup(array $row): void
    {
        if ($this->fail) {
            throw new RuntimeException('db down');
        }
        $this->rows[] = $row;
    }

    public function pruneNotSeenSince(Carbon $before): int
    {
        $this->prunedBefore = $before;

        return 3;
    }
}
