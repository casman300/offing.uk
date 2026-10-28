<?php

namespace App\Support\Barometer;

use App\Features\Barometer;
use App\Mail\BarometerCheckInMail;
use App\Models\BarometerReading;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Push\PushSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Sends due Barometer check-ins (#36), alongside the birthday drainer in
 * `reminders:drain`. Everything is re-checked at send time: the user still
 * has Barometer and check-ins on, the reading still exists and still has
 * no second rating. Push if the user has a device, otherwise email.
 *
 * The words never mention anxiety or what was logged: lock screens and
 * shared inboxes are visible to other people.
 */
final class CheckInDrainer
{
    public const TITLE = 'Offing';

    public const BODY = 'Time for a quick check-in.';

    public function __construct(private ?PushSender $push = null) {}

    /**
     * @return array{sent: int, skipped: int, failed: int, retrying: int}
     */
    public function drain(CarbonImmutable $now, int $limit = 200): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retrying' => 0];

        OutboxMessage::query()
            ->where('kind', OutboxMessage::KIND_BAROMETER_CHECK_IN)
            ->where('status', OutboxMessage::PENDING)
            ->where('send_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('send_at')
            ->limit($limit)
            ->get()
            ->each(function (OutboxMessage $message) use ($now, &$totals) {
                $totals[$this->send($message, $now)]++;
            });

        return $totals;
    }

    /**
     * @return 'sent'|'skipped'|'failed'|'retrying'
     */
    private function send(OutboxMessage $message, CarbonImmutable $now): string
    {
        if ($message->send_at->addMinutes(CheckIns::GIVE_UP_MINUTES)->isBefore($now)) {
            return $this->skip($message, 'Too late to be useful (cron was down?).');
        }

        $user = User::find($message->user_id);

        if ($user === null || ! $user->barometer_check_ins || ! Feature::for($user)->active(Barometer::class)) {
            return $this->skip($message, 'Check-ins were turned off before sending.');
        }

        // Never the text: only what's needed to decide.
        $reading = $user->barometerReadings()->find($message->checkInReadingId() ?? 0, ['id', 'user_id', 'rating_after']);

        if ($reading === null) {
            return $this->skip($message, 'The reading was deleted before sending.');
        }

        if ($reading->rating_after !== null) {
            return $this->skip($message, 'The second rating was already added.');
        }

        $note = null;

        if ($this->pushed($user, $reading, $note)) {
            return $this->sent($message, $now, 'push', $note);
        }

        try {
            Mail::to($user->email)->send(new BarometerCheckInMail($user, $reading->id));
        } catch (Throwable $e) {
            return $this->failed($message, $now, $e);
        }

        return $this->sent($message, $now, 'email', $note);
    }

    /**
     * Push to the user's devices. Unlike birthday reminders there's no push
     * retry: a check-in is time-sensitive, so any push problem falls
     * straight back to email.
     */
    private function pushed(User $user, BarometerReading $reading, ?string &$note): bool
    {
        $status = $this->push()->status();

        if (! $status->ready) {
            $note = $status->configured ? "Push unavailable: {$status->reason}" : null;

            return false;
        }

        $devices = $user->devices()->get();

        if ($devices->isEmpty()) {
            return false;
        }

        try {
            $result = $this->push()->send($devices, self::payload($reading));
        } catch (Throwable $e) {
            report($e);
            $note = 'Push failed: '.class_basename($e);

            return false;
        }

        if ($result->accepted === 0) {
            $note = 'Push failed: '.($result->errors[0] ?? 'every device’s subscription had expired');

            return false;
        }

        return true;
    }

    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function payload(BarometerReading $reading): array
    {
        return [
            'title' => self::TITLE,
            'body' => self::BODY,
            'url' => route('barometer.edit', $reading->id, false).'#second-rating',
            // Same tag → a re-delivered check-in replaces rather than stacks.
            'tag' => "check-in-{$reading->id}",
        ];
    }

    /**
     * @return 'sent'
     */
    private function sent(OutboxMessage $message, CarbonImmutable $now, string $via, ?string $note): string
    {
        $message->update([
            'status' => OutboxMessage::SENT,
            'sent_at' => $now,
            'delivered_via' => $via,
            'last_error' => $note !== null ? Str::limit($note, 1000) : null,
            'attempts' => $message->attempts + 1,
        ]);

        return 'sent';
    }

    /**
     * @return 'skipped'
     */
    private function skip(OutboxMessage $message, string $reason): string
    {
        $message->update(['status' => OutboxMessage::SKIPPED, 'last_error' => $reason]);

        return 'skipped';
    }

    /**
     * @return 'failed'|'retrying'
     */
    private function failed(OutboxMessage $message, CarbonImmutable $now, Throwable $e): string
    {
        $attempts = $message->attempts + 1;
        $error = Str::limit(class_basename($e).': '.$e->getMessage(), 1000);
        $wait = OutboxMessage::BACKOFF[$attempts - 1] ?? null;

        if ($wait === null) {
            $message->update(['status' => OutboxMessage::FAILED, 'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => null]);
            report($e);

            return 'failed';
        }

        $message->update(['attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => $now->addMinutes($wait)]);

        return 'retrying';
    }

    private function push(): PushSender
    {
        return $this->push ??= app(PushSender::class);
    }
}
