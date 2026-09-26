<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Controllers;

use App\Models\User;
use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Http\Requests\SendTestRequest;
use App\Modules\Channels\Http\Requests\SimulateRequest;
use App\Modules\Channels\Http\Resources\ChannelInfoResource;
use App\Modules\Channels\Services\ChannelAdminService;
use App\Modules\Channels\Services\DemoSeedFactory;
use App\Modules\Channels\Services\WebhookService;
use App\Modules\Recruiting\Http\Resources\TouchpointResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Channels part of the Integrations page (superadmin): webhook URLs, registration, test message, demo events. */
final class ChannelAdminController
{
    public function __construct(private readonly ChannelAdminService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return ChannelInfoResource::collection($this->service->overview());
    }

    public function register(Request $request, ChannelAdapter $channelKey): JsonResponse
    {
        $this->service->register($this->actor($request), $channelKey);

        return new JsonResponse(['data' => ['registered' => true]]);
    }

    public function test(SendTestRequest $request, ChannelAdapter $channelKey): JsonResponse
    {
        $sent = $this->service->test($this->actor($request), $channelKey, $request->to(), $request->text());

        return new JsonResponse(['data' => ['sent' => true, 'external_id' => $sent->externalId]]);
    }

    public function simulate(SimulateRequest $request, ChannelAdapter $channelKey, WebhookService $webhooks, DemoSeedFactory $seeds): JsonResponse
    {
        $result = $webhooks->simulate(
            $this->actor($request),
            $channelKey,
            $seeds->make($request->candidate(), $request->contact(), $request->text()),
        );

        return new JsonResponse(['data' => [
            'events' => $result->events,
            'created' => $result->created,
            'touchpoints' => TouchpointResource::collection($result->touchpoints)->toArray($request),
        ]], 201);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
