<?php

namespace App\Support\Reminders;

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Models\OutboxMessage;
use App\Models\ReminderRule;
use App\Models\User;
use App\Support\Birthday;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * Job two of the cron pipeline (spec §11, #17). Writes an outbox row for
 * every reminder that has come due, and only once due — so editing a
 * birthday or a reminder time before then simply takes effect.
 *
 * - Up to LATE_LIMIT late: queued to send (tolerates cron gaps).
 * - Later than that (up to LOOKBACK): recorded as skipped, never sent — no
 *   burst of stale reminders after downtime or a first deploy.
 * - Only reminders due after the user switched reminders on are considered.
 * - dedupe_key (rule + person + birthday date) is unique, so a reminder can
 *   never be queued twice, however often or late the scanner runs.
 */
final class ReminderScanner
{
    public const LATE_LIMIT_MINUTES = 120;

    public const LOOKBACK_HOURS = 24;

    public function __construct(private readonly BirthdaySource $source = new BirthdaySource) {}

    /**
     * @return array{queued: int, skipped: int}
     */
    public function scan(CarbonImmutable $now): array
    {
        $totals = ['queued' => 0, 'skipped' => 0];

        User::query()
            ->whereNotNull('reminders_enabled_at')
            ->whereHas('reminderRules', fn ($q) => $q->where('enabled', true)->where('channel', 'email'))
            ->chunkById(100, function ($users) use ($now, &$totals) {
                foreach ($users as $user) {
                    if (! Feature::for($user)->allAreActive([BirthdayEngine::class, Reminders::class])) {
                        continue;
                    }

                    foreach ($this->scanUser($user, $now) as $status => $count) {
                        $totals[$status] += $count;
                    }
                }
            });

        return $totals;
    }

    /**
     * @return array{queued: int, skipped: int}
     */
    public function scanUser(User $user, CarbonImmutable $now): array
    {
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $earliest = $now->subHours(self::LOOKBACK_HOURS)->max(CarbonImmutable::instance($user->reminders_enabled_at));
        $counts = ['queued' => 0, 'skipped' => 0];

        $rules = $user->reminderRules()->where('enabled', true)->where('channel', 'email')->get();

        foreach ($rules as $rule) {
            // Birthdays whose reminder could fall in the window: shift by days_before, pad a day for timezones.
            $localNow = $now->setTimezone($tz);
            $from = Birthday::dateOnly($localNow->subHours(self::LOOKBACK_HOURS))->addDays($rule->daysBefore() - 1);
            $to = Birthday::dateOnly($localNow)->addDays($rule->daysBefore() + 1);

            foreach ($this->source->between($user, $from, $to) as $entry) {
                $due = $this->dueAt($rule, $entry['date'], $tz);

                if ($due->gt($now) || $due->lt($earliest)) {
                    continue;
                }

                $late = $due->lt($now->subMinutes(self::LATE_LIMIT_MINUTES));

                $inserted = OutboxMessage::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'reminder_rule_id' => $rule->id,
                    'channel' => $rule->channel,
                    'dedupe_key' => "rule:{$rule->id}|person:{$entry['person']->id}|{$entry['date']->toDateString()}",
                    'payload' => json_encode([
                        'person_id' => $entry['person']->id,
                        'date' => $entry['date']->toDateString(),
                        'turns' => $entry['turns'],
                        'slot' => $rule->slot,
                        'days_before' => $rule->daysBefore(),
                    ]),
                    'send_at' => $due,
                    'status' => $late ? OutboxMessage::SKIPPED : OutboxMessage::PENDING,
                    'last_error' => $late ? 'More than '.self::LATE_LIMIT_MINUTES.' minutes late when found (cron gap?)' : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($inserted > 0) {
                    $counts[$late ? 'skipped' : 'queued']++;
                }
            }
        }

        return $counts;
    }

    /**
     * The UTC instant a rule fires for a birthday: N days before, at HH:MM
     * local time (so 20:00 stays 20:00 across BST/GMT).
     */
    public function dueAt(ReminderRule $rule, CarbonImmutable $birthday, string $tz): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $rule->time()));

        return CarbonImmutable::create($birthday->year, $birthday->month, $birthday->day, $hour, $minute, 0, $tz)
            ->subDays($rule->daysBefore())
            ->utc();
    }
}
