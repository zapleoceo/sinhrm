<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

enum EmployeeDirectoryGatewayState: string
{
    case DependencyPending = 'dependency_pending';
    case ReadyForPreview = 'ready_for_preview';
}
