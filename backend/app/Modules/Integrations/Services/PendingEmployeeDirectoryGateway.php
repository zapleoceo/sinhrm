<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Contracts\EmployeeDirectoryGateway;
use App\Modules\Integrations\DTO\EmployeeDirectorySnapshot;
use App\Modules\Integrations\DTO\EmployeeDirectorySourceStatus;
use App\Modules\Integrations\Enums\EmployeeDirectoryGatewayState;
use App\Modules\Integrations\Exceptions\EmployeeDirectoryUnavailable;

/** Fail-closed binding until the approved SDK and source contract are installed. Makes no network calls. */
final class PendingEmployeeDirectoryGateway implements EmployeeDirectoryGateway
{
    public function status(): EmployeeDirectorySourceStatus
    {
        return new EmployeeDirectorySourceStatus(EmployeeDirectoryGatewayState::DependencyPending, [
            'itstep_user_client_sdk',
            'authoritative_response_schema_and_namespace',
            'approved_employee_id_and_catalog_mappings',
            'trusted_service_endpoint_and_auth_configuration',
        ]);
    }

    public function fetchCompleteSnapshot(): EmployeeDirectorySnapshot
    {
        throw new EmployeeDirectoryUnavailable('dependency_pending');
    }
}
