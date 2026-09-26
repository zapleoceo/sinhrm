<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Enums;

/** Fixed categories of anonymous reports (translated in the UI). */
enum ReportCategory: string
{
    case Harassment = 'harassment';
    case Discrimination = 'discrimination';
    case Fraud = 'fraud';
    case Safety = 'safety';
    case Ethics = 'ethics';
    case Other = 'other';
}
