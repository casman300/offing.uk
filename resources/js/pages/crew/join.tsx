import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { type CrewRole, type SharedData } from '@/types';

interface JoinProps {
    crew: string;
    invitedBy: string | null;
    role: CrewRole;
    /** The address the invite went to (or, for confirm, the one being proved). Null for a copied link. */
    email: string | null;
    /** Who the captain made it for: a passenger's name, or a copied link's. */
    name: string | null;
    /** It gives a passenger their own account. */
    passenger: boolean;
    /**
     * answer: signed in as that address (or anyone, for a copied link) ·
     * other: signed in as someone else · sign_in: that address has an
     * account · new: it doesn't, yet · link: a copied link, not signed in ·
     * confirm: the emailed "finish joining" step of a copied link.
     */
    state: 'answer' | 'other' | 'sign_in' | 'new' | 'link' | 'confirm';
    signedInAs: string | null;
    /** The signed link itself — the POST is checked the same way. */
    action: string;
}

type Answer = 'accept' | 'decline' | 'switch';

/**
 * Where a crew invite's link lands (spec §15: the invite is the signup).
 * Opening it changes nothing; the buttons answer.
 */
export default function Join({ crew, invitedBy, role, email, name, passenger, state, signedInAs, action }: JoinProps) {
    const { flash } = usePage<SharedData>().props;
    const { data, setData, transform, post, processing, errors } = useForm({
        answer: 'accept' as Answer,
        name: name ?? '',
        email: '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    });

    const send = (answer: Answer) => {
        transform((form) => ({ ...form, answer }));
        post(action, { preserveScroll: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send('accept');
    };

    const who = invitedBy ?? 'Someone';
    const asRole = role === 'captain' ? 'a captain' : 'crew';
    const description =
        state === 'confirm'
            ? `You're joining ${crew} as ${email}.`
            : passenger
              ? `${who} invited you to your own account in ${crew}, as ${asRole}. You're aboard as ${name} already — everything so far comes with you.`
              : `${who} invited ${email ?? 'you'} to join ${crew} on Offing as ${asRole}.`;

    const decline = (
        <Button type="button" variant="ghost" className="w-full" onClick={() => send('decline')} disabled={processing}>
            No thanks
        </Button>
    );

    const nameField = (
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
            <InputError message={errors.name} />
        </div>
    );

    return (
        <AuthLayout title={state === 'confirm' ? `Finish joining ${crew}` : `Join ${crew}`} description={description}>
            <Head title={`Join ${crew}`} />

            {flash.status && (
                <p role="status" className="bg-accent text-accent-foreground mb-4 rounded-lg px-4 py-3 text-center text-sm">
                    {flash.status}
                </p>
            )}

            {state === 'new' && (
                <form onSubmit={submit} className="grid gap-4">
                    {nameField}
                    <InputError message={(errors as Record<string, string | undefined>).email} />

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

            {state === 'link' && (
                <form onSubmit={submit} className="grid gap-4">
                    {nameField}
                    <div className="grid gap-2">
                        <Label htmlFor="email">Your email</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <Button className="w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Email me a link to join
                    </Button>
                    {decline}

                    <p className="text-muted-foreground text-center text-sm">
                        No password, now or ever. Already on Offing?{' '}
                        <Link href={login.url()} className="text-foreground font-medium underline underline-offset-4">
                            Sign in
                        </Link>{' '}
                        and you'll come straight back here.
                    </p>
                </form>
            )}

            {state === 'confirm' && (
                <div className="grid gap-4">
                    {signedInAs && (
                        <p className="text-muted-foreground text-center text-sm">
                            You're currently signed in here as <span className="text-foreground font-medium">{signedInAs}</span>. Continuing signs
                            that account out.
                        </p>
                    )}
                    <Button className="w-full" onClick={() => send('accept')} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Join {crew} as {email}
                    </Button>
                    <p className="text-muted-foreground text-center text-sm">
                        Not your email? Close this page — nothing happens unless you continue.
                    </p>
                </div>
            )}

            {state === 'answer' && (
                <div className="grid gap-4">
                    <Button className="w-full" onClick={() => send('accept')} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Join {crew}
                        {signedInAs && ` as ${signedInAs}`}
                    </Button>
                    {decline}
                    {signedInAs ? (
                        <Button type="button" variant="link" className="w-full" onClick={() => send('switch')} disabled={processing}>
                            Not you? Sign out first
                        </Button>
                    ) : (
                        <p className="text-muted-foreground text-center text-sm">Nothing is shared until you join.</p>
                    )}
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
