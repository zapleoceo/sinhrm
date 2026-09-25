<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\IntegrationConfig;

/** Read-only probe of an external service. Must not log URLs or payloads that contain secrets. */
interface ConnectionChecker
{
    public function check(IntegrationConfig $config): CheckResult;
}
