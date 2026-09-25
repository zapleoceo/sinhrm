<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Models\User;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Integrations\Contracts\IntegrationDefinition;
use App\Modules\Integrations\Http\Requests\SetStatusRequest;
use App\Modules\Integrations\Http\Requests\UpdateAiPolicyRequest;
use App\Modules\Integrations\Http\Requests\UpdateIntegrationRequest;
use App\Modules\Integrations\Http\Resources\IntegrationLogResource;
use App\Modules\Integrations\Http\Resources\IntegrationResource;
use App\Modules\Integrations\Services\AiPolicyService;
use App\Modules\Integrations\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Integrations admin. Access: Gate "manage-integrations" (routes.php).
 * {integration} is resolved to an IntegrationDefinition by the provider (unknown key → 404).
 */
final class IntegrationsController
{
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

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
