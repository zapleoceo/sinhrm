<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use App\Modules\GoogleWorkspace\Support\GoogleOAuthConfig;
use Illuminate\Http\JsonResponse;

/** Connection state without tokens: GET /api/google/status (superadmin), GET /api/google/calendar (everyone). */
final readonly class GoogleStatusController
{
    public function __construct(private GoogleConnectionStore $connections, private GoogleOAuthConfig $config) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (GoogleService $s): array => $this->connections->state($s)->toArray(), GoogleService::cases()),
            'meta' => ['redirect_uri' => $this->config->redirectUri, 'oauth_configured' => $this->config->isConfigured()],
        ]);
    }

    /** Whether "Schedule a meeting" can work (the card disables the button otherwise). */
    public function calendar(): JsonResponse
    {
        return new JsonResponse(['data' => ['connected' => $this->connections->state(GoogleService::Calendar)->usable]]);
    }
}
