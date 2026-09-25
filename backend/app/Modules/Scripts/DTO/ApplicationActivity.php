<?php

declare(strict_types=1);

namespace App\Modules\Scripts\DTO;

use Illuminate\Support\Carbon;

/** What the follow-up rules need to know about one active application. */
final readonly class ApplicationActivity
{
    public function __construct(
        public int $applicationId,
        public int $candidateId,
        public int $recruiterId,
        public Carbon $createdAt,
        /** Last real touch of any channel/direction (applications.last_touch_at). */
        public ?Carbon $lastTouchAt,
        /** Our last outbound message (messenger or e-mail) to the candidate. */
        public ?Carbon $lastOutboundAt,
        /** Last inbound touch from the candidate (any channel except system). */
        public ?Carbon $lastInboundAt,
        /** Last stage change of the application. */
        public ?Carbon $lastStageChangeAt,
    ) {}
}
