<?php

namespace App\Models;

use App\Support\ConnectClaude\AccessLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

/**
 * One connected Claude (or other MCP client) and what the user let it do —
 * spec §12. Every token for the client carries just `mcp:use`; this row is
 * what the MCP server actually checks.
 *
 * @property AccessLevel $level
 */
class AiGrant extends Model
{
    /** Don't write last_used_at on every call — once a minute is plenty. */
    public const TOUCH_EVERY_SECONDS = 60;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => AccessLevel::class,
            'include_private' => 'boolean',
            'barometer' => 'boolean',
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

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    public function touchUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
