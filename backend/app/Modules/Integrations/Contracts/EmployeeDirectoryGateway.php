<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\DTO\EmployeeDirectorySnapshot;
use App\Modules\Integrations\DTO\EmployeeDirectorySourceStatus;
use App\Modules\Integrations\Exceptions\EmployeeDirectoryUnavailable;

/** Source access boundary. Implementations must use the approved Itstep SDK and source contract. */
interface EmployeeDirectoryGateway
{
    public function status(): EmployeeDirectorySourceStatus;

    /** @throws EmployeeDirectoryUnavailable */
    public function fetchCompleteSnapshot(): EmployeeDirectorySnapshot;
}
