<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\DTO\StoredFile;
use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Models\Document;

/**
 * Where attached files live. Today: DatabaseDocumentStorage (small files in documents_files). An object storage
 * (e.g. Vercel Blob) is a second implementation bound in DocumentsServiceProvider — callers do not change.
 */
interface DocumentStorage
{
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** Detected (not client-declared) MIME types that may be stored. */
    public const array ALLOWED_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Stores (or replaces) the document's file; returns the reference for documents.file_path.
     *
     * @throws DocumentException invalid_file (type not allowed) | file_too_large
     */
    public function put(Document $document, string $content, string $filename): string;

    public function get(Document $document): ?StoredFile;

    public function delete(Document $document): void;
}
