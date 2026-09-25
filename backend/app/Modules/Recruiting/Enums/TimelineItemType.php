<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** Kind of an item in the merged candidate timeline. */
enum TimelineItemType: string
{
    case Touchpoint = 'touchpoint';
    case StageChange = 'stage_change';
}
