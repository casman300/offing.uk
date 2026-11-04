<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Strips secrets from everything sent to Sentry: stack-frame variables are
 * dropped outright (see beforeSend), and URLs are scrubbed. Offing puts them in
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
        // Frame variables are the arguments of every call in the trace: the
        // Request, $_SERVER (Authorization header included), cookies, the
        // token itself. send_default_pii doesn't cover them, and whether PHP
        // records them at all depends on the host's zend.exception_ignore_args
        // — so drop them. File, line and code context are still sent.
        $stacktraces = [$event->getStacktrace()];
        foreach ($event->getExceptions() as $exception) {
            $stacktraces[] = $exception->getStacktrace();
        }
        foreach (array_filter($stacktraces) as $stacktrace) {
            foreach ($stacktrace->getFrames() as $frame) {
                $frame->setVars([]);
            }
        }

        $event->setRequest(self::scrubArray($event->getRequest()));
        $event->setTags(array_map(self::scrubString(...), $event->getTags()));

        if (($transaction = $event->getTransaction()) !== null) {
            $event->setTransaction(self::scrubString($transaction));
        }

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if (($message = $breadcrumb->getMessage()) !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrubString($message));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata($name, self::scrub($value));
        }

        return $breadcrumb;
    }

    public static function scrubString(string $value): string
    {
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value) ?? $value;
    }

    /**
     * @template K of array-key
     *
     * @param  array<K, mixed>  $value
     * @return array<K, mixed>
     */
    public static function scrubArray(array $value): array
    {
        return array_map(self::scrub(...), $value);
    }

    /** Strings and (nested) arrays are scrubbed; anything else is returned as is. */
    public static function scrub(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => self::scrubString($value),
            is_array($value) => self::scrubArray($value),
            default => $value,
        };
    }
}
