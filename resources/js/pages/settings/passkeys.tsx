import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, LoaderCircle, Pencil, Trash2 } from 'lucide-react';
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

interface Passkey {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Passkeys',
        href: '/settings/passkeys',
    },
];

export default function Passkeys({ passkeys }: { passkeys: Passkey[] }) {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string | undefined> }>().props;
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const supported = typeof window !== 'undefined' && browserSupportsWebAuthn();

    const add = async () => {
        setMessage(null);
        setBusy(true);
        try {
            const credential = await createPasskey();
            router.post(route('passkeys.store'), { credential, device: deviceName() }, { preserveScroll: true, onFinish: () => setBusy(false) });
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
            <Head title="Passkeys" />

            <SettingsLayout>
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

function PasskeyRow({ passkey }: { passkey: Passkey }) {
    const [renaming, setRenaming] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({ name: passkey.name });

    const rename: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('passkeys.update', passkey.id), { preserveScroll: true, onSuccess: () => setRenaming(false) });
    };

    const remove = () => router.delete(route('passkeys.destroy', passkey.id), { preserveScroll: true });

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
