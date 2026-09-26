<?php

declare(strict_types=1);

namespace App\Modules\Documents\DTO;

/** A file read back from DocumentStorage: raw bytes plus what the download response needs. */
final readonly class StoredFile
{
    public function __construct(
        public string $filename,
        public string $mime,
        public int $size,
        public string $content,
    ) {}
}
