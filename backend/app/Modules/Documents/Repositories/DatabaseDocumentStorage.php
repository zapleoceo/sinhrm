<?php

declare(strict_types=1);

namespace App\Modules\Documents\Repositories;

use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\DTO\StoredFile;
use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFile;
use finfo;

/**
 * Files in the documents_files table (base64). Enforces the limits itself (not only the FormRequest): size ≤ 2 MB
 * and a whitelist of types detected from the bytes (finfo), so a renamed file does not pass as a PDF.
 */
final class DatabaseDocumentStorage implements DocumentStorage
{
    public const string PREFIX = 'db:';

    private const string DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function put(Document $document, string $content, string $filename): string
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw DocumentException::fileTooLarge();
        }
        $mime = self::detect($content, $filename);
        if ($mime === null) {
            throw DocumentException::invalidFile();
        }

        $file = DocumentFile::query()->updateOrCreate(['document_id' => $document->id], [
            'filename' => self::safeName($filename),
            'mime' => $mime,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'content' => base64_encode($content),
        ]);

        return self::PREFIX.$file->id;
    }

    public function get(Document $document): ?StoredFile
    {
        $file = DocumentFile::query()->where('document_id', $document->id)->first();
        if ($file === null) {
            return null;
        }
        $content = base64_decode($file->content, true);

        return $content === false ? null : new StoredFile($file->filename, $file->mime, $file->size, $content);
    }

    public function delete(Document $document): void
    {
        DocumentFile::query()->where('document_id', $document->id)->delete();
    }

    /** MIME from the bytes; a .docx is a ZIP container, so libmagic may report it as zip/octet-stream. */
    public static function detect(string $content, string $filename): ?string
    {
        if ($content === '') {
            return null;
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $isDocxName = str_ends_with(mb_strtolower($filename), '.docx');
        if ($isDocxName && in_array($mime, [self::DOCX, 'application/zip', 'application/octet-stream'], true)
            && str_starts_with($content, "PK\x03\x04")) {
            return self::DOCX;
        }

        return in_array($mime, self::ALLOWED_MIMES, true) && $mime !== self::DOCX ? $mime : null;
    }

    /** Keeps a readable base name for the download header; no paths, no control characters. */
    public static function safeName(string $filename): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename(str_replace('\\', '/', $filename)));

        return mb_substr($name === '' ? 'file' : $name, 0, 200);
    }
}
