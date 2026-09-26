<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\Ai\Services\AiAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/ai/* — superadmin (Gate manage-integrations, routes.php): AI status/usage and the test prompt. */
final readonly class AiController
{
    public function __construct(private AiAdminService $admin) {}

    public function status(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->status()]);
    }

    /** ?period=today|7d|30d */
    public function stats(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->stats($request->string('period', 'today')->toString())]);
    }

    public function test(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->test()]);
    }
}
