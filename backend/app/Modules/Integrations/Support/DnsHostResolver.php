<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Modules\Integrations\Contracts\HostResolver;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host);
        $ips = $ips === false ? [] : $ips;

        $v6 = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($v6) ? $v6 : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
