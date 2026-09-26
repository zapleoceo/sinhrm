<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Models\User;
use App\Modules\Documents\Http\Requests\PreviewTemplateRequest;
use App\Modules\Documents\Http\Requests\SaveDocumentTemplateRequest;
use App\Modules\Documents\Http\Resources\DocumentTemplateResource;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Services\DocumentTemplateService;
use App\Modules\People\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Document templates (admin only, route gate documents-manage). No DELETE: archive instead. */
final class DocumentTemplateController
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly EmployeeService $employees,
    ) {}

    /** ?archived=1 — with archived ones. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return DocumentTemplateResource::collection($this->templates->list($request->boolean('archived')));
    }

    public function store(SaveDocumentTemplateRequest $request): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        /** @var array{name: string, body: string, category?: string|null} $data */
        $data = $request->attributesToSave();

        return (new DocumentTemplateResource($this->templates->create($actor, $data)))->response()->setStatusCode(201);
    }

    public function update(SaveDocumentTemplateRequest $request, DocumentTemplate $template): DocumentTemplateResource
    {
        return new DocumentTemplateResource($this->templates->update($template, $request->attributesToSave()));
    }

    /** Rendered preview (sanitized HTML) with an employee's values or synthetic sample values. */
    public function preview(PreviewTemplateRequest $request): JsonResponse
    {
        $employeeId = $request->employeeId();
        $employee = $employeeId === null ? null : $this->employees->find($employeeId);

        return new JsonResponse(['data' => $this->templates->preview($request->body(), $employee)]);
    }
}
