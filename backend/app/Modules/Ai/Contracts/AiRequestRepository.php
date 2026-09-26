<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTO\AiResult;
use App\Modules\Ai\DTO\AiUsage;
use App\Modules\Ai\Models\AiRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

interface AiRequestRepository
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): AiRequest;

    public function find(int $id): ?AiRequest;

    public function setJob(AiRequest $request, string $jobId, int $attempts): void;

    /** Adds tokens/cost of one provider answer (every attempt is paid for, valid or not). */
    public function addUsage(AiRequest $request, AiResult $result): void;

    /** pending → done; false when another process already finished the request (then do not apply the result). */
    public function markDone(AiRequest $request): bool;

    /** pending → failed with an error code; false when it was already finished. */
    public function markFailed(AiRequest $request, string $error): bool;

    /** @return Collection<int, AiRequest> pending requests that have a provider job, oldest first */
    public function pending(int $limit): Collection;

    public function usageSince(Carbon $since): AiUsage;
}
