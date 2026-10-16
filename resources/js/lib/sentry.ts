export interface SentryConfig {
    dsn: string | null;
    environment: string | null;
}

// Same rules as App\Support\SentryScrubber (keep the two in step): magic-link
// URLs carry the login token in the path plus a signature in the query string.
const PATTERNS: [RegExp, string][] = [
    [/\/login\/([^/?#\s]+)\/[^/?#\s"'<>]+/g, '/login/$1/[Filtered]'],
    [/\b(signature|token)=[^&\s"'#<>]*/gi, '$1=[Filtered]'],
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
