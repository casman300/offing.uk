<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class, 'owner_user_id');
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
