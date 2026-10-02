<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Http\Concerns\ResolvesActor;
use App\Modules\Core\Http\Responses\Download;
use App\Modules\Desk\Http\Requests\CaseCommentRequest;
use App\Modules\Desk\Http\Requests\OpenCaseRequest;
use App\Modules\Desk\Http\Requests\QueueRequest;
use App\Modules\Desk\Http\Requests\UpdateCaseRequest;
use App\Modules\Desk\Http\Resources\DeskCasePresenter;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Services\DeskService;
use App\Modules\Documents\Http\Requests\UploadDocumentFileRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Helpdesk cases: own cases for the employee, the queue for HR; thread, status, attachments. */
final class DeskCaseController
{
    use ResolvesActor;

    public function __construct(private readonly DeskService $desk) {}

    public function mine(Request $request): JsonResponse
    {
        return $this->collection($this->desk->mine($this->actor($request)), false);
    }

    public function queue(QueueRequest $request): JsonResponse
    {
        return $this->collection($this->desk->queue($request->filter()), true);
    }

    public function store(OpenCaseRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $case = $this->desk->open($actor, $request->categoryId(), $request->subject(), $request->body());

        return new JsonResponse(['data' => $this->detail($actor, $case)], 201);
    }

    public function show(Request $request, int $case): JsonResponse
    {
        $actor = $this->actor($request);

        return new JsonResponse(['data' => $this->detail($actor, $this->desk->findVisible($actor, $case))]);
    }

    public function update(UpdateCaseRequest $request, int $case): JsonResponse
    {
        $actor = $this->actor($request);
        $updated = $this->desk->update($actor, $this->desk->findVisible($actor, $case), $request->changes());

        return new JsonResponse(['data' => $this->detail($actor, $updated)]);
    }

    public function comment(CaseCommentRequest $request, int $case): JsonResponse
    {
        $actor = $this->actor($request);
        $visible = $this->desk->findVisible($actor, $case);
        $this->desk->comment($actor, $visible, $request->body(), $request->internal(), $request->articleId());

        return new JsonResponse(['data' => $this->detail($actor, $this->desk->findVisible($actor, $case))], 201);
    }

    /** Reuses the Documents upload validation (pdf/png/jpg/docx, ≤ 2 MB); the type is re-checked from the bytes. */
    public function attach(UploadDocumentFileRequest $request, int $case): JsonResponse
    {
        $actor = $this->actor($request);
        $visible = $this->desk->findVisible($actor, $case);
        $file = $request->upload();
        $this->desk->attach($actor, $visible, (string) $file->getContent(), $file->getClientOriginalName());

        return new JsonResponse(['data' => $this->detail($actor, $this->desk->findVisible($actor, $case))], 201);
    }

    /** Always a download (never inline on our origin), like documents. */
    public function download(Request $request, int $case, int $attachment): Response
    {
        $file = $this->desk->attachment($this->desk->findVisible($this->actor($request), $case), $attachment);
        $content = base64_decode($file->content, true);
        abort_if($content === false, 404);

        return Download::file($content, $file->filename, $file->mime, $file->size);
    }

    /** @return array<string, mixed> */
    private function detail(User $actor, DeskCase $case): array
    {
        $case->load(['comments.author:id,name', 'attachments']);

        return DeskCasePresenter::present($case, $this->desk->isHr($actor), true, $this->desk->articleTitles($case->comments));
    }

    /** @param  Collection<int, DeskCase>  $cases */
    private function collection(Collection $cases, bool $hr): JsonResponse
    {
        return new JsonResponse(['data' => $cases->map(static fn (DeskCase $c): array => DeskCasePresenter::present($c, $hr))->values()->all()]);
    }
}
