<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vCard import (#14): pending (parsed, awaiting review) → committed →
 * optionally undone. See the migration for what is and isn't stored.
 *
 * @property list<array<string, mixed>>|null $rows
 * @property array<string, mixed>|null $result
 */
class ContactImport extends Model
{
    use Prunable;

    public const PENDING = 'pending';

    public const COMMITTED = 'committed';

    public const UNDONE = 'undone';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'result' => 'array',
            'committed_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Abandoned reviews hold other people's names and birthdays — don't
     * keep them longer than a day.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('status', self::PENDING)->where('created_at', '<', now()->subDay());
    }
}
