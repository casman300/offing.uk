<?php

namespace App\Models;

use App\Support\Birthday;
use Carbon\CarbonImmutable;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person in the owner's life — usually "virtual" (no account). Spec §6/§8.
 *
 * Birthdays are attributes, not events (spec §6): the occurrence builder
 * will project them into feeds and reminders later.
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'birth_day',
        'birth_month',
        'birth_year',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_day' => 'integer',
            'birth_month' => 'integer',
            'birth_year' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function birthday(): Birthday
    {
        return new Birthday($this->birth_day, $this->birth_month, $this->birth_year);
    }

    /**
     * Props for the people pages, with birthday maths done against the
     * viewer's local date.
     *
     * @return array<string, mixed>
     */
    public function toPageArray(CarbonImmutable $today): array
    {
        $birthday = $this->birthday();
        $next = $birthday->nextOccurrence($today);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'birth_day' => $this->birth_day,
            'birth_month' => $this->birth_month,
            'birth_year' => $this->birth_year,
            'notes' => $this->notes,
            'next_birthday' => $next?->toDateString(),
            'days_until' => $birthday->daysUntil($today),
            'age' => $birthday->age($today) ?? $birthday->approximateAge($today),
            'age_is_approximate' => $birthday->isYearOnly(),
            'turns' => $birthday->turns($today),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
