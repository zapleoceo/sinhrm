<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Enums;

/** Kind of a survey (drives templates and the default reporting). */
enum SurveyType: string
{
    case Engagement = 'engagement';
    case Lifecycle = 'lifecycle';
    case Enps = 'enps';
    case Mood = 'mood';
    case Custom = 'custom';
}
