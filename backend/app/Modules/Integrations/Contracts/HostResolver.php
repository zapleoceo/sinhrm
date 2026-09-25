<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

/** DNS lookup behind an interface so the SSRF guard can be tested without real DNS. */
interface HostResolver
{
    /** @return list<string> IP addresses of the host; empty when it does not resolve */
    public function resolve(string $host): array;
}
