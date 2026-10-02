<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

use App\Modules\Directory\Enums\DictionarySort;
use App\Modules\Directory\Enums\DirectoryStatus;

/** Dictionary list: q = name contains; cityId — branches only; sort/descending order the page. */
final readonly class DictionaryFilter
{
    public function __construct(
        public ?string $q = null,
        public ?DirectoryStatus $status = null,
        public int $perPage = 50,
        public ?int $cityId = null,
        public DictionarySort $sort = DictionarySort::Name,
        public bool $descending = false,
    ) {}
}
