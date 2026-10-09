<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

use App\Modules\Recruiting\Models\Touchpoint;

/** Outcome of one webhook delivery / simulation: events parsed and touchpoints stored (new or already known). */
final readonly class WebhookResult
{
    /** @param  list<Touchpoint>  $touchpoints */
    public function __construct(
        public int $events,
        public array $touchpoints,
        public int $created,
        /** Accepted via the deprecated ?token= (telephony transitional flag): the answer carries a Deprecation header. */
        public bool $deprecatedAuth = false,
    ) {}
}
