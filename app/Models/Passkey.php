<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A WebAuthn credential that signs its user in on its own (spec §15, #9).
 * Magic links stay available alongside it as the fallback and recovery path.
 *
 * @property int $user_id
 * @property string $name
 * @property string $credential_id
 * @property string $credential
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class Passkey extends Model
{
    protected $fillable = [
        'name',
    ];

    protected $hidden = [
        'credential',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
