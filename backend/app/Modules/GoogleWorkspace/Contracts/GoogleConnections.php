<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\DTO\ConnectionState;
use App\Modules\GoogleWorkspace\Enums\GoogleService;

/**
 * Read-only view of the Google connections for other modules (MailAgent, TimeOff, Workflows): is Gmail / Calendar
 * usable, and who connected it. Implemented by GoogleWorkspace\Services\GoogleConnectionStore (tokens stay inside).
 */
interface GoogleConnections
{
    public function state(GoogleService $service): ConnectionState;

    /** User who connected the service (the actor of background jobs), if any. */
    public function connectedBy(GoogleService $service): ?int;
}
