<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\DTO\DocumentFilter;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\Signature;
use Illuminate\Database\Eloquent\Collection;

interface DocumentRepository
{
    /**
     * Newest first, at most $limit, with employee, file metadata and signatures.
     *
     * @return Collection<int, Document>
     */
    public function list(DocumentFilter $filter, int $limit): Collection;

    public function find(int $id): ?Document;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Document;

    /** @param  array<string, mixed>  $attributes */
    public function update(Document $document, array $attributes): Document;

    /**
     * Inserts the signature unless this user already signed the document (unique index — safe under races).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addSignatureOnce(array $attributes): ?Signature;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
