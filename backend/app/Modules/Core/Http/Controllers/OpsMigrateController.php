<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\MigrationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/ops/migrate            — apply pending migrations (all environments).
 * POST /api/ops/migrate?fresh=1    — rebuild DB with synthetic seed; refused in production.
 * Called by the deploy workflow so DB credentials never leave Vercel.
 */
final class OpsMigrateController
{
    public function __invoke(Request $request, MigrationRunner $runner): JsonResponse
    {
        $fresh = $request->boolean('fresh');

        if ($fresh && app()->isProduction()) {
            return response()->json(['ok' => false, 'error' => 'fresh is not allowed in production'], 403);
        }

        $output = $fresh ? $runner->rebuildWithSeed() : $runner->migrate();

        return response()->json(['ok' => true, 'fresh' => $fresh, 'output' => trim($output)]);
    }
}
