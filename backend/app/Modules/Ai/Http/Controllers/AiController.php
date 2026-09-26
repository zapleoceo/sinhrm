<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\Ai\Services\AiAdminService;
use Illuminate\Http\JsonResponse;

/** /api/ai/* — superadmin (Gate manage-integrations, routes.php): AI status/usage and the test prompt. */
final readonly class AiController
{
    public function __construct(private AiAdminService $admin) {}

    public function status(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->status()]);
    }

    public function test(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->test()]);
    }
}
