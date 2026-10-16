<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Strips secrets from URLs in everything sent to Sentry. Offing puts them in
 * two places: long random path segments (magic-link, email-change and crew
 * invite tokens are 64 characters, feed tokens 40) and query parameters
 * (every signed link's `signature`, OAuth's `code`/`state`). An error on —
 * or just after — one of those pages would otherwise ship a live sign-in,
 * invite or calendar-feed link to a third party.
 *
 * The rules are generic on purpose, so a new token-bearing route is covered
 * without anyone remembering to list it: tests/Feature/SentryTest.php checks
 * every current one. Keep new tokens at 32+ characters.
 *
 * Wired up as before_send/before_breadcrumb in config/sentry.php. The browser
 * SDK applies the same rules (resources/js/lib/sentry.ts) — keep them in step.
 */
class SentryScrubber
{
    public const FILTERED = '[Filtered]';

    /** @var array<string, string> pattern => replacement */
    private const PATTERNS = [
        // A whole path segment of 32+ token characters (feed tokens keep their .ics).
        '#/[A-Za-z0-9_-]{32,}(?=[/?\#.\s"\'<>]|$)#' => '/'.self::FILTERED,
        '/\b(signature|token|code|state|access_token|refresh_token|id_token|client_secret|code_verifier)=[^&\s"\'#<>]*/i' => '$1='.self::FILTERED,
    ];

    public static function beforeSend(Event $event): ?Event
    {
        $event->setRequest(self::scrub($event->getRequest()));
        $event->setTags(self::scrub($event->getTags()));

        if (($transaction = $event->getTransaction()) !== null) {
            $event->setTransaction(self::scrub($transaction));
        }

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if (($message = $breadcrumb->getMessage()) !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrub($message));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata($name, self::scrub($value));
        }

        return $breadcrumb;
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value) ?? $value;
        }

        if (is_array($value)) {
            return array_map(self::scrub(...), $value);
        }

        return $value;
    }
}
