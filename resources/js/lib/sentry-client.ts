import { init } from '@sentry/react';
import { scrub, type SentryConfig } from './sentry';

// Separate module with a named import, loaded on demand by initSentry(): a
// dynamic import of the whole '@sentry/react' namespace can't be tree-shaken
// and drags in Session Replay, the feedback widget and tracing (~160 KB gz)
// — none of which this app uses or wants.
export function start(dsn: string, config: SentryConfig): void {
    init({
        dsn,
        environment: config.environment ?? undefined,
        sendDefaultPii: false,
        // Session counting is usage tracking (spec §8), not error tracking.
        integrations: (defaults) => defaults.filter((integration) => integration.name !== 'BrowserSession'),
        beforeSend: (event) => scrub(event),
        beforeBreadcrumb: (breadcrumb) => scrub(breadcrumb),
    });
}
