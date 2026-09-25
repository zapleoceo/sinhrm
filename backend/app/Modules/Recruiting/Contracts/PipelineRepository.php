<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Database\Eloquent\Collection;

/** Pipeline configuration: pipelines with ordered stages, and the rejection reasons dictionary. */
interface PipelineRepository
{
    /** @return Collection<int, Pipeline> with stages */
    public function all(): Collection;

    public function find(int $id): ?Pipeline;

    public function defaultPipeline(): ?Pipeline;

    /** @param  list<array{name: string, kind: string, is_terminal: bool}>  $stages  in order */
    public function create(string $name, array $stages): Pipeline;

    public function findStage(int $id): ?PipelineStage;

    public function firstStage(int $pipelineId): ?PipelineStage;

    /** @return Collection<int, RejectReason> */
    public function rejectReasons(bool $onlyActive): Collection;

    public function findRejectReason(int $id): ?RejectReason;

    /** @param  array<string, mixed>  $attributes */
    public function saveRejectReason(?RejectReason $reason, array $attributes): RejectReason;
}
