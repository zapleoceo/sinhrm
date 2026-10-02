<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Models\User;
use App\Modules\Scripts\Models\Task;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** closeByRulePrefix (Core Like::startsWith with ESCAPE '!'): %, _ and ! in the prefix are literal. Synthetic data. */
final class TaskRulePrefixTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prefix_wildcards_and_escape_char_match_only_literally(): void
    {
        Carbon::setTestNow('2026-10-02 10:00:00');
        $user = User::factory()->create();
        $keys = ['hr:1_2:a', 'hr:1x2:a', 'hr:5%:a', 'hr:50:a', 'p!_:a', 'p!x:a', 'other:1_2:a'];
        foreach ($keys as $key) {
            $this->task($user, $key);
        }
        $alreadyDone = $this->task($user, 'hr:1_2:b', Carbon::parse('2026-09-01 08:00:00'));
        $service = $this->app->make(TaskService::class);

        $this->assertSame(1, $service->closeByRulePrefix('hr:1_2:'));
        $this->assertSame(1, $service->closeByRulePrefix('hr:5%:'));
        $this->assertSame(1, $service->closeByRulePrefix('p!_'));

        $closedNow = Task::query()->whereNotNull('done_at')->whereKeyNot($alreadyDone->id)->pluck('rule_key')->all();
        $this->assertEqualsCanonicalizing(['hr:1_2:a', 'hr:5%:a', 'p!_:a'], $closedNow);
        $this->assertEqualsCanonicalizing(['hr:1x2:a', 'hr:50:a', 'p!x:a', 'other:1_2:a'], Task::query()->whereNull('done_at')->pluck('rule_key')->all());
        $this->assertSame('2026-09-01 08:00:00', $alreadyDone->refresh()->done_at?->format('Y-m-d H:i:s'), 'a done task keeps its time');
    }

    private function task(User $user, string $ruleKey, ?Carbon $doneAt = null): Task
    {
        return Task::query()->create([
            'assignee_id' => $user->id,
            'type' => 'manual',
            'title' => 'Sample task',
            'due_at' => Carbon::parse('2026-10-03 09:00:00'),
            'done_at' => $doneAt,
            'rule_key' => $ruleKey,
        ]);
    }
}
