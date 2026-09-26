<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\Objective;
use App\Modules\Perform\Models\ObjectiveCheckin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An objective; check-in history only on the single-objective answer.
 *
 * @mixin Objective
 */
final class ObjectiveResource extends JsonResource
{
    private bool $canEdit = false;

    private bool $withCheckins = false;

    public static function for(Objective $objective, bool $canEdit, bool $withCheckins = false): self
    {
        $resource = new self($objective);
        $resource->canEdit = $canEdit;
        $resource->withCheckins = $withCheckins;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'owner' => $this->owner === null ? null : ['id' => $this->owner->id, 'full_name' => $this->owner->full_name],
            'department_id' => $this->department_id,
            'branch_id' => $this->branch_id,
            'period' => $this->period,
            'title' => $this->title,
            'description' => $this->description,
            'key_results' => $this->key_results,
            'progress' => $this->progress,
            'status' => $this->status->value,
            'parent_objective_id' => $this->parent_objective_id,
            'visibility' => $this->visibility->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => $this->canEdit,
            ...($this->withCheckins ? ['checkins' => $this->checkins->map(static fn (ObjectiveCheckin $c): array => [
                'id' => $c->id,
                'author' => $c->author === null ? null : ['id' => $c->author->id, 'name' => $c->author->name],
                'progress_before' => $c->progress_before,
                'progress_after' => $c->progress_after,
                'key_results' => $c->key_results,
                'comment' => $c->comment,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all()] : []),
        ];
    }
}
