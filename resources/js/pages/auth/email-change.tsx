import { Head, router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface EmailChangeProps {
    mode: 'confirm' | 'revert';
    email: string;
    /** The signed link itself — the POST is checked the same way. */
    action: string;
}

/**
 * Where email-change links land (security audit H1). Opening the link
 * changes nothing — mail scanners open links too — the button does.
 */
export default function EmailChange({ mode, email, action }: EmailChangeProps) {
    const [busy, setBusy] = useState(false);

    const submit = () => {
        setBusy(true);
        router.post(action, {}, { onFinish: () => setBusy(false) });
    };

    const confirm = mode === 'confirm';

    return (
        <AuthLayout
            title={confirm ? 'Confirm your new email' : 'Undo the email change?'}
            description={
                confirm
                    ? `Use ${email} to sign in to Offing from now on.`
                    : `This puts ${email} back on your account, signs out every device, and removes passkeys, calendar feed links, devices and AI connections added since the change.`
            }
        >
            <Head title={confirm ? 'Confirm new email' : 'Undo email change'} />

            <Button className="w-full" variant={confirm ? 'default' : 'destructive'} onClick={submit} disabled={busy}>
                {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                {confirm ? `Confirm ${email}` : 'Undo it and sign out everywhere'}
            </Button>
        </AuthLayout>
    );
}
