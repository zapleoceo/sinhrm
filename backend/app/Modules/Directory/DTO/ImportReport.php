<?php

declare(strict_types=1);

namespace App\Modules\Directory\DTO;

use App\Modules\Directory\Enums\DictionaryType;

final class ImportReport
{
    /** @var array<string, ImportCounts> */
    private array $counts = [];

    public function for(DictionaryType $type): ImportCounts
    {
        return $this->counts[$type->value] ??= new ImportCounts;
    }

    /** @return array<string, array{created: int, updated: int, skipped: int}> keyed by dictionary, plus "total" */
    public function toArray(): array
    {
        $total = new ImportCounts;
        $result = [];
        foreach (DictionaryType::importOrder() as $type) {
            $counts = $this->for($type);
            $result[$type->value] = $counts->toArray();
            $total->created += $counts->created;
            $total->updated += $counts->updated;
            $total->skipped += $counts->skipped;
        }
        $result['total'] = $total->toArray();

        return $result;
    }
}
