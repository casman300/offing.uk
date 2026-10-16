<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of `notification_outbox` (never `notifications` — see CLAUDE.md):
 * a single person's reminder for one birthday under one rule. The drainer
 * batches rows sharing user, rule and send_at into one email.
 *
 * @property array{person_id: int, date: string, turns: ?int, slot: ?string, days_before: int} $payload
 */
class OutboxMessage extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

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
