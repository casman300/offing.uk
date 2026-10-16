<?php

namespace App\Support\Push;

use App\Models\UserDevice;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push via minishlink/web-push (RFC 8030/8291/8292). Messages expire
 * after TTL seconds, so a phone that's off overnight doesn't get
 * yesterday's "tomorrow" reminder the next afternoon.
 */
final class WebPushSender implements PushSender
{
    public const TTL = 2 * 60 * 60;

    /**
     * @param  array<string, mixed>  $clientOptions  Guzzle options (tests pass a mock handler)
     */
    public function __construct(
        private readonly ?string $publicKey,
        private readonly ?string $privateKey,
        private readonly string $subject,
        private readonly array $clientOptions = [],
    ) {}

    public function status(): PushStatus
    {
        if (blank($this->publicKey) || blank($this->privateKey)) {
            return new PushStatus(false, false, 'VAPID keys are not set in .env.');
        }

        foreach (['openssl', 'curl', 'mbstring', 'json'] as $extension) {
            if (! extension_loaded($extension)) {
                return new PushStatus(true, false, "PHP extension {$extension} is missing.");
            }
        }

        try {
            VAPID::validate($this->auth()['VAPID']);
        } catch (Throwable $e) {
            return new PushStatus(true, false, 'VAPID keys are invalid: '.$e->getMessage());
        }

        return new PushStatus(true, true);
    }

    public function send(Collection $devices, array $payload): PushResult
    {
        if ($devices->isEmpty()) {
            return new PushResult;
        }

        $webPush = $this->client(['TTL' => self::TTL, 'urgency' => 'normal', 'topic' => Str::limit(preg_replace('/[^A-Za-z0-9_-]/', '', $payload['tag']), 32, '')]);
        $byEndpoint = $devices->keyBy(fn (UserDevice $d) => $d->push_subscription['endpoint']);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($devices as $device) {
            // Always RFC 8291 aes128gcm: the library's default for a subscription
            // without contentEncoding is legacy aesgcm, which Apple rejects.
            $webPush->queueNotification(Subscription::create([...$device->push_subscription, 'contentEncoding' => 'aes128gcm']), $json);
        }

        $accepted = $gone = $failed = 0;
        $errors = [];

        foreach ($webPush->flush() as $report) {
            $device = $byEndpoint->get($report->getEndpoint());

            if ($report->isSuccess()) {
                $accepted++;
                $device?->forceFill(['last_push_at' => now()])->save();
            } elseif ($report->isSubscriptionExpired()) {
                $gone++;
                $device?->delete();
            } else {
                $failed++;
                $errors[] = Str::limit($report->getReason(), 300);
            }
        }

        return new PushResult($accepted, $gone, $failed, $errors);
    }

    /**
     * The library raises an E_USER_NOTICE when neither gmp nor bcmath is
     * loaded (it then falls back to slower pure-PHP maths). Laravel turns
     * notices into exceptions, which would make every push fail on a host
     * without them — so let just that notice through quietly.
     *
     * @param  array<string, mixed>  $options
     */
    private function client(array $options): WebPush
    {
        set_error_handler(fn (int $no, string $message) => $no === E_USER_NOTICE && str_contains($message, 'GMP or BCMath'), E_USER_NOTICE);

        try {
            return new WebPush($this->auth(), $options, 10, $this->clientOptions);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{VAPID: array{subject: string, publicKey: ?string, privateKey: ?string}}
     */
    private function auth(): array
    {
        return ['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]];
    }
}
