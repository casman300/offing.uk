<?php

namespace App\Support\Push;

final class PushStatus
{
    public function __construct(
        /** VAPID keys are in .env — the admin intends push to work here. */
        public readonly bool $configured,
        /** Configured and able to send. */
        public readonly bool $ready,
        public readonly ?string $reason = null,
    ) {}
}
