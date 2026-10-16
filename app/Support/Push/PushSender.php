<?php

namespace App\Support\Push;

use App\Models\UserDevice;
use Illuminate\Support\Collection;

/**
 * Sends Web Push messages. Bound to WebPushSender; tests swap in a fake.
 */
interface PushSender
{
    /**
     * Whether this host can send push at all (keys set, extensions loaded).
     */
    public function status(): PushStatus;

    /**
     * Send one payload to each device. Devices whose subscription is gone
     * (404/410) are deleted; the result says how many accepted it.
     *
     * @param  Collection<int, UserDevice>  $devices
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     */
    public function send(Collection $devices, array $payload): PushResult;
}
