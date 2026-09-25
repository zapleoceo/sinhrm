<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\TimelineItemType;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Support\Carbon;

/** One row of the merged candidate timeline: a touchpoint or a stage change. */
final readonly class TimelineEntry
{
    public function __construct(
        public TimelineItemType $type,
        public Carbon $at,
        public Touchpoint|StageChange $item,
        /** @var array<string, mixed>|null Script evaluation summary of a touchpoint (Contracts/TouchpointEvaluations) */
        public ?array $evaluation = null,
    ) {}

    /** @param  array<string, mixed>|null  $evaluation */
    public function withEvaluation(?array $evaluation): self
    {
        return new self($this->type, $this->at, $this->item, $evaluation);
    }
}
