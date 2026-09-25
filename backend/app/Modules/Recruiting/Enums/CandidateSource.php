<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** Where a candidate came from. UTM details live in candidates.utm. */
enum CandidateSource: string
{
    case Manual = 'manual';
    case WorkUa = 'work_ua';
    case RobotaUa = 'robota_ua';
    case Djinni = 'djinni';
    case MetaAds = 'meta_ads';
    case Site = 'site';
    case Referral = 'referral';
    case Telegram = 'telegram';
    case Import = 'import';
    case Inbox = 'inbox';
    case Other = 'other';
}
