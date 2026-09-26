<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\ReviewAssignment;
use App\Modules\Perform\Models\ReviewCycle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cycle for the admin wizard; with $assignments — the progress list (who reviews whom and whether submitted;
 * never the answers).
 *
 * @mixin ReviewCycle
 */
final class ReviewCycleResource extends JsonResource
{
    /** @var Collection<int, ReviewAssignment>|null */
    private ?Collection $assignmentList = null;

    /** @param  Collection<int, ReviewAssignment>|null  $assignments */
    public static function for(ReviewCycle $cycle, ?Collection $assignments = null): self
    {
        $resource = new self($cycle);
        $resource->assignmentList = $assignments;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'participants' => [
                'branch_ids' => $this->participants['branch_ids'] ?? [],
                'department_ids' => $this->participants['department_ids'] ?? [],
            ],
            'types' => $this->types,
            'competency_ids' => $this->competency_ids,
            'anonymous' => $this->anonymous,
            'deadlines' => (object) $this->deadlines,
            'status' => $this->status->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'progress' => ['submitted' => (int) $this->submitted_count, 'total' => (int) $this->assignments_count],
            ...($this->assignmentList === null ? [] : ['assignments' => $this->assignmentList->map(static fn (ReviewAssignment $a): array => [
                'id' => $a->id,
                'subject' => ['id' => $a->subject->id, 'full_name' => $a->subject->full_name],
                'reviewer' => ['id' => $a->reviewer->id, 'full_name' => $a->reviewer->full_name],
                'type' => $a->type->value,
                'status' => $a->status->value,
            ])->values()->all()]),
        ];
    }
}
