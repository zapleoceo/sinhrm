<?php

declare(strict_types=1);

namespace App\Modules\Documents\Repositories;

use App\Modules\Documents\Contracts\DocumentRepository;
use App\Modules\Documents\DTO\DocumentFilter;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\Signature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentDocumentRepository implements DocumentRepository
{
    private const array RELATIONS = ['employee', 'file', 'signatures'];

    public function list(DocumentFilter $filter, int $limit): Collection
    {
        return $this->filtered($filter)
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function count(DocumentFilter $filter): int
    {
        return $this->filtered($filter)->count();
    }

    /** @return Builder<Document> */
    private function filtered(DocumentFilter $filter): Builder
    {
        return Document::query()
            ->when($filter->employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $filter->employeeIds ?? []))
            ->when($filter->employeeId, fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->when($filter->status, fn (Builder $q, DocumentStatus $s) => $q->where('status', $s->value))
            ->when($filter->category, fn (Builder $q, string $c) => $q->where('category', $c))
            ->when(! $filter->withDrafts, fn (Builder $q) => $q->where('status', '!=', DocumentStatus::Draft->value));
    }

    public function find(int $id): ?Document
    {
        return Document::query()->with(self::RELATIONS)->find($id);
    }

    public function create(array $attributes): Document
    {
        return Document::query()->create($attributes);
    }

    public function update(Document $document, array $attributes): Document
    {
        $document->fill($attributes)->save();

        return $document;
    }

    public function addSignatureOnce(array $attributes): ?Signature
    {
        $now = Carbon::now();
        $inserted = Signature::query()->insertOrIgnore([$attributes + ['created_at' => $now, 'updated_at' => $now]]) > 0;

        return $inserted
            ? Signature::query()->where('document_id', $attributes['document_id'])->where('signer_user_id', $attributes['signer_user_id'])->first()
            : null;
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
