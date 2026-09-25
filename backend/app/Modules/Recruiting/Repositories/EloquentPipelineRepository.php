<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Models\Pipeline;
use App\Modules\Recruiting\Models\PipelineStage;
use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentPipelineRepository implements PipelineRepository
{
    public function all(): Collection
    {
        return Pipeline::query()->with('stages')->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function find(int $id): ?Pipeline
    {
        return Pipeline::query()->with('stages')->find($id);
    }

    public function defaultPipeline(): ?Pipeline
    {
        return Pipeline::query()->with('stages')->where('is_default', true)->orderBy('id')->first();
    }

    public function create(string $name, array $stages): Pipeline
    {
        return DB::transaction(function () use ($name, $stages): Pipeline {
            $pipeline = Pipeline::query()->create(['name' => $name, 'is_default' => false]);
            foreach ($stages as $i => $stage) {
                $pipeline->stages()->create([
                    'name' => $stage['name'],
                    'kind' => $stage['kind'],
                    'is_terminal' => $stage['is_terminal'],
                    'position' => $i + 1,
                ]);
            }

            return $pipeline->load('stages');
        });
    }

    public function findStage(int $id): ?PipelineStage
    {
        return PipelineStage::query()->find($id);
    }

    public function firstStage(int $pipelineId): ?PipelineStage
    {
        return PipelineStage::query()->where('pipeline_id', $pipelineId)->orderBy('position')->first();
    }

    public function rejectReasons(bool $onlyActive): Collection
    {
        return RejectReason::query()
            ->when($onlyActive, fn ($q) => $q->where('active', true))
            ->orderBy('id')
            ->get();
    }

    public function findRejectReason(int $id): ?RejectReason
    {
        return RejectReason::query()->find($id);
    }

    public function saveRejectReason(?RejectReason $reason, array $attributes): RejectReason
    {
        $reason ??= new RejectReason;
        $reason->fill($attributes)->save();

        return $reason;
    }
}
