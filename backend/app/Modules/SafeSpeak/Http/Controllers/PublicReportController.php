<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Controllers;

use App\Modules\SafeSpeak\Http\Requests\AccessCodeRequest;
use App\Modules\SafeSpeak\Http\Requests\SubmitReportRequest;
use App\Modules\SafeSpeak\Http\Resources\ReportPresenter;
use App\Modules\SafeSpeak\Services\SafeSpeakService;
use App\Modules\SafeSpeak\Support\ClientBucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anonymous side of Safe Speak (routes without session and without auth — see SafeSpeakServiceProvider).
 * The only thing taken from the request besides the form is a hashed bucket of the client address for rate limiting;
 * the user, the session, the address and the user agent are never read into the service.
 */
final class PublicReportController
{
    public function __construct(private readonly SafeSpeakService $safeSpeak, private readonly string $appKey) {}

    public function submit(SubmitReportRequest $request): JsonResponse
    {
        $result = $this->safeSpeak->submit($this->bucket($request), $request->category(), $request->subject(), $request->body());

        return new JsonResponse(['data' => ['code' => $result['code'], 'report' => ReportPresenter::forReporter($result['report'])]], 201, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function followUp(AccessCodeRequest $request): JsonResponse
    {
        $report = $this->safeSpeak->byCode($this->bucket($request), $request->code());

        return new JsonResponse(['data' => ReportPresenter::forReporter($report)], 200, ['Cache-Control' => 'no-store']);
    }

    public function reply(AccessCodeRequest $request): JsonResponse
    {
        $report = $this->safeSpeak->reporterReply($this->bucket($request), $request->code(), $request->body());

        return new JsonResponse(['data' => ReportPresenter::forReporter($report)], 201, ['Cache-Control' => 'no-store']);
    }

    private function bucket(Request $request): string
    {
        return ClientBucket::of($request->ip(), $this->appKey);
    }
}
