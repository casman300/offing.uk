<?php

namespace App\Http\Controllers\Reminders;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\Push\PushSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Devices that receive reminder notifications (Settings → Reminders).
 */
class DeviceController extends Controller
{
    /**
     * Only real push services: the server POSTs to whatever endpoint is
     * stored, so an arbitrary URL would let anyone aim it at any host.
     */
    public const PUSH_HOSTS = [
        'web.push.apple.com',
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
    ];

    public const PUSH_HOST_SUFFIXES = ['.push.apple.com', '.notify.windows.com'];

    public function store(Request $request, PushSender $push): RedirectResponse
    {
        abort_unless($push->status()->ready, 404);

        $data = $request->validate([
            'subscription.endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:200'],
            'subscription.keys.auth' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $endpoint = $data['subscription']['endpoint'];
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        if (! in_array($host, self::PUSH_HOSTS, true) && ! collect(self::PUSH_HOST_SUFFIXES)->contains(fn ($s) => str_ends_with($host, $s))) {
            throw ValidationException::withMessages(['subscription' => 'That isn’t a recognised push service.']);
        }

        $user = $request->user();
        $hash = UserDevice::hashEndpoint($endpoint);
        $existing = UserDevice::where('endpoint_hash', $hash)->first();

        // The same browser re-subscribing (or moving to another account on a shared device).
        if ($existing !== null && $existing->user_id !== $user->id) {
            $existing->delete();
            $existing = null;
        }

        if ($existing === null && $user->devices()->count() >= UserDevice::MAX_PER_USER) {
            throw ValidationException::withMessages(['subscription' => 'You have '.UserDevice::MAX_PER_USER.' devices already — remove one first.']);
        }

        $device = $user->devices()->updateOrCreate(['endpoint_hash' => $hash], [
            'name' => ($data['name'] ?? null) ?: UserDevice::nameFromUserAgent($request->userAgent()),
            'push_subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => $data['subscription']['keys']['p256dh'], 'auth' => $data['subscription']['keys']['auth']],
                'contentEncoding' => 'aes128gcm',
            ],
            'last_seen_at' => now(),
        ]);

        if ($device->wasRecentlyCreated) {
            AuditLog::record($user, 'device.added', $device, ['name' => $device->name], $request->ip());

            // First device: switch to push with email as backup (changeable).
            if (($user->reminder_delivery ?? User::DELIVERY_EMAIL) === User::DELIVERY_EMAIL && $user->devices()->count() === 1) {
                $user->forceFill(['reminder_delivery' => User::DELIVERY_PUSH])->save();
                AuditLog::record($user, 'reminders.delivery_changed', $user, ['to' => User::DELIVERY_PUSH, 'via' => 'first_device'], $request->ip());
            }
        }

        return back()->with('status', 'device-added');
    }

    public function destroy(Request $request, int $device): RedirectResponse
    {
        $model = $request->user()->devices()->findOrFail($device);
        $model->delete();

        AuditLog::record($request->user(), 'device.removed', $model, ['name' => $model->name], $request->ip());

        return back()->with('status', 'device-removed');
    }

    public function test(Request $request, int $device, PushSender $push): RedirectResponse
    {
        $model = $request->user()->devices()->findOrFail($device);

        $result = $push->send(collect([$model]), [
            'title' => 'Offing notifications are working',
            'body' => 'Birthday reminders will arrive like this on '.$model->name.'.',
            'url' => route('reminders.edit', [], false),
            'tag' => 'test-'.$model->id,
        ]);

        AuditLog::record($request->user(), 'push.test_sent', $model, ['accepted' => $result->accepted], $request->ip());

        if ($result->accepted > 0) {
            return back()->with('status', 'device-test-sent');
        }

        return back()->withErrors(['device' => $result->gone > 0
            ? 'That device’s subscription has expired, so it was removed. Turn notifications on again from that device.'
            : 'The push service didn’t accept it: '.($result->errors[0] ?? 'unknown error')]);
    }
}
