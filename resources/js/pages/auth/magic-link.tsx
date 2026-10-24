import { Head, router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface MagicLinkProps {
    /** The account this link signs in to. */
    email: string;
    /** Someone else is signed in on this browser; continuing signs them out. */
    signedInAs: string | null;
    /** The signed link itself — the POST is checked the same way. */
    action: string;
}

/**
 * Where a magic link lands (security audit M1). Opening the link doesn't
 * sign in — mail scanners open links too, and a link someone else sent you
 * should say whose account it is first. The button does.
 */
export default function MagicLink({ email, signedInAs, action }: MagicLinkProps) {
    const [busy, setBusy] = useState(false);

    const submit = () => {
        setBusy(true);
        router.post(action, {}, { onFinish: () => setBusy(false) });
    };

    return (
        <AuthLayout title="Sign in to Offing" description={`You’re signing in as ${email}.`}>
            <Head title="Sign in" />

            <div className="grid gap-4">
                {signedInAs && (
                    <p className="text-muted-foreground text-center text-sm">
                        You’re currently signed in here as <span className="text-foreground font-medium">{signedInAs}</span>. Continuing signs that
                        account out.
                    </p>
                )}

                <Button className="w-full" onClick={submit} disabled={busy}>
                    {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Continue as {email}
                </Button>

                <p className="text-muted-foreground text-center text-sm">Not your email? Close this page — nothing happens unless you continue.</p>
            </div>
        </AuthLayout>
    );
}
