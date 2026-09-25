<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

/** Global kill-switch for AI provider calls. Off by default until the owner approves models and prompts. */
interface AiPolicy
{
    public function enabled(): bool;
}
