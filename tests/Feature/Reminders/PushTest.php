<?php

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\Person;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\Push\PushResult;
use App\Support\Push\PushSender;
use App\Support\Push\PushStatus;
use App\Support\Push\WebPushSender;
use App\Support\Reminders\OutboxDrainer;
use App\Support\Reminders\ReminderPayload;
use App\Support\Reminders\ReminderScanner;
use Carbon\CarbonImmutable;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Minishlink\WebPush\VAPID;
use Psr\Http\Message\RequestInterface;

/** A browser-style push subscription with a real P-256 key, as the PushManager would give us. */
function browserSubscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = openssl_pkey_get_details($key)['ec'];
    $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $b64("\x04".$ec['x'].$ec['y']), 'auth' => $b64(random_bytes(16))]];
}

/** A PushSender test double: records sends, answers with a scripted result. */
class FakePushSender implements PushSender
{
    /** @var list<array{devices: Collection, payload: array}> */
    public array $sent = [];

    public function __construct(public PushStatus $status, public ?PushResult $result = null) {}

    public function status(): PushStatus
    {
        return $this->status;
    }

    public function send(Collection $devices, array $payload): PushResult
    {
        $this->sent[] = ['devices' => $devices, 'payload' => $payload];

        return $this->result ?? new PushResult(accepted: $devices->count());
    }
}

function fakePush(bool $configured = true, bool $ready = true, ?PushResult $result = null, ?string $reason = null): FakePushSender
{
    $fake = new FakePushSender(new PushStatus($configured, $ready, $reason), $result);
    app()->instance(PushSender::class, $fake);

    return $fake;
}

function pushUser(string $delivery = User::DELIVERY_PUSH, int $devices = 1): User
{
    $user = User::factory()->create();
    Feature::for($user)->activate([BirthdayEngine::class, Reminders::class]);
    $user->reminderSlots();
    $user->forceFill(['reminders_enabled_at' => CarbonImmutable::parse('2026-01-01'), 'reminder_delivery' => $delivery])->save();

    for ($i = 0; $i < $devices; $i++) {
        $sub = browserSubscription("https://web.push.apple.com/device-{$user->id}-{$i}");
        $user->devices()->create(['name' => "iPhone {$i}", 'endpoint_hash' => UserDevice::hashEndpoint($sub['endpoint']), 'push_subscription' => $sub]);
    }

    Person::factory()->for($user, 'owner')->birthday(17, 10, 1983)->create(['name' => 'Auntie Sue']);

    return $user->fresh();
}

/** Queue the 20:00 day-before reminder for 17 Oct and run the drainer at $minutesLater. */
function runReminder(int $minutesLater = 0): array
{
    $due = CarbonImmutable::parse('2026-10-16 20:00', 'Europe/London')->utc();
    test()->travelTo($due);
    (new ReminderScanner)->scan($due);
    test()->travelTo($due->addMinutes($minutesLater));

    return (new OutboxDrainer)->drain($due->addMinutes($minutesLater));
}

function sentMails(): array
{
    return iterator_to_array(app('mailer')->getSymfonyTransport()->messages());
}

beforeEach(function () {
    config(['mail.default' => 'array']);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
});

describe('WebPushSender (real encryption, mocked push service)', function () {
    function realSender(array &$requests, array $statusByEndpoint = []): WebPushSender
    {
        $keys = VAPID::createVapidKeys();
        $handler = HandlerStack::create(function (RequestInterface $request) use (&$requests, $statusByEndpoint) {
            $requests[] = $request;
            $status = $statusByEndpoint[(string) $request->getUri()] ?? 201;

            return Create::promiseFor(new Response($status, [], $status >= 400 ? 'nope' : ''));
        });

        return new WebPushSender($keys['publicKey'], $keys['privateKey'], 'mailto:test@example.com', ['handler' => $handler]);
    }

    it('reports readiness from the keys', function () {
        $requests = [];

        expect((new WebPushSender(null, null, 'mailto:x@example.com'))->status())->configured->toBeFalse()->ready->toBeFalse()
            ->and((new WebPushSender('bad', 'keys', 'mailto:x@example.com'))->status())->configured->toBeTrue()->ready->toBeFalse()
            ->and(realSender($requests)->status()->ready)->toBeTrue();
    });

    it('encrypts and sends to each device, prunes gone ones, counts failures', function () {
        $user = User::factory()->create();
        $make = fn (string $endpoint) => $user->devices()->create([
            'name' => 'x', 'endpoint_hash' => UserDevice::hashEndpoint($endpoint), 'push_subscription' => browserSubscription($endpoint),
        ]);
        $ok = $make('https://web.push.apple.com/ok');
        $gone = $make('https://web.push.apple.com/gone');
        $make('https://web.push.apple.com/down');

        $requests = [];
        $result = realSender($requests, ['https://web.push.apple.com/gone' => 410, 'https://web.push.apple.com/down' => 503])
            ->send($user->devices()->get(), ['title' => 'Tomorrow: Sue turns 43', 'body' => 'Saturday 17 October', 'url' => '/people/1', 'tag' => 'reminder-2026-10-17-1']);

        expect($result)->accepted->toBe(1)->gone->toBe(1)->failed->toBe(1)
            ->and(UserDevice::find($gone->id))->toBeNull()
            ->and($ok->fresh()->last_push_at)->not->toBeNull()
            ->and($requests)->toHaveCount(3);

        $request = $requests[0];
        expect($request->getMethod())->toBe('POST')
            ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
            ->and($request->getHeaderLine('TTL'))->toBe((string) WebPushSender::TTL)
            ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid t=')
            ->and(strlen((string) $request->getBody()))->toBeGreaterThan(100)
            // Encrypted: the plaintext never appears on the wire.
            ->and((string) $request->getBody())->not->toContain('Sue');
    });
});

describe('payload', function () {
    it('names one person, or the first and a count', function () {
        $sue = Person::factory()->make(['id' => 1, 'name' => 'Auntie Sue']);
        $tom = Person::factory()->make(['id' => 2, 'name' => 'Tom']);
        $date = CarbonImmutable::parse('2026-10-17');

        $one = ReminderPayload::push([['person' => $sue, 'date' => $date, 'turns' => 43]], 1);
        $two = ReminderPayload::push([['person' => $sue, 'date' => $date, 'turns' => 43], ['person' => $tom, 'date' => $date, 'turns' => null]], 0);

        expect($one)->toBe(['title' => 'Tomorrow: Auntie Sue turns 43', 'body' => 'Saturday 17 October', 'url' => '/people/1', 'tag' => 'reminder-2026-10-17-1'])
            ->and($two['title'])->toBe('Today: Auntie Sue and 1 other')
            ->and($two['body'])->toBe('Auntie Sue (43), Tom')
            ->and($two['url'])->toBe('/people');
    });
});

describe('delivery', function () {
    it('pushes instead of emailing when a device accepts', function () {
        $push = fakePush();
        pushUser();

        expect(runReminder()['sent'])->toBe(1)
            ->and(sentMails())->toHaveCount(0)
            ->and($push->sent[0]['payload']['title'])->toBe('Tomorrow: Auntie Sue turns 43')
            ->and(OutboxMessage::sole()->delivered_via)->toBe('push');
    });

    it('falls back to email when there are no devices', function () {
        fakePush();
        pushUser(devices: 0);

        runReminder();

        expect(sentMails())->toHaveCount(1)
            ->and(OutboxMessage::sole()->only(['delivered_via', 'last_error']))->toBe(['delivered_via' => 'email (push fallback)', 'last_error' => 'No devices with notifications on.']);
    });

    it('falls back to email when push isn’t configured or isn’t working on the host', function () {
        fakePush(configured: false, ready: false);
        pushUser();
        runReminder();
        expect(OutboxMessage::sole()->only(['delivered_via', 'last_error']))->toBe(['delivered_via' => 'email (push fallback)', 'last_error' => null]);

        OutboxMessage::query()->delete();
        fakePush(ready: false, reason: 'PHP extension curl is missing.');
        runReminder(1);
        expect(OutboxMessage::sole()->last_error)->toBe('Push unavailable: PHP extension curl is missing.');
    });

    it('falls back to email when every subscription has expired', function () {
        fakePush(result: new PushResult(gone: 1));
        pushUser();

        runReminder();

        expect(sentMails())->toHaveCount(1)
            ->and(OutboxMessage::sole()->last_error)->toContain('expired');
    });

    it('retries a failing push service twice, then emails', function () {
        $push = fakePush(result: new PushResult(failed: 1, errors: ['503 Service Unavailable']));
        pushUser();

        expect(runReminder()['retrying'])->toBe(1);
        expect(OutboxMessage::sole()->last_error)->toBe('Push failed: 503 Service Unavailable');

        $due = CarbonImmutable::parse('2026-10-16 20:00', 'Europe/London')->utc();
        expect((new OutboxDrainer)->drain($due->addMinutes(5))['retrying'])->toBe(1)
            ->and((new OutboxDrainer)->drain($due->addMinutes(20))['sent'])->toBe(1)
            ->and(count($push->sent))->toBe(3)
            ->and(sentMails())->toHaveCount(1)
            ->and(OutboxMessage::sole()->delivered_via)->toBe('email (push fallback)');
    });

    it('sends both when asked, and doesn’t re-push if only the email fails', function () {
        $push = fakePush();
        pushUser(User::DELIVERY_BOTH);

        runReminder();
        expect(sentMails())->toHaveCount(1)
            ->and(OutboxMessage::sole()->delivered_via)->toBe('push + email');

        OutboxMessage::query()->delete();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
        expect(runReminder(1)['sent'])->toBe(1)
            ->and(OutboxMessage::sole()->only(['delivered_via', 'last_error']))->toBe(['delivered_via' => 'push', 'last_error' => 'Email failed: RuntimeException'])
            ->and(count($push->sent))->toBe(2);
    });

    it('leaves email-only users alone', function () {
        $push = fakePush();
        pushUser(User::DELIVERY_EMAIL);

        runReminder();

        expect($push->sent)->toBe([])
            ->and(OutboxMessage::sole()->delivered_via)->toBe('email');
    });
});

describe('devices', function () {
    function deviceUser(): User
    {
        $user = User::factory()->create();
        Feature::for($user)->activate([BirthdayEngine::class, Reminders::class]);

        return $user;
    }

    it('adds this device, names it, switches delivery to push on the first one, audited', function () {
        fakePush();
        $user = deviceUser();
        $sub = browserSubscription('https://web.push.apple.com/QWERTY');

        $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1')
            ->post('/settings/reminders/devices', ['subscription' => $sub])
            ->assertSessionHasNoErrors();

        $device = $user->devices()->sole();
        expect($device->name)->toBe('iPhone')
            ->and($device->push_subscription['endpoint'])->toBe($sub['endpoint'])
            ->and($user->refresh()->reminder_delivery)->toBe('push')
            ->and(AuditLog::where('action', 'device.added')->exists())->toBeTrue()
            ->and(AuditLog::where('action', 'reminders.delivery_changed')->sole()->meta)->toBe(['to' => 'push', 'via' => 'first_device']);

        // Re-subscribing the same browser updates rather than duplicates.
        $this->actingAs($user)->post('/settings/reminders/devices', ['subscription' => $sub]);
        expect($user->devices()->count())->toBe(1);
    });

    it('only accepts real push services', function (string $endpoint) {
        fakePush();
        $user = deviceUser();

        $this->actingAs($user)->post('/settings/reminders/devices', ['subscription' => browserSubscription($endpoint)])
            ->assertSessionHasErrors();

        expect($user->devices()->count())->toBe(0);
    })->with([
        'internal host' => ['https://169.254.169.254/latest'],
        'look-alike' => ['https://web.push.apple.com.evil.example/x'],
        'plain http' => ['http://fcm.googleapis.com/fcm/send/x'],
    ]);

    it('moves a device to whoever subscribes it last (shared browser)', function () {
        fakePush();
        $sub = browserSubscription();
        $a = deviceUser();
        $b = deviceUser();

        $this->actingAs($a)->post('/settings/reminders/devices', ['subscription' => $sub]);
        $this->actingAs($b)->post('/settings/reminders/devices', ['subscription' => $sub]);

        expect($a->devices()->count())->toBe(0)->and($b->devices()->count())->toBe(1);
    });

    it('caps devices per user', function () {
        fakePush();
        $user = deviceUser();

        foreach (range(1, UserDevice::MAX_PER_USER) as $i) {
            $sub = browserSubscription("https://fcm.googleapis.com/fcm/send/{$i}");
            $user->devices()->create(['name' => "d{$i}", 'endpoint_hash' => UserDevice::hashEndpoint($sub['endpoint']), 'push_subscription' => $sub]);
        }
        $this->actingAs($user)->post('/settings/reminders/devices', ['subscription' => browserSubscription('https://fcm.googleapis.com/fcm/send/one-too-many')])
            ->assertSessionHasErrors('subscription');

        expect($user->devices()->count())->toBe(UserDevice::MAX_PER_USER);
    });

    it('404s when this host can’t send push', function () {
        fakePush(configured: false, ready: false);

        $this->actingAs(deviceUser())->post('/settings/reminders/devices', ['subscription' => browserSubscription()])->assertNotFound();
    });

    it('removes and tests only your own devices', function () {
        $push = fakePush();
        $user = pushUser();
        $theirs = pushUser();
        $mine = $user->devices()->sole();

        $this->actingAs($user)->post("/settings/reminders/devices/{$mine->id}/test")->assertSessionHas('status', 'device-test-sent');
        expect($push->sent[0]['payload']['title'])->toBe('Offing notifications are working');

        $this->actingAs($user)->delete('/settings/reminders/devices/'.$theirs->devices()->sole()->id)->assertNotFound();
        $this->actingAs($user)->delete("/settings/reminders/devices/{$mine->id}")->assertRedirect();

        expect(UserDevice::find($mine->id))->toBeNull()
            ->and(AuditLog::where('action', 'device.removed')->exists())->toBeTrue()
            ->and(AuditLog::where('action', 'push.test_sent')->exists())->toBeTrue();
    });

    it('explains an expired device on test', function () {
        fakePush(result: new PushResult(gone: 1));
        $user = pushUser();

        $this->actingAs($user)->post('/settings/reminders/devices/'.$user->devices()->sole()->id.'/test')
            ->assertSessionHasErrors(['device' => 'That device’s subscription has expired, so it was removed. Turn notifications on again from that device.']);
    });
});

describe('settings and admin', function () {
    it('hides push until the host is ready, then shows devices and the delivery choice', function () {
        fakePush(configured: false, ready: false);
        $user = pushUser();
        $this->actingAs($user)->get('/settings/reminders')->assertInertia(fn (Assert $page) => $page->where('push', null));

        fakePush();
        config(['offing.vapid.public_key' => 'PUBLIC']);
        $this->actingAs($user)->get('/settings/reminders')->assertInertia(fn (Assert $page) => $page
            ->where('push.publicKey', 'PUBLIC')
            ->where('push.delivery', 'push')
            ->where('push.devices.0.name', 'iPhone 0')
            ->missing('push.devices.0.push_subscription'));
    });

    it('saves the delivery choice, audited', function () {
        fakePush();
        $user = pushUser();

        $this->actingAs($user)->patch('/settings/reminders', ['enabled' => true, 'slots' => [], 'delivery' => 'both'])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch('/settings/reminders', ['enabled' => true, 'slots' => [], 'delivery' => 'carrier-pigeon'])->assertSessionHasErrors('delivery');

        expect($user->refresh()->reminder_delivery)->toBe('both')
            ->and(AuditLog::where('action', 'reminders.delivery_changed')->sole()->meta)->toBe(['to' => 'both', 'via' => 'settings']);
    });

    it('warns admins when push is configured but broken', function () {
        config(['offing.admin_emails' => ['boss@example.com']]);
        fakePush(ready: false, reason: 'PHP extension curl is missing.');

        $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->get('/admin/outbox')
            ->assertInertia(fn (Assert $page) => $page->where('pushProblem', 'PHP extension curl is missing.'));
    });
});

describe('commands and install', function () {
    it('prints VAPID keys and reports readiness', function () {
        $this->artisan('push:keys')->expectsOutputToContain('VAPID_PUBLIC_KEY=')->assertSuccessful();

        fakePush(configured: false, ready: false, reason: 'VAPID keys are not set in .env.');
        $this->artisan('push:check')->expectsOutputToContain('Configured: no')->assertSuccessful();

        fakePush(ready: false, reason: 'PHP extension curl is missing.');
        $this->artisan('push:check')->expectsOutputToContain('curl is missing')->assertFailed();
    });

    it('links the web app manifest and ships the push worker and icons', function () {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertSee('<link rel="manifest" href="/build/manifest.webmanifest">', false)
            ->assertSee('apple-touch-icon', false);

        foreach (['push-sw.js', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/apple-touch-icon.png', 'icons/badge-96.png'] as $file) {
            expect(public_path($file))->toBeFile();
        }
    });
});
