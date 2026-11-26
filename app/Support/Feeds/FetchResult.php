<?php

namespace App\Support\Feeds;

/** What a fetch got: a body (and its ETag), or "not modified" (304). */
final class FetchResult
{
    private function __construct(
        public readonly bool $notModified,
        public readonly string $body = '',
        public readonly ?string $etag = null,
    ) {}

    public static function ok(string $body, ?string $etag): self
    {
        return new self(false, $body, $etag);
    }

    public static function notModified(): self
    {
        return new self(true);
    }
}
