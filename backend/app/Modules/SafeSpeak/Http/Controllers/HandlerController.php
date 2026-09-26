<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Controllers;

use App\Models\User;
use App\Modules\SafeSpeak\Http\Requests\HandlerUpdateRequest;
use App\Modules\SafeSpeak\Http\Resources\ReportPresenter;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use App\Modules\SafeSpeak\Services\SafeSpeakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Handler inbox (gate safe-speak-handle: admins with the explicit handler flag). */
final class HandlerController
{
    public function __construct(private readonly SafeSpeakService $safeSpeak) {}

    public function index(HandlerUpdateRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->safeSpeak->inbox($request->status())
            ->map(static fn (SafeSpeakReport $r): array => ReportPresenter::forHandler($r, false))->values()->all()]);
    }

    public function show(int $report): JsonResponse
    {
        return new JsonResponse(['data' => ReportPresenter::forHandler($this->safeSpeak->find($report), true)]);
    }

    public function reply(HandlerUpdateRequest $request, int $report): JsonResponse
    {
        $handler = $request->user();
        assert($handler instanceof User);
        $updated = $this->safeSpeak->handlerReply($handler, $this->safeSpeak->find($report), $request->body());

        return new JsonResponse(['data' => ReportPresenter::forHandler($updated, true)], 201);
    }

    public function update(HandlerUpdateRequest $request, int $report): JsonResponse
    {
        $status = $request->status();
        assert($status !== null);

        return new JsonResponse(['data' => ReportPresenter::forHandler($this->safeSpeak->setStatus($this->safeSpeak->find($report), $status), true)]);
    }

    /** GET /api/safe-speak/me — whether the current user handles reports (the UI shows the inbox link). */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return new JsonResponse(['data' => ['handler' => $this->safeSpeak->isHandler($user)]]);
    }
}
