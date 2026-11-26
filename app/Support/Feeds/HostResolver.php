<?php

namespace App\Support\Feeds;

/**
 * Looks up a hostname's addresses. An interface so tests can say where a
 * name "resolves" without touching DNS (the SSRF checks run on the answer).
 */
interface HostResolver
{
    /**
     * @return list<string> every IPv4/IPv6 address the name resolves to; empty when it doesn't
     */
    public function resolve(string $host): array;
}
