import {
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
    startAuthentication,
    startRegistration,
    WebAuthnError,
} from '@simplewebauthn/browser';

export { browserSupportsWebAuthn, browserSupportsWebAuthnAutofill };

/** POST to a JSON endpoint with Laravel's CSRF cookie, returning the parsed body. */
async function postJson<T>(url: string): Promise<T> {
    const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
    });

    if (!response.ok) throw new Error(`Request failed (${response.status})`);

    return response.json() as Promise<T>;
}

/** Create a passkey for the signed-in user. Returns the credential JSON to post back. */
export async function createPasskey(): Promise<string> {
    const optionsJSON = await postJson<Parameters<typeof startRegistration>[0]['optionsJSON']>(route('passkeys.options'));

    return JSON.stringify(await startRegistration({ optionsJSON }));
}

/**
 * Sign in with a passkey. With `autofill`, the request waits quietly for
 * the user to pick a passkey from the email field's autofill (conditional
 * mediation). Starting a new request aborts any pending one.
 */
export async function getPasskeyAssertion(autofill = false): Promise<string> {
    const optionsJSON = await postJson<Parameters<typeof startAuthentication>[0]['optionsJSON']>(route('passkeys.login.options'));

    return JSON.stringify(await startAuthentication({ optionsJSON, useBrowserAutofill: autofill }));
}

/** True for the user backing out of the browser's sheet (or another ceremony aborting this one). Not worth an error message. */
export function isCancellation(error: unknown): boolean {
    if (error instanceof WebAuthnError && error.code === 'ERROR_CEREMONY_ABORTED') return true;
    const name = error instanceof WebAuthnError ? (error.cause as Error | undefined)?.name : (error as Error | undefined)?.name;

    return name === 'NotAllowedError' || name === 'AbortError';
}

/** The authenticator already holds one of this user's passkeys (excludeCredentials matched). */
export function isAlreadyRegistered(error: unknown): boolean {
    return error instanceof WebAuthnError && error.code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED';
}

/** A default name for a new passkey, from the device it's created on. The server prefers a known provider name. */
export function deviceName(): string {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua)) return 'iPad';
    if (/Android/.test(ua)) return 'Android';
    if (/Macintosh/.test(ua)) return 'Mac';
    if (/Windows/.test(ua)) return 'Windows';
    if (/Linux/.test(ua)) return 'Linux';

    return 'Passkey';
}
