<?php

declare(strict_types=1);

namespace App\Modules\Directory\Enums;

/**
 * Sortable columns of a dictionary (GET /api/directory/{type}?sort=…). City exists on branches only (other
 * dictionaries answer 422 to it). A closed list mapped to ORDER BY in the repository.
 */
enum DictionarySort: string
{
    case Name = 'name';
    case City = 'city';
    case Status = 'status';
}
