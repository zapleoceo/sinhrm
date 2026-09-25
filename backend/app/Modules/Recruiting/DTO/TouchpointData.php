<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Support\Carbon;

/** A touch logged by hand in the product (note, call log, meeting, …). */
final readonly class TouchpointData
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public Channel $channel,
        public Direction $direction,
        public ?string $body,
        public Carbon $occurredAt,
        public ?int $applicationId = null,
        public array $meta = [],
    ) {}
}
