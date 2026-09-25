<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\Contracts\IntegrationDefinition;

/** Shared bits of definitions: an integration is checkable exactly when it implements ConnectionChecker. */
abstract class AbstractDefinition implements IntegrationDefinition
{
    final public function supportsCheck(): bool
    {
        return $this instanceof ConnectionChecker;
    }
}
