<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers;

use App\Models\User;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Privacy\Http\Requests\ErasePersonalDataRequest;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Models\PrivacySettings;
use App\Modules\Privacy\Services\ExportHtmlRenderer;
use App\Modules\Privacy\Services\PersonalDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Personal-data rights (superadmin, admin): export (JSON or HTML file), erase (anonymize), journal, retention rule. */
final class PersonalDataController
{
    public function __construct(private readonly PersonalDataService $service, private readonly ExportHtmlRenderer $html) {}

    /** GET /api/privacy/{type}/{id}/export?format=json|html — a downloadable file with everything we keep. */
    public function export(Request $request, string $type, int $id): Response
    {
        $request->validate(['format' => ['nullable', 'in:json,html']]);
        $export = $this->service->export($this->subject($type, $id), $this->actor($request)->id);
        $name = 'personal-data-'.$type.'-'.$id;
        if ($request->query('format') === 'html') {
            return new Response($this->html->render($export), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$name.'.html"',
            ]);
        }

        return new Response((string) json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.json"',
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
        $subject = $this->subject($type, $id);
        $rows = PrivacyRequest::query()->where('subject_type', $subject->type->value)->where('subject_id', $subject->id)
            ->orderByDesc('id')->limit(100)->get()
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
        return new JsonResponse(['data' => ['retention_rejected_months' => PrivacySettings::current()->retention_rejected_months]]);
    }

    /** PUT /api/privacy/settings {retention_rejected_months: 1..120 | null (off)}. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['retention_rejected_months' => ['present', 'nullable', 'integer', 'min:1', 'max:120']]);
        $settings = PrivacySettings::current();
        $settings->update(['retention_rejected_months' => $data['retention_rejected_months']]);

        return new JsonResponse(['data' => ['retention_rejected_months' => $settings->retention_rejected_months]]);
    }

    private function subject(string $type, int $id): DataSubject
    {
        return new DataSubject(DataSubjectType::from($type), $id);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
