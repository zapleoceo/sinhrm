<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Services\StalenessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * An application; with candidate (board, stale list), with vacancy/stage (candidate card), and — when stage changes are
 * loaded — the ROUTE: every stage passed with time spent on it.
 *
 * @mixin Application
 */
final class ApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'candidate_id' => $this->candidate_id,
            'vacancy_id' => $this->vacancy_id,
            'stage_id' => $this->stage_id,
            'status' => $this->status->value,
            'reject_reason_id' => $this->reject_reason_id,
            'reject_reason' => $this->relationLoaded('rejectReason') ? $this->rejectReason?->name : null,
            'rejected_note' => $this->rejected_note,
            'stage_entered_at' => $this->stage_entered_at?->toIso8601String(),
            'last_touch_at' => $this->last_touch_at?->toIso8601String(),
            'is_stale' => StalenessService::isStale($this->resource),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
        if ($this->relationLoaded('candidate')) {
            $c = $this->candidate;
            $data['candidate'] = [
                'id' => $c->id,
                'full_name' => $c->full_name,
                'phone' => $c->phone,
                'email' => $c->email,
                'telegram_username' => $c->telegram_username,
                'source' => $c->source->value,
            ];
        }
        if ($this->relationLoaded('vacancy')) {
            $data['vacancy'] = ['id' => $this->vacancy->id, 'title' => $this->vacancy->title, 'status' => $this->vacancy->status->value];
            if ($this->vacancy->relationLoaded('pipeline')) {
                $data['stages'] = StageResource::collection($this->vacancy->pipeline->stages);
            }
        }
        if ($this->relationLoaded('stage')) {
            $data['stage'] = new StageResource($this->stage);
        }
        if ($this->relationLoaded('stageChanges')) {
            $data['route'] = $this->route();
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function route(): array
    {
        $changes = $this->stageChanges->values();
        $now = Carbon::now();
        $route = [];
        foreach ($changes as $i => $change) {
            /** @var StageChange $change */
            $next = $changes->get($i + 1);
            $left = $next instanceof StageChange ? $next->at : null;
            $route[] = [
                'stage_change_id' => $change->id,
                'stage_id' => $change->to_stage_id,
                'stage_name' => $change->toStage->name,
                'kind' => $change->toStage->kind->value,
                'entered_at' => $change->at->toIso8601String(),
                'left_at' => $left?->toIso8601String(),
                'duration_sec' => (int) max(0, $change->at->diffInSeconds($left ?? $now)),
                'by' => $change->byUser === null ? null : ['id' => $change->byUser->id, 'name' => $change->byUser->name],
                'reason' => $change->reason,
            ];
        }

        return $route;
    }
}
