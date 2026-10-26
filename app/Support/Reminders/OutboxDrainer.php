<?php

namespace App\Support\Reminders;

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Mail\BirthdayReminderMail;
use App\Models\OutboxMessage;
use App\Models\Person;
use App\Models\User;
use App\Support\Push\PushSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Job three of the cron pipeline (spec §11, #17): sends due outbox rows.
 *
 * Rows sharing user + rule + send_at go out as one message ("Tomorrow: Sue
 * and Tom"), by the user's chosen delivery: email; push with email as
 * backup (no devices, push unavailable, or push still failing after
 * PUSH_RETRIES → email); or both. Everything is re-checked at send time — reminders still on,
 * flags still active, people not deleted since — and a failure retries
 * with OutboxMessage::BACKOFF before being marked failed.
 */
final class OutboxDrainer
{
    /** Push hiccups retried (+5, +15 min) before falling back to email. */
    public const PUSH_RETRIES = 2;

    public function __construct(private ?PushSender $push = null) {}

    /**
     * @return array{sent: int, skipped: int, failed: int, retrying: int}
     */
    public function drain(CarbonImmutable $now, int $limit = 500): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retrying' => 0];

        OutboxMessage::query()
            ->where('kind', OutboxMessage::KIND_BIRTHDAY)
            ->where('status', OutboxMessage::PENDING)
            ->where('channel', 'email')
            ->where('send_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('send_at')
            ->limit($limit)
            ->get()
            ->groupBy(fn (OutboxMessage $m) => "{$m->user_id}|{$m->reminder_rule_id}|{$m->send_at->getTimestamp()}")
            ->each(function (Collection $batch) use ($now, &$totals) {
                $totals[$this->sendBatch($batch, $now)] += $batch->count();
            });

        return $totals;
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     * @return 'sent'|'skipped'|'failed'|'retrying'
     */
    private function sendBatch(Collection $batch, CarbonImmutable $now): string
    {
        /** @var OutboxMessage $first */
        $first = $batch->first();
        $user = User::find($first->user_id);

        if ($user === null || ! $user->remindersOn()
            || ! Feature::for($user)->allAreActive([BirthdayEngine::class, Reminders::class])) {
            return $this->mark($batch, OutboxMessage::SKIPPED, 'Reminders were turned off before sending.');
        }

        $people = $user->people()->whereIn('id', $batch->pluck('payload.person_id'))->get()->keyBy('id');
        /** @var list<array{person: Person, date: CarbonImmutable, turns: ?int}> $entries the filter drops anyone deleted */
        $entries = $batch
            ->filter(fn (OutboxMessage $m) => $people->has($m->birthday()['person_id']))
            ->map(fn (OutboxMessage $m) => [
                'person' => $people[$m->birthday()['person_id']],
                'date' => CarbonImmutable::parse($m->birthday()['date']),
                'turns' => $m->birthday()['turns'],
            ])
            ->sortBy(fn (array $e) => mb_strtolower($e['person']->name))
            ->values()
            ->all();

        if ($entries === []) {
            return $this->mark($batch, OutboxMessage::SKIPPED, 'Everyone in this reminder was deleted before sending.');
        }

        $daysBefore = $first->birthday()['days_before'];
        $delivery = $user->reminder_delivery ?? User::DELIVERY_EMAIL;
        $via = [];
        $note = null;

        if ($delivery !== User::DELIVERY_EMAIL) {
            [$pushed, $note] = $this->tryPush($user, $entries, $daysBefore);

            if ($pushed) {
                $via[] = 'push';
            } elseif ($note !== null && str_starts_with($note, 'retry:') && $delivery === User::DELIVERY_PUSH && $first->attempts < self::PUSH_RETRIES) {
                // A push service hiccup: try push again shortly before falling back to email.
                return $this->retry($batch, $now, 'Push failed: '.substr($note, 6));
            }
        }

        $needsEmail = $delivery !== User::DELIVERY_PUSH || $via === [];

        if ($needsEmail) {
            try {
                Mail::to($user->email)->send(new BirthdayReminderMail($user, $entries, $daysBefore));
                $via[] = $delivery === User::DELIVERY_PUSH ? 'email (push fallback)' : 'email';
            } catch (Throwable $e) {
                // Push already reached a device: don't re-push on a retry just to get the email out.
                if ($via === []) {
                    return $this->failed($batch, $now, $e);
                }

                $note = 'Email failed: '.class_basename($e);
            }
        }

        $batch->each->update([
            'status' => OutboxMessage::SENT,
            'sent_at' => $now,
            'delivered_via' => implode(' + ', $via),
            'last_error' => $note !== null ? Str::limit(str_replace('retry:', 'Push failed: ', $note), 1000) : null,
            'attempts' => $first->attempts + 1,
        ]);

        return 'sent';
    }

    /**
     * @param  list<array{person: Person, date: CarbonImmutable, turns: ?int}>  $entries
     * @return array{0: bool, 1: ?string} [delivered, note]; a note starting "retry:" is worth retrying
     */
    private function tryPush(User $user, array $entries, int $daysBefore): array
    {
        $status = $this->push()->status();

        if (! $status->ready) {
            return [false, $status->configured ? "Push unavailable: {$status->reason}" : null];
        }

        $devices = $user->devices()->get();

        if ($devices->isEmpty()) {
            return [false, 'No devices with notifications on.'];
        }

        try {
            $result = $this->push()->send($devices, ReminderPayload::push($entries, $daysBefore));
        } catch (Throwable $e) {
            report($e);

            return [false, 'retry:'.class_basename($e)];
        }

        if ($result->accepted > 0) {
            return [true, null];
        }

        return $result->failed > 0
            ? [false, 'retry:'.($result->errors[0] ?? 'push service error')]
            : [false, 'Every device’s subscription had expired.'];
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     * @return 'retrying'
     */
    private function retry(Collection $batch, CarbonImmutable $now, string $reason): string
    {
        $attempts = $batch->first()->attempts + 1;

        $batch->each->update([
            'attempts' => $attempts,
            'last_error' => Str::limit($reason, 1000),
            'next_attempt_at' => $now->addMinutes(OutboxMessage::BACKOFF[$attempts - 1]),
        ]);

        return 'retrying';
    }

    private function push(): PushSender
    {
        return $this->push ??= app(PushSender::class);
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     * @return 'failed'|'retrying'
     */
    private function failed(Collection $batch, CarbonImmutable $now, Throwable $e): string
    {
        $attempts = $batch->first()->attempts + 1;
        $error = Str::limit(class_basename($e).': '.$e->getMessage(), 1000);
        $wait = OutboxMessage::BACKOFF[$attempts - 1] ?? null;

        if ($wait === null) {
            $batch->each->update(['status' => OutboxMessage::FAILED, 'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => null]);
            report($e);

            return 'failed';
        }

        $batch->each->update(['attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => $now->addMinutes($wait)]);

        return 'retrying';
    }

    /**
     * @param  Collection<int, OutboxMessage>  $batch
     * @param  'skipped'  $status
     * @return 'skipped'
     */
    private function mark(Collection $batch, string $status, string $reason): string
    {
        $batch->each->update(['status' => $status, 'last_error' => $reason]);

        return $status;
    }
}
