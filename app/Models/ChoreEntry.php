<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in the ship's log (spec §25): a chore done or skipped. Never
 * deleted — a mistake is voided, and voiding the latest live entry puts the
 * chore back how it was (`before`). Names and the title are snapshots.
 */
class ChoreEntry extends Model
{
    public const UPDATED_AT = null;

    public const DONE = 'done';

    public const SKIP = 'skip';

    protected $fillable = [
        'chore_id',
        'kind',
        'done_on',
        'done_by_member_id',
        'done_by_name',
        'chore_title',
        'logged_by_user_id',
        'via',
        'reason',
        'before',
    ];

    protected function casts(): array
    {
        return [
            'done_on' => 'immutable_date',
            'before' => 'array',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Chore, $this>
     */
    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
