<?php

namespace App\Support\Push;

final class PushResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        /** Push services that accepted the message (we can't know it was seen). */
        public readonly int $accepted = 0,
        /** Subscriptions reported gone and deleted. */
        public readonly int $gone = 0,
        /** Other failures (5xx, network, bad request) — worth a retry. */
        public readonly int $failed = 0,
        public readonly array $errors = [],
    ) {}
}
