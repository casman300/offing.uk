<?php

namespace App\Support\Feeds;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches an external calendar safely (spec §13):
 *
 * - every hop, redirects included, goes through UrlGuard, and the
 *   connection is pinned to the address it vetted (no DNS rebinding);
 * - at most MAX_REDIRECTS redirects, never https → http;
 * - short connect/overall timeouts and a hard size cap, enforced while
 *   downloading — a huge or endless body is cut off, not buffered;
 * - conditional GET with the last ETag, so an unchanged feed costs a 304;
 * - a fresh client every time: no cookies, no credentials, no proxy.
 */
final class FeedFetcher
{
    public const MAX_REDIRECTS = 3;

    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 15;

    public function __construct(private readonly UrlGuard $guard) {}

    public static function maxBytes(): int
    {
        return (int) config('offing.feeds.max_bytes', 5 * 1024 * 1024);
    }

    /**
     * @throws FeedException
     */
    public function fetch(string $url, ?string $etag = null): FetchResult
    {
        $current = UrlGuard::normalise($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->guard->check($current);
            $response = $this->request($target, $etag);
            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new FeedException('The calendar’s server sent a redirect without saying where to.');
                }

                $next = self::resolveLocation($current, $location);

                if ($target['scheme'] === 'https' && str_starts_with(strtolower($next), 'http:')) {
                    throw new FeedException('The calendar’s server redirected from https to an insecure address.');
                }

                $current = $next;

                continue;
            }

            if ($status === 304) {
                return FetchResult::notModified();
            }

            if ($status === 404 || $status === 410) {
                throw new FeedException("The calendar wasn’t found there any more (HTTP {$status}). It may have been deleted or its link reset.");
            }

            if ($status === 401 || $status === 403) {
                throw new FeedException("The calendar’s server refused access (HTTP {$status}). If it’s a secret address, it may have been reset.");
            }

            if ($status !== 200) {
                throw new FeedException("The calendar’s server answered with an error (HTTP {$status}).");
            }

            if ((int) $response->header('Content-Length') > self::maxBytes()) {
                throw new FeedException(self::tooBig());
            }

            $body = self::decode($response);

            if (strlen($body) > self::maxBytes()) {
                throw new FeedException(self::tooBig());
            }

            if (stripos($body, 'BEGIN:VCALENDAR') === false) {
                throw new FeedException('That address didn’t return a calendar. Check it’s the iCal (.ics) link, not a web page.');
            }

            $newEtag = $response->header('ETag');

            return FetchResult::ok($body, $newEtag === '' ? null : mb_substr($newEtag, 0, 250));
        }

        throw new FeedException('The calendar’s server redirected too many times.');
    }

    /**
     * @param  array{url: string, scheme: string, host: string, port: int, ip: string|null}  $target
     */
    private function request(array $target, ?string $etag): Response
    {
        $max = self::maxBytes();
        $tooBig = false;
        $curl = [];

        if ($target['ip'] !== null) {
            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
            $curl[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:{$ip}"];
        }

        $headers = [
            'Accept' => 'text/calendar, text/plain;q=0.9, */*;q=0.1',
            'Accept-Encoding' => 'gzip',
        ];

        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }

        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'cookies' => false,
                'proxy' => '',
                'decode_content' => false,
                'curl' => $curl,
                'on_headers' => function (ResponseInterface $response) use ($max, &$tooBig) {
                    if ((int) $response->getHeaderLine('Content-Length') > $max) {
                        $tooBig = true;
                        throw new FeedException(self::tooBig());
                    }
                },
                'progress' => function ($downloadTotal, $downloaded) use ($max, &$tooBig) {
                    if ($downloaded > $max) {
                        $tooBig = true;
                        throw new FeedException(self::tooBig());
                    }
                },
            ])
                ->withHeaders($headers)
                ->withUserAgent('Offing calendar subscriber (+https://offing.uk)')
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->get($target['url']);
        } catch (ConnectionException $e) {
            throw new FeedException($tooBig ? self::tooBig() : 'Couldn’t connect to the calendar’s server, or it took too long to answer.');
        } catch (FeedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new FeedException($tooBig ? self::tooBig() : 'Couldn’t fetch the calendar.');
        }
    }

    /** gzip is asked for to save bandwidth; unpacked here with the size cap, so a zip bomb can't fill memory. */
    private static function decode(Response $response): string
    {
        $body = $response->body();
        $encoding = strtolower($response->header('Content-Encoding'));

        if ($encoding === '' || $encoding === 'identity') {
            return $body;
        }

        if ($encoding === 'gzip' || $encoding === 'x-gzip') {
            $decoded = @gzdecode($body, self::maxBytes() + 1);
        } elseif ($encoding === 'deflate') {
            $decoded = @gzuncompress($body, self::maxBytes() + 1);
        } else {
            throw new FeedException('The calendar’s server sent it in an encoding Offing can’t read.');
        }

        if ($decoded === false) {
            throw new FeedException(self::tooBig().' (or it was corrupted)');
        }

        return $decoded;
    }

    private static function tooBig(): string
    {
        $max = self::maxBytes();
        $size = $max >= 1024 * 1024 ? intdiv($max, 1024 * 1024).' MB' : intdiv($max, 1024).' KB';

        return "The calendar is too big to import (over {$size}).";
    }

    /** Where a redirect's Location points, relative to the URL that sent it. */
    public static function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.($dir === '' ? '/' : $dir).$location;
    }
}
