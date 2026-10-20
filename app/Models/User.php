<?php

namespace App\Models;

use App\Support\Crews\CrewHandover;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * People are owned through a morph, so there's no foreign key to cascade
     * an account deletion to them — do it here, along with handing over any
     * crew this was the last captain of (spec §7).
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            $user->people()->withTrashed()->forceDelete();
            CrewHandover::forDeletedAccount($user);
        });
    }

    public const DEFAULT_TIMEZONE = 'Europe/London';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'timezone',
    ];

    /** reminder_delivery values. */
    public const DELIVERY_EMAIL = 'email';

    public const DELIVERY_PUSH = 'push'; // push, with email as backup

    public const DELIVERY_BOTH = 'both';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reminders_enabled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MagicLink, $this>
     */
    public function magicLinks(): HasMany
    {
        return $this->hasMany(MagicLink::class);
    }

    /**
     * Your private address book. Crew-owned people (passengers) hang off
     * the crew instead.
     *
     * @return MorphMany<Person, $this>
     */
    public function people(): MorphMany
    {
        return $this->morphMany(Person::class, 'owner');
    }

    /**
     * @return HasMany<CrewMember, $this>
     */
    public function crewMemberships(): HasMany
    {
        return $this->hasMany(CrewMember::class)->whereNull('left_at');
    }

    /**
     * @return HasMany<Passkey, $this>
     */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /**
     * @return MorphMany<FeedToken, $this>
     */
    public function feedTokens(): MorphMany
    {
        return $this->morphMany(FeedToken::class, 'subject');
    }

    /**
     * @return HasMany<ContactImport, $this>
     */
    public function contactImports(): HasMany
    {
        return $this->hasMany(ContactImport::class, 'owner_user_id');
    }

    /**
     * @return HasMany<ReminderRule, $this>
     */
    public function reminderRules(): HasMany
    {
        return $this->hasMany(ReminderRule::class);
    }

    /**
     * The two reminder slots, created with spec defaults on first use.
     *
     * @return array<string, ReminderRule> keyed by slot
     */
    public function reminderSlots(): array
    {
        $slots = [];

        foreach (ReminderRule::SLOTS as $slot => $offset) {
            $slots[$slot] = $this->reminderRules()->firstOrCreate(['slot' => $slot], ['offset_def' => $offset, 'enabled' => true]);
        }

        return $slots;
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function remindersOn(): bool
    {
        return $this->reminders_enabled_at !== null;
    }

    /**
     * Admins come from ADMIN_EMAILS in the host's .env (config/offing.php),
     * not from the database — nothing inside the app can grant it.
     */
    public function isAdmin(): bool
    {
        return in_array(strtolower($this->email), config('offing.admin_emails'), true);
    }

    /**
     * Today's date where the user is — decides whether a birthday has
     * happened yet this year.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone ?? self::DEFAULT_TIMEZONE)->startOfDay();
    }
}
