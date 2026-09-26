<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Models\User;
use App\Modules\Documents\Contracts\DocumentRepository;
use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\DTO\DocumentFilter;
use App\Modules\Documents\DTO\StoredFile;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\SignatureMethod;
use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Support\TemplateFiller;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Services\TaskService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Employee documents: generate from a template or write by hand, attach a file, send for acknowledgement
 * (a task "read and acknowledge" for the employee), acknowledge / reject by the employee, archive.
 *
 * Access (PeopleScope): admin — everything; the employee — own documents except drafts; a manager above the
 * employee — read the same. Writing is admin-only (routes gate), acknowledging — the employee only.
 */
final readonly class DocumentService
{
    public const int LIMIT = 200;

    public const string MY_DOCUMENTS_LINK = '/me/documents';

    public function __construct(
        private DocumentRepository $documents,
        private DocumentStorage $storage,
        private TaskService $tasks,
        private LoggerInterface $log,
    ) {}

    public static function taskRule(int $documentId): string
    {
        return 'doc:'.$documentId;
    }

    /** @return Collection<int, Document> */
    public function list(PeopleContext $ctx, DocumentFilter $filter): Collection
    {
        if (! $ctx->admin) {
            $filter = $filter->restrictedTo($ctx->visibleIds() ?? [], false);
        }

        return $this->documents->list($filter, self::LIMIT);
    }

    /** @return Collection<int, Document> the caller's own documents (drafts hidden) */
    public function mine(PeopleContext $ctx): Collection
    {
        if ($ctx->selfId === null) {
            return new Collection;
        }

        return $this->documents->list(self::mineFilter($ctx->selfId), self::LIMIT);
    }

    /** How many of mine() wait for my acknowledgement (status sent) — sidebar counter. */
    public function countAwaitingMe(PeopleContext $ctx): int
    {
        if ($ctx->selfId === null) {
            return 0;
        }

        return $this->documents->count(self::mineFilter($ctx->selfId, DocumentStatus::Sent));
    }

    private static function mineFilter(int $selfId, ?DocumentStatus $status = null): DocumentFilter
    {
        return new DocumentFilter(employeeIds: [$selfId], status: $status, withDrafts: false);
    }

    public function canView(PeopleContext $ctx, Document $document): bool
    {
        if ($ctx->admin) {
            return true;
        }

        return $document->status !== DocumentStatus::Draft
            && ($ctx->isSelf($document->employee_id) || $ctx->isAbove($document->employee_id));
    }

    /** @throws ModelNotFoundException<Document> when missing or not visible (no existence leak) */
    public function findVisible(PeopleContext $ctx, int $id): Document
    {
        $document = $this->documents->find($id);
        if ($document === null || ! $this->canView($ctx, $document)) {
            throw (new ModelNotFoundException)->setModel(Document::class, [$id]);
        }

        return $document;
    }

    /**
     * New draft for the employee: from a template (variables filled now — later template edits do not change it)
     * and/or custom Markdown. $actor null = created by a workflow.
     *
     * @throws DocumentException template_archived
     */
    public function generate(
        ?User $actor,
        Employee $employee,
        ?DocumentTemplate $template,
        ?string $title = null,
        ?string $contentMd = null,
        ?string $category = null,
        ?Carbon $today = null,
    ): Document {
        if ($template !== null && $template->archived) {
            throw DocumentException::templateArchived();
        }
        if ($template !== null && $contentMd === null) {
            $contentMd = TemplateFiller::fill($template->body, DocumentVariables::forEmployee($employee, $today ?? Carbon::now()))['text'];
        }
        $document = $this->documents->create([
            'employee_id' => $employee->id,
            'template_id' => $template?->id,
            'title' => $title ?? $template->name ?? 'Document',
            'category' => $category ?? $template?->category,
            'status' => DocumentStatus::Draft->value,
            'content_md' => $contentMd,
            'created_by' => $actor?->id,
        ]);
        $this->log->info('documents.created', ['id' => $document->id, 'employee' => $employee->id, 'by' => $actor?->id]);

        return $this->reload($document);
    }

    /**
     * Title/category/content of an editable document; status "archived" archives it (from any status).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DocumentException not_editable
     */
    public function update(Document $document, array $data): Document
    {
        if (($data['status'] ?? null) === DocumentStatus::Archived->value) {
            $this->documents->update($document, ['status' => DocumentStatus::Archived->value]);
            $this->closeTask($document);

            return $this->reload($document);
        }
        unset($data['status']);
        if (array_key_exists('content_md', $data) && ! $document->status->isEditable()) {
            throw DocumentException::notEditable();
        }
        $this->documents->update($document, $data);

        return $this->reload($document);
    }

    /** @throws DocumentException not_editable | invalid_file | file_too_large */
    public function attachFile(Document $document, string $content, string $filename): Document
    {
        if (! $document->status->isEditable()) {
            throw DocumentException::notEditable();
        }
        $path = $this->storage->put($document, $content, $filename);
        $this->documents->update($document, ['file_path' => $path]);
        $this->log->info('documents.file_attached', ['id' => $document->id, 'size' => strlen($content)]);

        return $this->reload($document);
    }

    public function file(Document $document): ?StoredFile
    {
        return $document->file_path === null ? null : $this->storage->get($document);
    }

    /**
     * Sends for acknowledgement: status sent + task "acknowledge" for the employee's login (once per document).
     *
     * @throws DocumentException not_editable | empty_document | employee_has_no_login
     */
    public function send(Document $document, ?Carbon $now = null): Document
    {
        $now ??= Carbon::now();
        if (! $document->status->isEditable()) {
            throw DocumentException::notEditable();
        }
        if (trim((string) $document->content_md) === '' && $document->file_path === null) {
            throw DocumentException::empty();
        }
        $userId = $document->employee->user_id;
        if ($userId === null) {
            throw DocumentException::noLogin();
        }
        $this->documents->transaction(function () use ($document, $userId, $now): void {
            $this->documents->update($document, ['status' => DocumentStatus::Sent->value, 'sent_at' => $now, 'reject_reason' => null]);
            $task = $this->tasks->schedule(new NewTask(
                assigneeId: $userId,
                type: TaskType::Document,
                title: $document->title,
                dueAt: $now->copy()->addDays(3),
                ruleKey: self::taskRule($document->id),
                employeeId: $document->employee_id,
                link: self::MY_DOCUMENTS_LINK,
            ));
            // Sent again after a rejection: the same task is reopened.
            $this->tasks->setDone($task, false);
        });
        $this->log->info('documents.sent', ['id' => $document->id]);

        return $this->reload($document);
    }

    /**
     * "Ознайомлений" by the employee themself. IP and user agent are kept as keyed hashes (HMAC with APP_KEY).
     *
     * @throws DocumentException not_sent | already_signed
     */
    public function acknowledge(User $actor, PeopleContext $ctx, Document $document, ?string $ip, ?string $userAgent, ?Carbon $now = null): Document
    {
        $this->assertOwnSent($ctx, $document);
        $now ??= Carbon::now();
        $this->documents->transaction(function () use ($actor, $document, $ip, $userAgent, $now): void {
            $signature = $this->documents->addSignatureOnce([
                'document_id' => $document->id,
                'signer_employee_id' => $document->employee_id,
                'signer_user_id' => $actor->id,
                'method' => SignatureMethod::ManualAck->value,
                'signed_at' => $now,
                'ip_hash' => self::hashOf($ip),
                'user_agent_hash' => self::hashOf($userAgent),
            ]);
            if ($signature === null) {
                throw DocumentException::alreadySigned();
            }
            $this->documents->update($document, ['status' => DocumentStatus::Signed->value]);
        });
        $this->closeTask($document, $now);
        $this->log->info('documents.acknowledged', ['id' => $document->id, 'by' => $actor->id]);

        return $this->reload($document);
    }

    /** @throws DocumentException not_sent */
    public function reject(PeopleContext $ctx, Document $document, ?string $reason): Document
    {
        $this->assertOwnSent($ctx, $document);
        $this->documents->update($document, ['status' => DocumentStatus::Rejected->value, 'reject_reason' => $reason]);
        $this->closeTask($document);
        $this->log->info('documents.rejected', ['id' => $document->id]);

        return $this->reload($document);
    }

    public static function hashOf(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function assertOwnSent(PeopleContext $ctx, Document $document): void
    {
        if (! $ctx->isSelf($document->employee_id)) {
            throw (new ModelNotFoundException)->setModel(Document::class, [$document->id]);
        }
        if ($document->status === DocumentStatus::Signed) {
            throw DocumentException::alreadySigned();
        }
        if ($document->status !== DocumentStatus::Sent) {
            throw DocumentException::notSent();
        }
    }

    private function closeTask(Document $document, ?Carbon $at = null): void
    {
        $this->tasks->closeByRule($document->employee_id, self::taskRule($document->id), $at);
    }

    private function reload(Document $document): Document
    {
        return $this->documents->find($document->id) ?? $document;
    }
}
