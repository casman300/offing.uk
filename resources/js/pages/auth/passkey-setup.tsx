import { Head, router, usePage } from '@inertiajs/react';
import { KeyRound, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { browserSupportsWebAuthn, createPasskey, deviceName, isAlreadyRegistered, isCancellation } from '@/lib/passkeys';
import passkeys from '@/routes/passkeys';

/** "Use Face ID next time?", shown after a magic-link sign-in (spec §15). */
export default function PasskeySetup() {
    const serverError = (usePage().props.errors as Record<string, string | undefined>).passkey;
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const supported = typeof window !== 'undefined' && browserSupportsWebAuthn();

    const notNow = () => router.post(passkeys.setup.dismiss.url());

    const setUp = async () => {
        setMessage(null);
        setBusy(true);
        try {
            const credential = await createPasskey();
            router.post(passkeys.store.url(), { credential, device: deviceName(), from_setup: true }, { onFinish: () => setBusy(false) });
        } catch (error) {
            setBusy(false);
            if (isAlreadyRegistered(error)) {
                setMessage('This device already has a passkey for Offing. Next time, choose “Sign in with a passkey”.');
            } else if (!isCancellation(error)) {
                setMessage("That didn't work. You can try again, or add a passkey later from Settings.");
            }
        }
    };

    return (
        <AuthLayout
            title={supported ? 'Sign in with Face ID next time?' : 'You’re signed in'}
            description={
                supported
                    ? 'Add a passkey and next time you can sign in with Face ID, Touch ID or your device PIN. No email needed.'
                    : 'This browser can’t use passkeys, so keep using magic links here.'
            }
        >
            <Head title="Set up a passkey" />

            <div className="grid gap-3">
                {supported && (
                    <Button className="w-full" onClick={setUp} disabled={busy}>
                        {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                        Set up a passkey
                    </Button>
                )}
                <Button variant={supported ? 'ghost' : 'default'} className="w-full" onClick={notNow} disabled={busy}>
                    {supported ? 'Not now' : 'Continue'}
                </Button>
                <InputError message={message ?? serverError} />
            </div>
        </AuthLayout>
    );
}
