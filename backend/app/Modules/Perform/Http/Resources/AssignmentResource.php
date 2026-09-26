<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\Competency;
use App\Modules\Perform\Models\ReviewAnswer;
use App\Modules\Perform\Models\ReviewAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The reviewer's own form. With $competencies — the full form (competencies, scale levels, own answers).
 *
 * @mixin ReviewAssignment
 */
final class AssignmentResource extends JsonResource
{
    /** @var Collection<int, Competency>|null */
    private ?Collection $competencies = null;

    /** @param  Collection<int, Competency>|null  $competencies */
    public static function for(ReviewAssignment $assignment, ?Collection $competencies = null): self
    {
        $resource = new self($assignment);
        $resource->competencies = $competencies;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cycle = $this->cycle;

        return [
            'id' => $this->id,
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status->value,
                'deadline' => $cycle->deadlines[$this->type->value] ?? null,
            ],
            'subject' => ['id' => $this->subject->id, 'full_name' => $this->subject->full_name],
            'type' => $this->type->value,
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            ...($this->competencies === null ? [] : [
                'competencies' => $this->competencies->map(static fn (Competency $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'levels' => $c->scale->levels,
                ])->values()->all(),
                'answers' => $this->answers->map(static fn (ReviewAnswer $a): array => [
                    'competency_id' => $a->competency_id, 'rating' => $a->rating, 'comment' => $a->comment,
                ])->values()->all(),
            ]),
        ];
    }
}
