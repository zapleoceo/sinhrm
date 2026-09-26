<?php

declare(strict_types=1);

namespace App\Modules\Ai\Repositories;

use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\DTO\AiUsage;
use App\Modules\Ai\Enums\AiRequestStatus;
use App\Modules\Ai\Models\AiRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentAiRequestRepository implements AiRequestRepository
{
    public function create(array $attributes): AiRequest
    {
        return AiRequest::query()->create($attributes);
    }

    public function find(int $id): ?AiRequest
    {
        return AiRequest::query()->find($id);
    }

    public function setJob(AiRequest $request, string $jobId, int $attempts): void
    {
        $request->job_id = $jobId;
        $request->attempts = $attempts;
        $request->save();
    }

    public function addUsage(AiRequest $request, AiResult $result): void
    {
        $request->tokens_in += $result->tokensIn;
        $request->tokens_out += $result->tokensOut;
        $request->tokens_cached += $result->tokensCached;
        $request->cost_usd = round($request->cost_usd + $result->costUsd, 6);
        $request->model = $result->model === null ? $request->model : mb_substr($result->model, 0, 128);
        $request->save();
    }

    public function markDone(AiRequest $request): bool
    {
        return $this->finish($request, AiRequestStatus::Done, null);
    }

    public function markFailed(AiRequest $request, string $error): bool
    {
        return $this->finish($request, AiRequestStatus::Failed, mb_substr($error, 0, 64));
    }

    public function pending(int $limit): Collection
    {
        return AiRequest::query()
            ->where('status', AiRequestStatus::Pending->value)
            ->whereNotNull('job_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function usageSince(Carbon $since): AiUsage
    {
        $row = AiRequest::query()
            ->where('created_at', '>=', $since)
            ->select([
                DB::raw('COALESCE(SUM(attempts), 0) AS requests'),
                DB::raw('COALESCE(SUM(cost_usd), 0) AS cost'),
                DB::raw('COALESCE(SUM(tokens_in), 0) AS tin'),
                DB::raw('COALESCE(SUM(tokens_out), 0) AS tout'),
                DB::raw('COALESCE(SUM(tokens_cached), 0) AS tcached'),
            ])
            ->toBase()
            ->first();

        return new AiUsage(
            (int) ($row->requests ?? 0),
            round((float) ($row->cost ?? 0), 6),
            (int) ($row->tin ?? 0),
            (int) ($row->tout ?? 0),
            (int) ($row->tcached ?? 0),
        );
    }

    public function statsSince(Carbon $since, int $buckets, int $bucketSeconds): array
    {
        $stats = [];
        $latency = [];
        $rows = AiRequest::query()
            ->where('created_at', '>=', $since)
            ->select(['purpose', 'status', 'error', 'tokens_in', 'tokens_out', 'tokens_cached', 'cost_usd', 'created_at', 'completed_at'])
            ->toBase()
            ->cursor();
        foreach ($rows as $row) {
            $purpose = (string) $row->purpose;
            $s = $stats[$purpose] ?? [
                'requests' => 0, 'done' => 0, 'failed' => 0, 'pending' => 0, 'errors' => [], 'avg_latency_s' => null,
                'tokens_in' => 0, 'tokens_out' => 0, 'tokens_cached' => 0, 'cost_usd' => 0.0, 'series' => array_fill(0, $buckets, 0),
            ];
            $s['requests']++;
            $status = (string) $row->status;
            if ($status === AiRequestStatus::Done->value) {
                $s['done']++;
            } elseif ($status === AiRequestStatus::Failed->value) {
                $s['failed']++;
                $code = (string) ($row->error ?? 'unknown');
                $s['errors'][$code] = ($s['errors'][$code] ?? 0) + 1;
            } else {
                $s['pending']++;
            }
            $s['tokens_in'] += (int) $row->tokens_in;
            $s['tokens_out'] += (int) $row->tokens_out;
            $s['tokens_cached'] += (int) $row->tokens_cached;
            $s['cost_usd'] += (float) $row->cost_usd;
            $created = Carbon::parse((string) $row->created_at, 'UTC');
            $bucket = (int) floor(($created->getTimestamp() - $since->getTimestamp()) / max(1, $bucketSeconds));
            $s['series'][max(0, min($buckets - 1, $bucket))]++;
            if ($row->completed_at !== null && $status === AiRequestStatus::Done->value) {
                $latency[$purpose][] = max(0, Carbon::parse((string) $row->completed_at, 'UTC')->getTimestamp() - $created->getTimestamp());
            }
            $stats[$purpose] = $s;
        }
        foreach ($stats as $purpose => $s) {
            $values = $latency[$purpose] ?? [];
            $stats[$purpose]['avg_latency_s'] = $values === [] ? null : round(array_sum($values) / count($values), 1);
            $stats[$purpose]['cost_usd'] = round($s['cost_usd'], 6);
            arsort($stats[$purpose]['errors']);
        }

        return $stats;
    }

    /** Conditional UPDATE: only a pending row moves, so two finishers never both apply a result. */
    private function finish(AiRequest $request, AiRequestStatus $status, ?string $error): bool
    {
        $now = Carbon::now();
        $affected = AiRequest::query()
            ->whereKey($request->id)
            ->where('status', AiRequestStatus::Pending->value)
            ->update(['status' => $status->value, 'error' => $error, 'completed_at' => $now, 'updated_at' => $now]);
        if ($affected === 1) {
            $request->status = $status;
            $request->error = $error;
            $request->completed_at = $now;
            $request->syncOriginal();
        }

        return $affected === 1;
    }
}
