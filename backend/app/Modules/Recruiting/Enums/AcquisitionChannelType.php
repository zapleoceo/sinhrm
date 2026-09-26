<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** Kind of an acquisition channel (tz3), for grouping in the dictionary and reports. */
enum AcquisitionChannelType: string
{
    case JobBoard = 'job_board';
    case Ads = 'ads';
    case Referral = 'referral';
    case Social = 'social';
    case Site = 'site';
    case Event = 'event';
    case Agency = 'agency';
    case Other = 'other';
}
