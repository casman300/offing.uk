<?php

namespace App\Support\ConnectClaude;

use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * Connecting, changing and disconnecting a Claude (spec §12). Every step is
 * audited; the grant row and the client's tokens always go together, so a
 * revoked connection can't keep working on a token it already holds.
 */
final class Connections
{
    /** Dynamic registration is open to anyone, so unused clients don't linger. */
    public const ABANDONED_AFTER_HOURS = 24;

    public static function approve(User $user, Client $client, AccessLevel $level, bool $includePrivate, ?string $ip = null): AiGrant
    {
        $grant = AiGrant::updateOrCreate(
            ['user_id' => $user->id, 'oauth_client_id' => $client->getKey()],
            ['level' => $level, 'include_private' => $includePrivate],
        );

        AuditLog::record($user, 'connect_claude.approved', $grant, self::meta($grant, $client), $ip);

        return $grant;
    }

    public static function denied(User $user, Client $client, ?string $ip = null): void
    {
        AuditLog::record($user, 'connect_claude.denied', $client, ['client' => $client->name], $ip);
    }

    public static function update(AiGrant $grant, AccessLevel $level, bool $includePrivate, ?string $ip = null): void
    {
        $before = ['level' => $grant->level->value, 'include_private' => $grant->include_private];
        $grant->update(['level' => $level, 'include_private' => $includePrivate]);

        AuditLog::record($grant->user, 'connect_claude.updated', $grant, [...self::meta($grant, $grant->client), 'before' => $before], $ip);
    }

    public static function revoke(AiGrant $grant, ?string $ip = null): void
    {
        self::revokeTokens($grant->user_id, $grant->oauth_client_id);

        AuditLog::record($grant->user, 'connect_claude.revoked', $grant, self::meta($grant, $grant->client), $ip);

        $grant->delete();
    }

    /** Account deletion: the grants cascade, the tokens don't (no foreign key). */
    public static function forgetUser(User $user): void
    {
        Passport::token()->newQuery()->where('user_id', $user->id)->get()
            ->each(function (Token $token) {
                $token->refreshToken?->delete();
                $token->delete();
            });
    }

    /**
     * Clients that registered but never got a grant (someone started to
     * connect and gave up, or a bot hit the open endpoint).
     */
    public static function pruneAbandonedClients(): int
    {
        $clients = Passport::client()->newQuery()
            ->whereNull('owner_id')
            ->where('created_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))
            ->whereNotIn('id', AiGrant::query()->select('oauth_client_id'))
            ->whereDoesntHave('tokens', fn ($query) => $query->where('revoked', false))
            ->get();

        $clients->each(function (Client $client) {
            $client->tokens()->get()->each(function (Token $token) {
                $token->refreshToken?->delete();
                $token->delete();
            });
            $client->authCodes()->delete();
            $client->delete();
        });

        return $clients->count();
    }

    private static function revokeTokens(int $userId, string $clientId): void
    {
        Passport::token()->newQuery()
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->get()
            ->each(function (Token $token) {
                $token->revoke();
                $token->refreshToken?->revoke();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(AiGrant $grant, ?Client $client): array
    {
        return [
            'client' => $client?->name,
            'level' => $grant->level->value,
            'include_private' => $grant->include_private,
        ];
    }
}
