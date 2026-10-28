import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { type CrewRole } from '@/types';

interface JoinProps {
    crew: string;
    invitedBy: string | null;
    role: CrewRole;
    /** The address the invite went to. */
    email: string;
    /**
     * answer: signed in as that address · other: signed in as someone else ·
     * sign_in: that address has an account · new: it doesn't, yet.
     */
    state: 'answer' | 'other' | 'sign_in' | 'new';
    signedInAs: string | null;
    /** The signed link itself — the POST is checked the same way. */
    action: string;
}

type Answer = 'accept' | 'decline' | 'switch';

/**
 * Where a crew invite's emailed link lands (spec §15: the invite is the
 * signup). Opening it changes nothing; the buttons answer.
 */
export default function Join({ crew, invitedBy, role, email, state, signedInAs, action }: JoinProps) {
    const { data, setData, transform, post, processing, errors } = useForm({
        answer: 'accept' as Answer,
        name: '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    });

    const send = (answer: Answer) => {
        transform((form) => ({ ...form, answer }));
        post(action);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send('accept');
    };

    const who = invitedBy ?? 'Someone';
    const asRole = role === 'captain' ? 'a captain' : 'crew';

    const decline = (
        <Button type="button" variant="ghost" className="w-full" onClick={() => send('decline')} disabled={processing}>
            No thanks
        </Button>
    );

    return (
        <AuthLayout title={`Join ${crew}`} description={`${who} invited ${email} to join ${crew} on Offing as ${asRole}.`}>
            <Head title={`Join ${crew}`} />

            {state === 'new' && (
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Your name</Label>
                        <Input
                            id="name"
                            required
                            autoFocus
                            autoComplete="name"
                            maxLength={255}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <InputError message={errors.name ?? (errors as Record<string, string | undefined>).email} />
                    </div>

                    <Button className="w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Join {crew}
                    </Button>
                    {decline}

                    <p className="text-muted-foreground text-center text-sm">
                        That's all it takes — no password, now or ever. You'll sign in with {email}.
                    </p>
                </form>
            )}

            {state === 'answer' && (
                <div className="grid gap-4">
                    <Button className="w-full" onClick={() => send('accept')} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Join {crew}
                    </Button>
                    {decline}
                    <p className="text-muted-foreground text-center text-sm">Nothing is shared until you join.</p>
                </div>
            )}

            {state === 'sign_in' && (
                <div className="grid gap-4">
                    <Button className="w-full" onClick={() => send('accept')} disabled={processing}>
                        Sign in as {email} to join
                    </Button>
                    {decline}
                    <p className="text-muted-foreground text-center text-sm">
                        After signing in on this device you'll come straight back here. Signed in somewhere else? Open this link again there.
                    </p>
                </div>
            )}

            {state === 'other' && (
                <div className="grid gap-4">
                    <p className="text-muted-foreground text-center text-sm">
                        You're signed in here as <span className="text-foreground font-medium">{signedInAs}</span>. This invite is for {email}.
                    </p>
                    <Button className="w-full" onClick={() => send('switch')} disabled={processing}>
                        Sign out and continue
                    </Button>
                    <p className="text-muted-foreground text-center text-sm">Not for you? Close this page — nothing happens.</p>
                </div>
            )}
        </AuthLayout>
    );
}
