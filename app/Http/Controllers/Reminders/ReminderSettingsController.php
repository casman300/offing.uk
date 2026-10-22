<?php

namespace App\Http\Controllers\Reminders;

use App\Http\Controllers\Controller;
use App\Mail\BirthdayReminderMail;
use App\Models\AuditLog;
use App\Models\ReminderRule;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\Push\PushSender;
use App\Support\Reminders\BirthdaySource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings → Reminders (#18): master switch (off by default) plus the two
 * slots, each on/off with a time in 15-minute steps.
 */
class ReminderSettingsController extends Controller
{
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):(00|15|30|45)$/';

    public function edit(Request $request, PushSender $push): Response
    {
        $user = $request->user();
        $pushReady = $push->status()->ready;

        return Inertia::render('settings/reminders', [
            'enabled' => $user->remindersOn(),
            'timezone' => $user->timezone,
            'slots' => collect($user->reminderSlots())->map(fn (ReminderRule $rule) => [
                'enabled' => $rule->enabled,
                'time' => $rule->time(),
            ]),
            'status' => $request->session()->get('status'),
            // Push stays hidden until this host has VAPID keys (and can send).
            'push' => $pushReady ? [
                'publicKey' => config('offing.vapid.public_key'),
                'delivery' => $user->reminder_delivery,
                'devices' => $user->devices()->latest()->get()->map(fn (UserDevice $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'endpoint_hash' => $d->endpoint_hash,
                    'added_at' => $d->created_at?->toIso8601String(),
                    'last_push_at' => $d->last_push_at?->toIso8601String(),
                ]),
            ] : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'slots' => ['present', 'array'],
            'slots.*.enabled' => ['required', 'boolean'],
            'slots.*.time' => ['required', 'string', 'regex:'.self::TIME_PATTERN],
            'delivery' => ['sometimes', 'string', 'in:'.implode(',', [User::DELIVERY_EMAIL, User::DELIVERY_PUSH, User::DELIVERY_BOTH])],
        ], [
            'slots.*.time.regex' => 'Pick a time on the quarter hour.',
        ]);

        $user = $request->user();

        foreach ($user->reminderSlots() as $slot => $rule) {
            if (isset($data['slots'][$slot])) {
                $rule->update([
                    'enabled' => (bool) $data['slots'][$slot]['enabled'],
                    'offset_def' => [...$rule->offset_def, 'time' => $data['slots'][$slot]['time']],
                ]);
            }
        }

        if (isset($data['delivery']) && $data['delivery'] !== $user->reminder_delivery) {
            $user->forceFill(['reminder_delivery' => $data['delivery']])->save();
            AuditLog::record($user, 'reminders.delivery_changed', $user, ['to' => $data['delivery'], 'via' => 'settings'], $request->ip());
        }

        $wasOn = $user->remindersOn();

        if ($data['enabled'] !== $wasOn) {
            $user->forceFill(['reminders_enabled_at' => $data['enabled'] ? now() : null])->save();
            AuditLog::record($user, $data['enabled'] ? 'reminders.enabled' : 'reminders.disabled', $user, ['via' => 'settings'], $request->ip());
        }

        return to_route('reminders.edit')->with('status', 'reminders-saved');
    }

    /**
     * Send a sample now so you can check it arrives (and isn't in spam).
     */
    public function test(Request $request): RedirectResponse
    {
        $user = $request->user();
        $today = $user->today();
        $entries = array_values(collect((new BirthdaySource)->between($user, $today, $today->addYear()))
            ->sortBy(fn (array $e) => $e['date']->getTimestamp())
            ->take(3)
            ->all());

        if ($entries === []) {
            return back()->with('status', 'reminders-test-empty');
        }

        try {
            Mail::to($user->email)->send(new BirthdayReminderMail($user, $entries, 1, isTest: true));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['test' => 'The test email couldn’t be sent: '.class_basename($e).'. Check the mail settings in .env.']);
        }

        AuditLog::record($user, 'reminders.test_sent', $user, ['people' => count($entries)], $request->ip());

        return back()->with('status', 'reminders-test-sent');
    }
}
