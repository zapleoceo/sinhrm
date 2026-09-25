<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/ops/jobs/run — runs every registered ScheduledJob once (cron workflow, X-Ops-Secret).
 * One failing job does not stop the others; the answer is ok=false then (the cron step fails visibly).
 */
final class OpsJobsController
{
    /** @param  iterable<ScheduledJob>  $jobs */
    public function __construct(private readonly iterable $jobs) {}

    public function __invoke(): JsonResponse
    {
        $now = Carbon::now();
        $ok = true;
        $results = [];
        foreach ($this->jobs as $job) {
            try {
                $results[$job->name()] = ['ok' => true] + $job->run($now);
            } catch (Throwable $e) {
                $ok = false;
                report($e);
                $results[$job->name()] = ['ok' => false, 'error' => $e::class];
            }
        }
        Log::info('ops.jobs_run', ['ok' => $ok, 'jobs' => $results]);

        return new JsonResponse(['ok' => $ok, 'jobs' => (object) $results]);
    }
}
