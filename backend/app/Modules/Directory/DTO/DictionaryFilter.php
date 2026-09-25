<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

use App\Modules\Directory\Enums\DirectoryStatus;

final readonly class DictionaryFilter
{
    public function __construct(
        public ?string $q = null,
        public ?DirectoryStatus $status = null,
        public int $perPage = 50,
    ) {}
}
