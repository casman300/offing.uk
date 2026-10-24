<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response (security audit M2).
 *
 * - No framing at all: the OAuth consent screen, account deletion and
 *   passkey pages must never be clickjackable.
 * - HSTS once the app is served over https (from APP_URL, so it holds
 *   behind a TLS-terminating proxy too); never in local.
 * - The Content Security Policy ships report-only first: browsers log what
 *   it would block, nothing breaks. Enforce it in a later change once
 *   staging's console is clean. frame-ancestors is ignored in report-only
 *   mode, which is why X-Frame-Options carries the anti-framing job.
 *
 * Headers a response already set win: the feed and the magic-link page send
 * `Referrer-Policy: no-referrer` themselves.
 */
class SecurityHeaders
{
    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy-Report-Only' => self::contentSecurityPolicy(),
        ];

        if (! app()->isLocal() && str_starts_with((string) config('app.url'), 'https://')) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    public static function contentSecurityPolicy(): string
    {
        // Vite's dev server (scripts, styles, HMR socket) — local only.
        $dev = app()->isLocal() ? ' http://localhost:5173 ws://localhost:5173' : '';

        $directives = [
            'default-src' => "'self'",
            'script-src' => "'self'".$dev,
            // Inline style attributes come from React components.
            'style-src' => "'self' 'unsafe-inline' https://fonts.bunny.net".$dev,
            'font-src' => "'self' https://fonts.bunny.net",
            'img-src' => "'self' data:",
            'connect-src' => "'self'".$dev,
            'worker-src' => "'self'",
            'manifest-src' => "'self'",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'frame-ancestors' => "'none'",
            // The Connect Claude consent form ends in a redirect to the
            // client's callback, which form-action also governs.
            'form-action' => implode(' ', ["'self'", ...self::oauthRedirectSources()]),
        ];

        return implode('; ', array_map(fn ($name, $value) => "{$name} {$value}", array_keys($directives), $directives));
    }

    /**
     * config/mcp.php's redirect domains as CSP sources. Localhost clients
     * (Claude Code) pick their own port.
     *
     * @return list<string>
     */
    private static function oauthRedirectSources(): array
    {
        $sources = [];

        foreach ((array) config('mcp.redirect_domains', []) as $domain) {
            $parts = parse_url((string) $domain);

            if (! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $port = in_array($parts['host'], ['localhost', '127.0.0.1'], true) ? ':*' : (isset($parts['port']) ? ':'.$parts['port'] : '');
            $sources[] = "{$parts['scheme']}://{$parts['host']}{$port}";
        }

        return array_values(array_unique($sources));
    }
}
