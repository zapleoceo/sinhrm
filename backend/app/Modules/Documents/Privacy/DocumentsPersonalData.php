<?php

declare(strict_types=1);

namespace App\Modules\Documents\Privacy;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Documents' share of an employee's data. Export lists documents as metadata (title, status, dates, file name,
 * type and size — not the file itself). Erase wipes text and files of documents that were never signed (draft, sent,
 * rejected); signed and archived documents are personnel records kept for the period set by law and stay as is.
 * Candidates have no documents here.
 */
final readonly class DocumentsPersonalData implements PersonalDataProvider
{
    public function section(): string
    {
        return 'documents';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        return null;
    }

    public function export(DataSubject $subject): array
    {
        return $this->documents($subject)->with('file')->orderBy('id')->get()->map(static fn (Document $d): array => [
            'title' => $d->title,
            'category' => $d->category,
            'status' => $d->status->value,
            'created_at' => $d->created_at?->toIso8601String(),
            'sent_at' => $d->sent_at?->toIso8601String(),
            'file' => $d->file === null ? null : ['filename' => $d->file->filename, 'mime' => $d->file->mime, 'size' => $d->file->size],
        ])->all();
    }

    public function erase(DataSubject $subject): array
    {
        $unsigned = $this->documents($subject)
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Sent->value, DocumentStatus::Rejected->value]);
        $ids = $unsigned->pluck('id')->all();

        return [
            'files' => DocumentFile::query()->whereIn('document_id', $ids)->delete(),
            'documents' => Document::query()->whereKey($ids)
                ->update(['title' => 'Видалений документ', 'content_md' => null, 'file_path' => null, 'reject_reason' => null]),
        ];
    }

    /** @return Builder<Document> */
    private function documents(DataSubject $subject): Builder
    {
        return $subject->type === DataSubjectType::Employee
            ? Document::query()->where('employee_id', $subject->id)
            : Document::query()->whereRaw('1 = 0');
    }
}
