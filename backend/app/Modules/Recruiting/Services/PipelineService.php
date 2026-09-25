<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/** Pipelines (read; create by admins) and the reject reasons dictionary. */
final readonly class PipelineService
{
    public function __construct(private PipelineRepository $pipelines, private LoggerInterface $log) {}

    /** @return Collection<int, Pipeline> */
    public function all(): Collection
    {
        return $this->pipelines->all();
    }

    /** @param  list<array{name: string, kind: string, is_terminal: bool}>  $stages */
    public function create(User $actor, string $name, array $stages): Pipeline
    {
        $pipeline = $this->pipelines->create($name, $stages);
        $this->log->info('recruiting.pipeline_created', ['id' => $pipeline->id, 'by' => $actor->id]);

        return $pipeline;
    }

    /** @return Collection<int, RejectReason> */
    public function rejectReasons(bool $onlyActive): Collection
    {
        return $this->pipelines->rejectReasons($onlyActive);
    }

    /** @throws ModelNotFoundException<RejectReason> */
    public function findRejectReason(int $id): RejectReason
    {
        return $this->pipelines->findRejectReason($id) ?? throw (new ModelNotFoundException)->setModel(RejectReason::class, [$id]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveRejectReason(User $actor, ?RejectReason $reason, array $attributes): RejectReason
    {
        $saved = $this->pipelines->saveRejectReason($reason, $attributes);
        $this->log->info('recruiting.reject_reason_saved', ['id' => $saved->id, 'by' => $actor->id]);

        return $saved;
    }
}
