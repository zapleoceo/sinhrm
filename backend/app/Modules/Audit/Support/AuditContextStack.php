<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Contracts\AuditContext;

/** Singleton: the meta of the enclosing AuditContext::within() calls, inner values win. */
final class AuditContextStack implements AuditContext
{
    /** @var list<array<string, scalar|null>> */
    private array $stack = [];

    public function within(array $meta, callable $callback): mixed
    {
        $this->stack[] = $meta;
        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    /** @return array<string, scalar|null> */
    public function current(): array
    {
        return $this->stack === [] ? [] : array_merge(...$this->stack);
    }
}
