<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of `notification_outbox` (never `notifications` — see CLAUDE.md),
 * of one of two kinds:
 *
 * - birthday: a single person's reminder for one birthday under one rule.
 *   OutboxDrainer batches rows sharing user, rule and send_at into one email.
 * - barometer_check_in: "time for a quick check-in" ~30 minutes after a
 *   Barometer reading (#36), sent by CheckInDrainer. Its payload holds only
 *   the reading's id — never anything from the reading.
 *
 * @property string $kind
 * @property array{person_id: int, date: string, turns: ?int, slot: ?string, days_before: int}|array{reading_id: int} $payload
 */
class OutboxMessage extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const KIND_BIRTHDAY = 'birthday';

    public const KIND_BAROMETER_CHECK_IN = 'barometer_check_in';

    /** Minutes to wait after each failed attempt; after the last, give up. */
    public const BACKOFF = [5, 15, 30, 60];

    protected $table = 'notification_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'send_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * A birthday reminder's payload (kind `birthday`).
     *
     * @return array{person_id: int, date: string, turns: ?int, slot: ?string, days_before: int}
     */
    public function birthday(): array
    {
        $payload = $this->payload;

        if (! isset($payload['person_id'])) {
            throw new LogicException("Outbox row {$this->id} is a {$this->kind}, not a birthday reminder.");
        }

        return $payload;
    }

    /** A Barometer check-in's reading (kind `barometer_check_in`); null for any other row. */
    public function checkInReadingId(): ?int
    {
        $payload = $this->payload;

        return isset($payload['reading_id']) ? $payload['reading_id'] : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ReminderRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReminderRule::class, 'reminder_rule_id');
    }
}
