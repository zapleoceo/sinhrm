<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** The "History" tab of a record in another module (employee, candidate). Implemented by Audit\Services\AuditService. */
interface AuditHistory
{
    /**
     * Entries of the given records, newest first.
     *
     * @param  array<string, list<int>>  $entities  entity type → ids
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function history(array $entities, int $page = 1, int $perPage = 20): LengthAwarePaginator;
}
