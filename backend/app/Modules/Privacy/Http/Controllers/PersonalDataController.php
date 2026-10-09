<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers;

use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Core\Http\Responses\Download;
use App\Modules\Privacy\Contracts\PrivacyRepository;
use App\Modules\Privacy\Http\Requests\ErasePersonalDataRequest;
use App\Modules\Privacy\Http\Requests\ExportPersonalDataRequest;
use App\Modules\Privacy\Http\Requests\UpdatePrivacySettingsRequest;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Services\ExportHtmlRenderer;
use App\Modules\Privacy\Services\PersonalDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Personal-data rights (superadmin, admin): export (JSON or HTML file), erase (anonymize), journal, retention rule. */
final class PersonalDataController
{
    use ResolvesActor;

    public function __construct(
        private readonly PersonalDataService $service,
        private readonly ExportHtmlRenderer $html,
        private readonly PrivacyRepository $privacy,
    ) {}

    /** GET /api/privacy/{type}/{id}/export?format=json|html — a downloadable file with everything we keep. */
    public function export(ExportPersonalDataRequest $request, string $type, int $id): Response
    {
        $export = $this->service->export($this->subject($type, $id), $this->actor($request)->id);
        $name = 'personal-data-'.$type.'-'.$id;
        if ($request->wantsHtml()) {
            return new Response($this->html->render($export), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => Download::disposition($name.'.html'),
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return new Response((string) json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => Download::disposition($name.'.json'),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST /api/privacy/{type}/{id}/erase {reason, confirm} — anonymize in place, irreversible. */
    public function erase(ErasePersonalDataRequest $request, string $type, int $id): JsonResponse
    {
        $counts = $this->service->erase($this->subject($type, $id), $request->reason(), $this->actor($request)->id);

        return new JsonResponse(['data' => ['erased' => true, 'counts' => $counts]]);
    }

    /** GET /api/privacy/{type}/{id}/requests — the journal of export/erase requests about this person. */
    public function requests(string $type, int $id): JsonResponse
    {
        $rows = $this->privacy->requestsFor($this->subject($type, $id), 100)
            ->map(static fn (PrivacyRequest $r): array => [
                'action' => $r->action,
                'trigger' => $r->trigger,
                'reason' => $r->reason,
                'actor_id' => $r->actor_id,
                'counts' => $r->counts,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return new JsonResponse(['data' => $rows]);
    }

    public function settings(): JsonResponse
    {
        return new JsonResponse(['data' => ['retention_rejected_months' => $this->privacy->retentionRejectedMonths()]]);
    }

    /** PUT /api/privacy/settings {retention_rejected_months: 1..120 | null (off)}. */
    public function updateSettings(UpdatePrivacySettingsRequest $request): JsonResponse
    {
        $stored = $this->privacy->setRetentionRejectedMonths($request->retentionRejectedMonths());

        return new JsonResponse(['data' => ['retention_rejected_months' => $stored]]);
    }

    private function subject(string $type, int $id): DataSubject
    {
        return new DataSubject(DataSubjectType::from($type), $id);
    }
}
