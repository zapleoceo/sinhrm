<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/**
 * Shared context of the audit rows written while a callback runs (by the observer or by hand). Bulk endpoints wrap
 * each item: every object still gets its own row, and the row says it came from a bulk action (`meta.bulk`).
 * Implemented by Audit\Support\AuditContextStack (one per request/process).
 */
interface AuditContext
{
    /**
     * @template T
     *
     * @param  array<string, scalar|null>  $meta  non-personal context only (action name, ids)
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(array $meta, callable $callback): mixed;
}
