<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

use App\Modules\Directory\Enums\DirectoryStatus;

/**
 * Fields of a manual create/update. Null = not sent (unchanged). For city_id, $cityIdSent tells
 * "not sent" apart from "sent null" (detach the city).
 */
final readonly class DictionaryItemData
{
    public function __construct(
        public ?string $name = null,
        public ?DirectoryStatus $status = null,
        public ?int $cityId = null,
        public bool $cityIdSent = false,
    ) {}

    /** @return array<string, mixed> only the fields that were sent */
    public function attributes(): array
    {
        $attributes = [];
        if ($this->name !== null) {
            $attributes['name'] = $this->name;
        }
        if ($this->status !== null) {
            $attributes['status'] = $this->status;
        }
        if ($this->cityIdSent) {
            $attributes['city_id'] = $this->cityId;
        }

        return $attributes;
    }
}
