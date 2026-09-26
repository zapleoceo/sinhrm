<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Enums;

enum WorkflowKind: string
{
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';
    case Custom = 'custom';
}
