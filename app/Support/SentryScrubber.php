<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Strips login tokens from everything sent to Sentry. Magic-link URLs carry
 * the token in the path (/login/{magicLink}/{token}) plus a signature in the
 * query string, and an error on — or right after — that page would otherwise
 * ship a live login link to a third party.
 *
 * Wired up as before_send/before_breadcrumb in config/sentry.php. The browser
 * SDK applies the same rules (resources/js/lib/sentry.ts) — when a new
 * token-bearing route lands (e.g. ICS feed tokens), add it to both.
 */
class SentryScrubber
{
    public const FILTERED = '[Filtered]';

    /** @var array<string, string> pattern => replacement */
    private const PATTERNS = [
        '#/login/([^/?\#\s]+)/[^/?\#\s"\'<>]+#' => '/login/$1/'.self::FILTERED,
        '/\b(signature|token)=[^&\s"\'#<>]*/i' => '$1='.self::FILTERED,
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
