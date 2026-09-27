<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** Offer lifecycle: draft → sent (by e-mail) → accepted | declined (marked by the recruiter). */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
