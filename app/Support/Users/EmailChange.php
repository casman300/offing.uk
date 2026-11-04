<?php

namespace App\Support\Users;

use App\Mail\EmailChangeConfirmMail;
use App\Mail\EmailChangedMail;
use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\MagicLink;
use App\Models\SignIn;
use App\Models\User;
use App\Support\ConnectClaude\Connections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Changing an account's email (security audit H1). The address is the
 * account — magic links, crew invites and ADMIN_EMAILS all trust it — so a
 * new one only takes effect once its inbox clicks a confirm link, and the
 * old one is then told, with a "this wasn't me" link that puts it back and
 * throws out whoever made the change.
 *
 * Both links are magic links with a purpose (see MagicLink); the pending
 * change is simply the newest open confirm link.
 */
final class EmailChange
{
    public const CONFIRM_MINUTES = 60 * 24;

    public const REVERT_MINUTES = 60 * 24 * 7;

    /** The address waiting to be confirmed, if any. */
    public static function pending(User $user): ?string
    {
        return $user->magicLinks()->where('purpose', MagicLink::EMAIL_CONFIRM)->open()->latest('id')->value('email');
    }

    /**
     * Send a confirm link to $email. Replaces any earlier pending change, so
     * only the newest link works.
     */
    public static function request(User $user, string $email, ?string $ip = null): void
    {
        $email = Str::lower(trim($email));

        self::closeOpen($user, MagicLink::EMAIL_CONFIRM);

        [$link, $url] = MagicLink::issueForEmail($user, MagicLink::EMAIL_CONFIRM, $email, self::CONFIRM_MINUTES, 'email-change.confirm');

        Mail::to($email)->queue(new EmailChangeConfirmMail($user, $email, $url));

        AuditLog::record($user, 'auth.email_change.requested', $link, ['to' => $email], $ip);
    }

    public static function cancel(User $user, ?string $ip = null): void
    {
        $email = self::pending($user);

        if ($email === null) {
            return;
        }

        self::closeOpen($user, MagicLink::EMAIL_CONFIRM);

        AuditLog::record($user, 'auth.email_change.cancelled', null, ['to' => $email], $ip);
    }

    /**
     * Swap in the confirmed address and tell the old one. Returns false if
     * someone else has taken the address since it was requested.
     */
    public static function confirm(MagicLink $link, ?string $ip = null): bool
    {
        $user = $link->user;
        $to = (string) $link->email;

        if (self::takenByAnother($user, $to)) {
            return false;
        }

        $from = DB::transaction(function () use ($user, $to) {
            $from = $user->email;
            $user->forceFill(['email' => $to, 'email_verified_at' => now()])->save();
            self::closeOpen($user, MagicLink::EMAIL_CONFIRM);

            return $from;
        });

        [, $revertUrl] = MagicLink::issueForEmail($user, MagicLink::EMAIL_REVERT, $from, self::REVERT_MINUTES, 'email-change.revert');

        Mail::to($from)->queue(new EmailChangedMail($user, $from, $to, $revertUrl));

        AuditLog::record($user, 'auth.email_change.confirmed', $link, ['from' => $from, 'to' => $to], $ip);

        return true;
    }

    /**
     * "This wasn't me": put the old address back and undo what a hijacker
     * could have planted since the change — every session and remember
     * cookie, any open links, AI connections, and passkeys, feed links and
     * push devices added after it. Returns false if the old address now
     * belongs to someone else (only an admin can sort that out).
     */
    public static function revert(MagicLink $link, ?string $ip = null): bool
    {
        $user = $link->user;
        $to = (string) $link->email;
        $since = $link->created_at;

        if (self::takenByAnother($user, $to)) {
            return false;
        }

        $from = $user->email;

        $removed = DB::transaction(function () use ($user, $to, $since) {
            $user->forceFill(['email' => $to, 'email_verified_at' => now()])->save();
            SignIn::signOutEverywhere($user);

            $user->magicLinks()->open()->update(['used_at' => now()]);

            return [
                'passkeys' => $user->passkeys()->where('created_at', '>=', $since)->delete(),
                'feed_tokens' => $user->feedTokens()->active()->where('created_at', '>=', $since)->update(['revoked_at' => now()]),
                'devices' => $user->devices()->where('created_at', '>=', $since)->delete(),
            ];
        });

        $grants = AiGrant::where('user_id', $user->id)->get();
        $grants->each(fn (AiGrant $grant) => Connections::revoke($grant, $ip));

        AuditLog::record($user, 'auth.email_change.reverted', $link, [
            'from' => $from,
            'to' => $to,
            ...$removed,
            'ai_connections' => $grants->count(),
        ], $ip);

        return true;
    }

    private static function takenByAnother(User $user, string $email): bool
    {
        return User::whereRaw('lower(email) = ?', [Str::lower($email)])->whereKeyNot($user->id)->exists();
    }

    private static function closeOpen(User $user, string $purpose): void
    {
        $user->magicLinks()->where('purpose', $purpose)->open()->update(['used_at' => now()]);
    }
}
