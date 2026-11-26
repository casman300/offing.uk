<?php

namespace App\Support\Feeds;

/** The real resolver: A and AAAA records from the system's DNS. */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA);

        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
