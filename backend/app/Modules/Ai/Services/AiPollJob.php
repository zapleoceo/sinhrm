<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/**
 * "ai.poll" (cron every ~30 min via POST /api/ops/jobs/run): one poll of each deferred request; done → the purpose
 * handler applies the result (exactly once, AiService guards pending → done). Requests pending longer than
 * EXPIRE_HOURS fail with ai_timeout. No sleeping here: the whole ops run shares one serverless request.
 */
final readonly class AiPollJob implements ScheduledJob
{
    public const int BATCH = 25;

    public const int EXPIRE_HOURS = 24;

    /** Stop taking new requests after this many seconds (other jobs run in the same request). */
    public const int TIME_BUDGET_SECONDS = 20;

    public function __construct(private AiService $ai, private AiRequestRepository $requests) {}

    public function name(): string
    {
        return 'ai.poll';
    }

    public function run(Carbon $now): array
    {
        $counts = ['polled' => 0, 'done' => 0, 'failed' => 0, 'pending' => 0, 'expired' => 0];
        $started = hrtime(true);
        foreach ($this->requests->pending(self::BATCH) as $request) {
            if ((hrtime(true) - $started) / 1e9 > self::TIME_BUDGET_SECONDS) {
                break;
            }
            if ($request->created_at !== null && $request->created_at->lt($now->copy()->subHours(self::EXPIRE_HOURS))) {
                $this->ai->expire($request);
                $counts['expired']++;

                continue;
            }
            $counts['polled']++;
            $outcome = $this->ai->refresh($request);
            $counts[$outcome->isDone() ? 'done' : ($outcome->isDeferred() ? 'pending' : 'failed')]++;
        }

        return $counts;
    }
}
