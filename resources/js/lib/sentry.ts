export interface SentryConfig {
    dsn: string | null;
    environment: string | null;
}

// Same rules as App\Support\SentryScrubber (keep the two in step): long
// random path segments (sign-in, invite and feed tokens) and secret-bearing
// query parameters (link signatures, OAuth codes).
const PATTERNS: [RegExp, string][] = [
    [/\/[A-Za-z0-9_-]{32,}(?=[/?#.\s"'<>]|$)/g, '/[Filtered]'],
    [/\b(signature|token|code|state|access_token|refresh_token|id_token|client_secret|code_verifier)=[^&\s"'#<>]*/gi, '$1=[Filtered]'],
];

export function scrub<T>(value: T): T {
    if (typeof value === 'string') {
        return PATTERNS.reduce((s, [pattern, replacement]) => s.replace(pattern, replacement), value as string) as T;
    }
    if (Array.isArray(value)) {
        return value.map(scrub) as T;
    }
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([key, v]) => [key, scrub(v)])) as T;
    }
    return value;
}

/**
 * Errors only, no personal data — see config/sentry.php. Loaded on demand,
 * so without a DSN (local dev) the SDK is never even downloaded.
 */
export function initSentry(config: SentryConfig | undefined): void {
    const dsn = config?.dsn;
    if (!dsn) {
        return;
    }

    void import('./sentry-client').then(({ start }) => start(dsn, config));
}
