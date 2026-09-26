<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Controllers;

use App\Modules\Observability\Http\Requests\ClientErrorRequest;
use App\Modules\Observability\Models\ErrorEvent;
use App\Modules\Observability\Services\ErrorRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * /api/errors/* — the in-app error log. client: any signed-in user (throttled); the rest: superadmin
 * (gate manage-integrations, routes.php).
 */
final class ErrorLogController
{
    private const int LIST_LIMIT = 200;

    public function __construct(private readonly ErrorRecorder $recorder) {}

    public function client(ClientErrorRequest $request): Response
    {
        $id = $request->user()?->getAuthIdentifier();
        $this->recorder->recordClient($request->kind(), $request->errorMessage(), $request->location(), $request->spaRoute(), is_numeric($id) ? (int) $id : null);

        return response()->noContent();
    }

    /** Groups, newest first; ?status=open (default) | resolved | all. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');
        $query = ErrorEvent::query()->orderByDesc('last_seen_at')->limit(self::LIST_LIMIT);
        if ($status === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        return new JsonResponse(['data' => $query->get()->map(static fn (ErrorEvent $e): array => $e->present())->values()->all()]);
    }

    public function show(int $error): JsonResponse
    {
        return new JsonResponse(['data' => ErrorEvent::query()->findOrFail($error)->present()]);
    }

    /** {"resolved": true|false} */
    public function update(Request $request, int $error): JsonResponse
    {
        $data = $request->validate(['resolved' => ['required', 'boolean']]);
        $event = ErrorEvent::query()->findOrFail($error);
        $event->resolved_at = $data['resolved'] ? Carbon::now() : null;
        $event->save();

        return new JsonResponse(['data' => $event->present()]);
    }
}
