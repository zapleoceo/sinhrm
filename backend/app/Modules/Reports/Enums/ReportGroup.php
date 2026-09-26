<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/** Catalog groups (the UI shows them as sections, in this order). */
enum ReportGroup: string
{
    case General = 'general';
    case Hr = 'hr';
    case Performance = 'performance';
    case Recruiting = 'recruiting';
}
