import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, LoaderCircle, LogOut, Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { browserSupportsWebAuthn, createPasskey, deviceName, isAlreadyRegistered, isCancellation } from '@/lib/passkeys';
import devices from '@/routes/devices';
import passkeyRoutes from '@/routes/passkeys';

interface Passkey {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
}

interface SignIn {
    id: number;
    name: string;
    method: 'magic_link' | 'passkey' | null;
    current: boolean;
    signed_in_at: string | null;
    last_active_at: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Sign-in & devices',
        href: '/settings/devices',
    },
];

export default function Devices({ passkeys, signIns }: { passkeys: Passkey[]; signIns: SignIn[] | null }) {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string | undefined> }>().props;
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const supported = typeof window !== 'undefined' && browserSupportsWebAuthn();

    const add = async () => {
        setMessage(null);
        setBusy(true);
        try {
            const credential = await createPasskey();
            router.post(passkeyRoutes.store.url(), { credential, device: deviceName() }, { preserveScroll: true, onFinish: () => setBusy(false) });
        } catch (error) {
            setBusy(false);
            if (isAlreadyRegistered(error)) {
                setMessage('This device already has one of your passkeys.');
            } else if (!isCancellation(error)) {
                setMessage("That didn't work. Please try again.");
            }
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sign-in & devices" />

            <SettingsLayout>
                {signIns && <SignedIn signIns={signIns} signedOutOthers={flash.status === 'signed-out-others'} />}

                <div className="space-y-6">
                    <HeadingSmall
                        title="Passkeys"
                        description="Sign in with Face ID, Touch ID or your device PIN instead of waiting for an email. Magic links keep working too."
                    />

                    {passkeys.length > 0 ? (
                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {passkeys.map((passkey) => (
                                <PasskeyRow key={passkey.id} passkey={passkey} />
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground text-sm">You haven’t added a passkey yet.</p>
                    )}

                    {flash.status === 'passkey-added' && (
                        <p role="status" className="text-sm text-green-600">
                            Passkey added. We’ve emailed you to confirm.
                        </p>
                    )}

                    {supported ? (
                        <div className="space-y-2">
                            <Button onClick={add} disabled={busy}>
                                {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                                Add a passkey
                            </Button>
                            <InputError message={message ?? errors.passkey} />
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            This browser can’t create passkeys. Try Safari, Chrome, Edge or Firefox on a recent device.
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function SignedIn({ signIns, signedOutOthers }: { signIns: SignIn[]; signedOutOthers: boolean }) {
    const others = signIns.filter((signIn) => !signIn.current).length;
    const [processing, setProcessing] = useState(false);

    const signOutOthers = () =>
        router.post(
            devices.signOutOthers.url(),
            {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );

    return (
        <div className="space-y-6">
            <HeadingSmall title="Where you’re signed in" description="Every browser and device signed in to your account." />

            <ul className="bg-card divide-border divide-y rounded-xl border">
                {signIns.map((signIn) => (
                    <li key={signIn.id} className="px-4 py-3">
                        <p className="font-medium">
                            {signIn.name}
                            {signIn.current && <span className="text-muted-foreground ml-2 text-sm font-normal">This device</span>}
                        </p>
                        <p className="text-muted-foreground text-sm">
                            {signIn.signed_in_at
                                ? `Signed in ${formatDate(signIn.signed_in_at)}${signIn.method ? ` with ${METHODS[signIn.method]}` : ''}`
                                : 'Signed in before Offing kept track'}
                            {' · '}
                            {signIn.current ? 'active now' : `active ${relativeTime(signIn.last_active_at)}`}
                        </p>
                    </li>
                ))}
            </ul>

            {signedOutOthers && (
                <p role="status" className="text-sm text-green-600">
                    Signed out everywhere else.
                </p>
            )}

            {others > 0 && (
                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="outline">
                            <LogOut className="h-4 w-4" />
                            Sign out everywhere else
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Sign out everywhere else?</DialogTitle>
                        <DialogDescription>
                            {others === 1 ? 'The other device' : `The other ${others} devices`} will need an email link or passkey to get back in.
                            You’ll stay signed in here.
                        </DialogDescription>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <DialogClose asChild>
                                <Button variant="destructive" onClick={signOutOthers} disabled={processing}>
                                    Sign out
                                </Button>
                            </DialogClose>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}

const METHODS: Record<NonNullable<SignIn['method']>, string> = {
    magic_link: 'an email link',
    passkey: 'a passkey',
};

function PasskeyRow({ passkey }: { passkey: Passkey }) {
    const [renaming, setRenaming] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({ name: passkey.name });

    const rename: FormEventHandler = (e) => {
        e.preventDefault();
        patch(passkeyRoutes.update.url(passkey.id), { preserveScroll: true, onSuccess: () => setRenaming(false) });
    };

    const remove = () => router.delete(passkeyRoutes.destroy.url(passkey.id), { preserveScroll: true });

    return (
        <li className="flex items-center justify-between gap-4 px-4 py-3">
            <div className="min-w-0">
                <p className="truncate font-medium">{passkey.name}</p>
                <p className="text-muted-foreground text-sm">
                    {passkey.last_used_at ? `Last used ${formatDate(passkey.last_used_at)}` : 'Not used yet'} · added {formatDate(passkey.created_at)}
                </p>
            </div>
            <div className="flex shrink-0">
                <Dialog open={renaming} onOpenChange={setRenaming}>
                    <DialogTrigger asChild>
                        <Button variant="ghost" aria-label={`Rename ${passkey.name}`}>
                            <Pencil />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <form onSubmit={rename} className="space-y-4">
                            <DialogTitle>Rename passkey</DialogTitle>
                            <div className="grid gap-2">
                                <Label htmlFor={`passkey-name-${passkey.id}`}>Name</Label>
                                <Input
                                    id={`passkey-name-${passkey.id}`}
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    maxLength={50}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button disabled={processing}>Save</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="ghost" aria-label={`Remove ${passkey.name}`}>
                            <Trash2 />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Remove “{passkey.name}”?</DialogTitle>
                        <DialogDescription>
                            It won’t be able to sign you in any more. You may also want to delete it from your device’s password manager.
                        </DialogDescription>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button variant="destructive" onClick={remove}>
                                Remove
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </li>
    );
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function relativeTime(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }

    return 'just now';
}
