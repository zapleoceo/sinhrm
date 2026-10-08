<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Repositories\EloquentTouchpointRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/** PROD-50: the conversation lookup by (channel, meta.thread) has a MySQL 8.4 functional index the optimizer can use. */
final class TouchpointThreadIndexTest extends TestCase
{
    use MysqlSchemaAssertions, RefreshDatabase;

    private const string INDEX = 'touchpoints_channel_thread_index';

    public function test_functional_index_covers_channel_and_the_thread_key(): void
    {
        $parts = $this->indexParts('touchpoints', self::INDEX);

        $this->assertCount(2, $parts);
        $this->assertSame('channel', $parts[0]);
        $this->assertStringContainsString('json_extract', $parts[1]);
        $this->assertStringContainsString('utf8mb4_bin', $parts[1]);
    }

    public function test_the_repository_thread_query_can_use_the_index_and_stays_case_sensitive(): void
    {
        $candidate = Candidate::factory()->create();
        $other = Candidate::factory()->create();
        $this->touch('T-1', $candidate->id);
        $this->touch('t-1', $other->id);
        $repository = new EloquentTouchpointRepository;

        DB::enableQueryLog();
        $found = $repository->candidateIdByThread(Channel::Telegram, 'T-1');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($candidate->id, $found);
        $this->assertSame($other->id, $repository->candidateIdByThread(Channel::Telegram, 't-1'));
        $this->assertCount(1, $log);
        $plan = DB::selectOne('explain '.$log[0]['query'], $log[0]['bindings']);
        $this->assertContains(self::INDEX, explode(',', (string) $plan->possible_keys), 'optimizer does not match the functional index');
    }

    public function test_a_thread_longer_than_the_indexed_255_chars_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->touch(str_repeat('x', 256), null);
    }

    private function touch(string $thread, ?int $candidateId): void
    {
        Touchpoint::query()->create([
            'candidate_id' => $candidateId, 'channel' => Channel::Telegram, 'occurred_at' => now(), 'meta' => ['thread' => $thread],
        ]);
    }
}
