import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { browserSupportsWebAuthn, browserSupportsWebAuthnAutofill, getPasskeyAssertion, isCancellation } from '@/lib/passkeys';
import { store as loginStore } from '@/routes/login';
import passkeys from '@/routes/passkeys';

interface LoginProps {
    status?: string;
}

export default function Login({ status }: LoginProps) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        remember: true as boolean,
    });
    const passkeyError = (usePage().props.errors as Record<string, string | undefined>).passkey;
    const [passkeyBusy, setPasskeyBusy] = useState(false);
    const [passkeyFailed, setPasskeyFailed] = useState<string | null>(null);
    const supportsPasskeys = typeof window !== 'undefined' && browserSupportsWebAuthn();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(loginStore.url());
    };

    const finishPasskey = (credential: string) => router.post(passkeys.login.url(), { credential }, { onFinish: () => setPasskeyBusy(false) });

    // Offer saved passkeys in the email field's autofill. It waits quietly;
    // nothing happens unless the user picks one.
    const armAutofill = useCallback(async () => {
        if (!(await browserSupportsWebAuthnAutofill())) return;
        try {
            finishPasskey(await getPasskeyAssertion(true));
        } catch {
            // Aborted by the button flow, or the user dismissed autofill.
        }
    }, []);

    useEffect(() => {
        armAutofill();
    }, [armAutofill]);

    const signInWithPasskey = async () => {
        setPasskeyFailed(null);
        setPasskeyBusy(true);
        try {
            finishPasskey(await getPasskeyAssertion());
        } catch (error) {
            setPasskeyBusy(false);
            if (!isCancellation(error)) setPasskeyFailed("That didn't work. Try again, or get a magic link instead.");
            armAutofill();
        }
    };

    return (
        <AuthLayout title="Log in to offing.uk" description="Use a passkey, or enter your email and we'll send you a magic link. No password needed.">
            <Head title="Log in" />

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}

            {supportsPasskeys && (
                <div className="mb-6 grid gap-2">
                    <Button type="button" variant="outline" className="w-full" onClick={signInWithPasskey} disabled={passkeyBusy}>
                        {passkeyBusy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                        Sign in with a passkey
                    </Button>
                    <InputError message={passkeyFailed ?? passkeyError} />
                    <div className="text-muted-foreground mt-4 flex items-center gap-3 text-xs uppercase">
                        <span className="bg-border h-px flex-1" />
                        or
                        <span className="bg-border h-px flex-1" />
                    </div>
                </div>
            )}

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="email webauthn"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="email@example.com"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="flex items-center space-x-3">
                        <Checkbox
                            id="remember"
                            name="remember"
                            tabIndex={2}
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                        />
                        <Label htmlFor="remember">Keep me logged in on this device</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={3} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Send me a magic link
                    </Button>
                </div>

                <p className="text-muted-foreground text-center text-sm">
                    offing.uk is invite-only for now — if your email's on the list, the link will land in your inbox.
                </p>
            </form>
        </AuthLayout>
    );
}
