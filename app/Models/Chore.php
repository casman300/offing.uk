<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One recurring household chore (spec §25). There's no such thing as a
 * one-off chore — that's a task, and tasks are a permanent non-goal (§1).
 */
class Chore extends Model
{
    use SoftDeletes;

    /** Next due = `every` `unit` after the day it was done. */
    public const FLOATING = 'floating';

    /** Next due = the next date in its RRULE pattern, however late you were. */
    public const FIXED = 'fixed';

    public const UNITS = ['day', 'week', 'month', 'year'];

    public const ANYONE = 'anyone';

    public const MEMBER = 'member';

    public const ROTA = 'rota';

    protected $fillable = [
        'title',
        'area',
        'notes',
        'mode',
        'every',
        'unit',
        'rrule',
        'anchor_on',
        'due_on',
        'lead_days',
        'assign',
        'assignee_member_id',
        'rota_position',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'every' => 'integer',
            'anchor_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'lead_days' => 'integer',
            'rota_position' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChoreList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ChoreList::class, 'chore_list_id');
    }

    /**
     * @return HasMany<ChoreEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ChoreEntry::class);
    }

    /**
     * @return BelongsTo<CrewMember, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class, 'assignee_member_id');
    }

    /**
     * @return BelongsToMany<CrewMember, $this>
     */
    public function rotaMembers(): BelongsToMany
    {
        return $this->belongsToMany(CrewMember::class, 'chore_rota')->withPivot('position')->orderByPivot('position');
    }

    public function isFixed(): bool
    {
        return $this->mode === self::FIXED;
    }

    /**
     * Whose turn or job it is now, or null for "anyone" (including a pinned
     * member who has since gone, or an empty rota).
     */
    public function currentAssignee(): ?CrewMember
    {
        return match ($this->assign) {
            self::MEMBER => $this->assignee?->left_at === null ? $this->assignee : null,
            self::ROTA => $this->rotaTurn(),
            default => null,
        };
    }

    /**
     * @param  Collection<int, CrewMember>|null  $rota
     */
    public function rotaTurn(?Collection $rota = null): ?CrewMember
    {
        $rota ??= $this->rotaMembers;

        if ($rota->isEmpty()) {
            return null;
        }

        return $rota->values()->get($this->rota_position % $rota->count());
    }
}
