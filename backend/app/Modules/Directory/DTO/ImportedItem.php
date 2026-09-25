<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

use App\Modules\Directory\Enums\DirectoryStatus;

/** One dictionary item as read from Sintegrum (already validated by SintegrumPayloadMapper). */
final readonly class ImportedItem
{
    public function __construct(
        public string $externalId,
        public string $name,
        public DirectoryStatus $status,
        public ?string $cityExternalId = null,
    ) {}
}
