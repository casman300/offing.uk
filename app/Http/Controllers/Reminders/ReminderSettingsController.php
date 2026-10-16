<?php

namespace App\Http\Controllers\Reminders;

use App\Http\Controllers\Controller;
use App\Mail\BirthdayReminderMail;
use App\Models\AuditLog;
use App\Models\ReminderRule;
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

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/reminders', [
            'enabled' => $user->remindersOn(),
            'timezone' => $user->timezone,
            'slots' => collect($user->reminderSlots())->map(fn (ReminderRule $rule) => [
                'enabled' => $rule->enabled,
                'time' => $rule->time(),
            ]),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'slots' => ['present', 'array'],
            'slots.*.enabled' => ['required', 'boolean'],
            'slots.*.time' => ['required', 'string', 'regex:'.self::TIME_PATTERN],
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
        $entries = collect((new BirthdaySource)->between($user, $today, $today->addYear()))
            ->sortBy(fn (array $e) => $e['date']->getTimestamp())
            ->take(3)
            ->values()
            ->all();

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
