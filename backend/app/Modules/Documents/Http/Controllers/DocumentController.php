<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Models\User;
use App\Modules\Documents\Http\Requests\CreateDocumentRequest;
use App\Modules\Documents\Http\Requests\ListDocumentsRequest;
use App\Modules\Documents\Http\Requests\RejectDocumentRequest;
use App\Modules\Documents\Http\Requests\UpdateDocumentRequest;
use App\Modules\Documents\Http\Requests\UploadDocumentFileRequest;
use App\Modules\Documents\Http\Resources\DocumentResource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentTemplateService;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Employee documents. Reading: admin, the employee (not drafts), managers above the employee; writing: admin
 * (route gate); acknowledge / reject: the employee themself. Not visible → 404 (no existence leak).
 */
final class DocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentTemplateService $templates,
        private readonly EmployeeService $employees,
        private readonly PeopleScope $scope,
    ) {}

    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $ctx = $this->context($request);

        return $this->collection($this->documents->list($ctx, $request->filter()), $ctx);
    }

    /** GET /api/me/documents — own documents, drafts hidden; no linked employee → empty list. */
    public function mine(Request $request): JsonResponse
    {
        $ctx = $this->context($request);

        return $this->collection($this->documents->mine($ctx), $ctx);
    }

    public function show(Request $request, Document $document): DocumentResource
    {
        $ctx = $this->context($request);

        return DocumentResource::for($this->documents->findVisible($ctx, $document->id), $ctx, detailed: true);
    }

    public function store(CreateDocumentRequest $request): JsonResponse
    {
        $ctx = $this->context($request);
        $templateId = $request->templateId();
        $document = $this->documents->generate(
            $this->actor($request),
            $this->employees->find($request->employeeId()),
            $templateId === null ? null : $this->templates->find($templateId),
            $request->title(),
            $request->contentMd(),
            $request->category(),
        );

        return DocumentResource::for($document, $ctx, detailed: true)->response()->setStatusCode(201);
    }

    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        return DocumentResource::for($this->documents->update($document, $request->attributesToSave()), $this->context($request), detailed: true);
    }

    public function upload(UploadDocumentFileRequest $request, Document $document): DocumentResource
    {
        $file = $request->upload();
        $document = $this->documents->attachFile($document, (string) $file->getContent(), $file->getClientOriginalName());

        return DocumentResource::for($document, $this->context($request), detailed: true);
    }

    /** The attached file as a download (never inline: the browser must not render an uploaded file on our origin). */
    public function download(Request $request, Document $document): Response
    {
        $file = $this->documents->file($this->documents->findVisible($this->context($request), $document->id));
        abort_if($file === null, 404);
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->filename) ?: 'file';

        return new Response($file->content, 200, [
            'Content-Type' => $file->mime,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->filename, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function send(Request $request, Document $document): DocumentResource
    {
        return DocumentResource::for($this->documents->send($document), $this->context($request), detailed: true);
    }

    public function acknowledge(Request $request, Document $document): DocumentResource
    {
        $ctx = $this->context($request);
        $visible = $this->documents->findVisible($ctx, $document->id);
        $signed = $this->documents->acknowledge($this->actor($request), $ctx, $visible, $request->ip(), $request->userAgent());

        return DocumentResource::for($signed, $ctx, detailed: true);
    }

    public function reject(RejectDocumentRequest $request, Document $document): DocumentResource
    {
        $ctx = $this->context($request);
        $visible = $this->documents->findVisible($ctx, $document->id);

        return DocumentResource::for($this->documents->reject($ctx, $visible, $request->reason()), $ctx, detailed: true);
    }

    /** @param  Collection<int, Document>  $documents */
    private function collection(Collection $documents, PeopleContext $ctx): JsonResponse
    {
        return new JsonResponse(['data' => $documents->map(
            static fn (Document $d): array => DocumentResource::for($d, $ctx)->resolve(),
        )->values()->all()]);
    }

    private function context(Request $request): PeopleContext
    {
        return $this->scope->for($this->actor($request));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
