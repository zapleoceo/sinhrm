<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Integrations\Contracts\HostResolver;

/** Test double: resolves hosts from a map, anything else to a public documentation-free address. */
final class FakeHostResolver implements HostResolver
{
    /** @param  array<string, list<string>>  $map */
    public function __construct(private readonly array $map = [], private readonly string $default = '93.184.216.34') {}

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [$this->default];
    }
}
