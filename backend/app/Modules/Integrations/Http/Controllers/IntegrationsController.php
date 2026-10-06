<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Integrations\Contracts\EmployeeDirectoryGateway;
use App\Modules\Integrations\Contracts\IntegrationDefinition;
use App\Modules\Integrations\Enums\EmployeeDirectoryGatewayState;
use App\Modules\Integrations\Exceptions\EmployeeDirectoryUnavailable;
use App\Modules\Integrations\Http\Requests\SetStatusRequest;
use App\Modules\Integrations\Http\Requests\UpdateAiPolicyRequest;
use App\Modules\Integrations\Http\Requests\UpdateIntegrationRequest;
use App\Modules\Integrations\Http\Resources\IntegrationLogResource;
use App\Modules\Integrations\Http\Resources\IntegrationResource;
use App\Modules\Integrations\Services\AiPolicyService;
use App\Modules\Integrations\Services\EmployeeDirectoryPreview;
use App\Modules\Integrations\Services\EmployeeDirectorySyntheticPreview;
use App\Modules\Integrations\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

/**
 * Integrations admin. Access: Gate "manage-integrations" (routes.php).
 * {integration} is resolved to an IntegrationDefinition by the provider (unknown key → 404).
 */
final class IntegrationsController
{
    use ResolvesActor;

    public function __construct(
        private readonly IntegrationService $service,
        private readonly AiPolicyService $aiPolicy,
    ) {}

    public function index(AiPolicy $policy): AnonymousResourceCollection
    {
        return IntegrationResource::collection($this->service->list())
            ->additional(['ai_policy' => ['enabled' => $policy->enabled()]]);
    }

    public function update(UpdateIntegrationRequest $request): IntegrationResource
    {
        return new IntegrationResource($this->service->update(
            $this->actor($request), $request->definition(), $request->settings(), $request->secrets(),
        ));
    }

    public function check(Request $request, IntegrationDefinition $integration): IntegrationResource
    {
        return new IntegrationResource($this->service->check($this->actor($request), $integration));
    }

    public function status(SetStatusRequest $request, IntegrationDefinition $integration): IntegrationResource
    {
        return new IntegrationResource($this->service->setStatus($this->actor($request), $integration, $request->status()));
    }

    public function logs(IntegrationDefinition $integration): AnonymousResourceCollection
    {
        return IntegrationLogResource::collection($this->service->logs($integration));
    }

    public function updateAiPolicy(UpdateAiPolicyRequest $request): JsonResponse
    {
        $enabled = $this->aiPolicy->set($this->actor($request), $request->enabled());

        return new JsonResponse(['data' => ['enabled' => $enabled]]);
    }

    public function employeeDirectoryStatus(EmployeeDirectoryGateway $gateway): JsonResponse
    {
        $status = $gateway->status();

        return new JsonResponse(['data' => [
            'status' => $status->status->value,
            'missing_inputs' => $status->missingInputs,
            'scope_configured' => $status->namespace !== null && $status->namespace !== '',
            'writes_enabled' => false,
        ]]);
    }

    public function employeeDirectoryPreview(EmployeeDirectoryGateway $gateway, EmployeeDirectoryPreview $preview): JsonResponse
    {
        $status = $gateway->status();
        if ($status->status !== EmployeeDirectoryGatewayState::ReadyForPreview || $status->namespace === null || trim($status->namespace) === '') {
            return new JsonResponse(['code' => 'dependency_pending'], 409);
        }

        try {
            $snapshot = $gateway->fetchCompleteSnapshot();
        } catch (EmployeeDirectoryUnavailable $exception) {
            return new JsonResponse(['code' => $exception->reason], 409);
        }

        try {
            return new JsonResponse(['data' => $preview->build([
                'namespace' => $snapshot->namespace,
                'complete' => $snapshot->complete,
                'profiles' => $snapshot->profiles,
            ], $status->namespace)]);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['code' => 'snapshot_invalid', 'reason' => $exception->getMessage()], 422);
        }
    }

    public function employeeDirectorySyntheticPreview(EmployeeDirectorySyntheticPreview $preview): JsonResponse
    {
        return new JsonResponse(['data' => $preview->build(), 'synthetic' => true]);
    }
}
