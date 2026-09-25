<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

use App\Modules\Directory\Enums\UpsertOutcome;

/** Per-dictionary import counters. skipped = unchanged rows + rows the mapper could not read. */
final class ImportCounts
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public function add(UpsertOutcome $outcome): void
    {
        match ($outcome) {
            UpsertOutcome::Created => $this->created++,
            UpsertOutcome::Updated => $this->updated++,
            UpsertOutcome::Unchanged => $this->skipped++,
        };
    }

    /** @return array{created: int, updated: int, skipped: int} */
    public function toArray(): array
    {
        return ['created' => $this->created, 'updated' => $this->updated, 'skipped' => $this->skipped];
    }
}
