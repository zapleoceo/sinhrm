<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

final readonly class MappedPayload
{
    /** @param  list<ImportedItem>  $items */
    public function __construct(
        public array $items,
        public int $invalid,
    ) {}
}
