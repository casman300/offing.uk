import { ROLE_LABELS, RoleBadge } from '@/components/crew-roles';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import crewRoutes from '@/routes/crew';
import { type BreadcrumbItem, type CrewRole, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { LogOut, Mail, Pencil, Trash2, UserPlus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Member {
    id: number;
    name: string;
    email: string | null;
    role: CrewRole;
    is_me: boolean;
    is_account: boolean;
    /** Shown to captains: features this crewmate needs that aren't on for them yet. */
    missing_features: string[];
}

interface Invite {
    id: number;
    email: string;
    role: CrewRole;
    expires_at: string;
}

interface Props {
    crew: { id: number; name: string };
    me: { id: number; role: CrewRole };
    members: Member[];
    formerPassengers: Member[];
    captainCount: number;
    invites: Invite[];
    myPeople: MyPerson[];
}

interface MyPerson {
    id: number;
    name: string;
    has_birthday: boolean;
    aboard: boolean;
}

const selectClass =
    'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden';

export default function CrewShow({ crew, me, members, formerPassengers, captainCount, invites, myPeople }: Props) {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const isCaptain = me.role === 'captain';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Crew', href: '/crew' },
        { title: crew.name, href: `/crew/${crew.id}` },
    ];

    const opts = { preserveScroll: true };
    const setRole = (member: Member, role: string) => router.patch(crewRoutes.members.role.url({ crew: crew.id, member: member.id }), { role }, opts);
    const remove = (member: Member) => router.delete(crewRoutes.members.remove.url({ crew: crew.id, member: member.id }), opts);
    const readd = (member: Member) => router.post(crewRoutes.members.readd.url({ crew: crew.id, member: member.id }), {}, opts);
    const revoke = (invite: Invite) => router.delete(crewRoutes.invites.revoke.url({ crew: crew.id, invite: invite.id }), opts);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={crew.name} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{crew.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            You're {me.role === 'captain' ? 'a captain' : 'crew'} here. Everything shared with {crew.name} is visible to all its crew.
                        </p>
                    </div>
                    {isCaptain && <RenameDialog crew={crew} />}
                </div>

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}
                {(errors.role || errors.member || errors.leave) && (
                    <p role="alert" className="bg-destructive/10 text-destructive rounded-lg px-4 py-3 text-sm">
                        {errors.role || errors.member || errors.leave}
                    </p>
                )}

                <section aria-labelledby="aboard" className="space-y-3">
                    <h2 id="aboard" className="text-sm font-medium">
                        Aboard
                    </h2>
                    <ul className="bg-card divide-border divide-y rounded-xl border">
                        {members.map((member) => (
                            <li key={member.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <Avatar member={member} />
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">
                                        {member.name}
                                        {member.is_me && <span className="text-muted-foreground font-normal"> (you)</span>}
                                    </p>
                                    {member.email && <p className="text-muted-foreground truncate text-sm">{member.email}</p>}
                                    {!member.is_account && <p className="text-muted-foreground text-sm">No account — crewmates act for them</p>}
                                    {member.missing_features.length > 0 && (
                                        <p className="text-sm text-amber-700 dark:text-amber-400">
                                            {member.missing_features.join(' and ')} {member.missing_features.length > 1 ? "aren't" : "isn't"} switched
                                            on for them yet, so they can't see this crew's things. Ask whoever runs your Offing.
                                        </p>
                                    )}
                                </div>

                                {isCaptain && member.is_account && !member.is_me ? (
                                    <div className="flex items-center gap-2">
                                        <label className="sr-only" htmlFor={`role-${member.id}`}>
                                            Role for {member.name}
                                        </label>
                                        <select
                                            id={`role-${member.id}`}
                                            className={selectClass}
                                            value={member.role}
                                            onChange={(e) => setRole(member, e.target.value)}
                                        >
                                            <option value="captain">{ROLE_LABELS.captain}</option>
                                            <option value="crew">{ROLE_LABELS.crew}</option>
                                        </select>
                                        <ConfirmRemove member={member} crewName={crew.name} onConfirm={() => remove(member)} />
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <RoleBadge role={member.role} />
                                        {isCaptain && !member.is_account && (
                                            <ConfirmRemove member={member} crewName={crew.name} onConfirm={() => remove(member)} />
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>

                    {formerPassengers.length > 0 && (
                        <details className="bg-card rounded-xl border">
                            <summary className="text-muted-foreground cursor-pointer px-4 py-3 text-sm font-medium">
                                Former passengers ({formerPassengers.length})
                            </summary>
                            <ul className="divide-border divide-y border-t">
                                {formerPassengers.map((member) => (
                                    <li key={member.id} className="flex items-center gap-3 px-4 py-2">
                                        <span className="text-muted-foreground flex-1 text-sm">
                                            {member.name} · still in {crew.name}'s people
                                        </span>
                                        <Button size="sm" variant="outline" onClick={() => readd(member)}>
                                            Re-add
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </section>

                <div className={cn('grid gap-4', isCaptain && 'sm:grid-cols-2')}>
                    {isCaptain && <InviteForm crewId={crew.id} />}
                    <PassengerForm crewId={crew.id} crewName={crew.name} myPeople={myPeople} />
                </div>

                {isCaptain && invites.length > 0 && (
                    <section aria-labelledby="pending" className="space-y-3">
                        <h2 id="pending" className="text-sm font-medium">
                            Invites waiting for an answer
                        </h2>
                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {invites.map((invite) => (
                                <li key={invite.id} className="flex items-center gap-3 px-4 py-3">
                                    <Mail className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{invite.email}</p>
                                        <p className="text-muted-foreground text-xs">
                                            As {ROLE_LABELS[invite.role].toLowerCase()} · expires{' '}
                                            {new Date(invite.expires_at).toLocaleDateString(undefined, { dateStyle: 'medium' })}
                                        </p>
                                    </div>
                                    <Button size="sm" variant="ghost" onClick={() => revoke(invite)}>
                                        Withdraw
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="danger" className="space-y-3 border-t pt-6">
                    <h2 id="danger" className="sr-only">
                        Leave or delete
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        <LeaveDialog crew={crew} lastCaptain={isCaptain && captainCount === 1} />
                        {isCaptain && <DeleteDialog crew={crew} />}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

function Avatar({ member }: { member: Member }) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                member.role === 'captain' && 'bg-primary text-primary-foreground',
                member.role === 'crew' && 'bg-accent text-accent-foreground',
                member.role === 'passenger' && 'text-muted-foreground border border-dashed border-current',
            )}
        >
            {member.name.charAt(0).toUpperCase()}
        </span>
    );
}

function ConfirmRemove({ member, crewName, onConfirm }: { member: Member; crewName: string; onConfirm: () => void }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button size="icon" variant="ghost" aria-label={`Remove ${member.name}`}>
                    <X />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Remove {member.name}?</DialogTitle>
                <DialogDescription>
                    {member.is_account
                        ? `They lose access to everything shared with ${crewName} straight away. Their own lists and People aren't touched.`
                        : `${member.name} stays in ${crewName}'s people as a former passenger, so you can re-add them any time.`}
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <DialogClose asChild>
                        <Button variant="destructive" onClick={onConfirm}>
                            Remove
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function InviteForm({ crewId }: { crewId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', role: 'crew' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(crewRoutes.invites.store.url(crewId), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="bg-card space-y-3 rounded-xl border p-4">
            <h2 className="flex items-center gap-2 text-sm font-medium">
                <Mail className="size-4" aria-hidden /> Invite by email
            </h2>
            <div className="grid gap-2">
                <Label htmlFor="invite-email">Email</Label>
                <Input id="invite-email" type="email" required value={data.email} onChange={(e) => setData('email', e.target.value)} />
                <InputError message={errors.email} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="invite-role">As</Label>
                <select
                    id="invite-role"
                    className={cn(selectClass, 'h-10 w-full')}
                    value={data.role}
                    onChange={(e) => setData('role', e.target.value)}
                >
                    <option value="crew">Crew</option>
                    <option value="captain">Captain</option>
                </select>
            </div>
            <Button disabled={processing} className="w-full">
                Send invite
            </Button>
            <p className="text-muted-foreground text-xs">
                We email them a link — new to Offing, they join with just their name. Nothing is shared until they accept.
            </p>
        </form>
    );
}

function PassengerForm({ crewId, crewName, myPeople }: { crewId: number; crewName: string; myPeople: MyPerson[] }) {
    const available = myPeople.filter((person) => !person.aboard);
    const [source, setSource] = useState<'people' | 'new'>(available.length > 0 ? 'people' : 'new');
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        name: '',
        person_id: '',
        share_birthday: false as boolean,
    });

    const picked = myPeople.find((person) => String(person.id) === data.person_id);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => (source === 'people' ? { person_id: Number(form.person_id), share_birthday: form.share_birthday } : { name: form.name }));
        post(crewRoutes.passengers.store.url(crewId), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="bg-card space-y-3 rounded-xl border p-4">
            <h2 className="flex items-center gap-2 text-sm font-medium">
                <UserPlus className="size-4" aria-hidden /> Add a passenger
            </h2>

            {myPeople.length > 0 && (
                <div role="group" aria-label="Who to add" className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1">
                    {(['people', 'new'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-pressed={source === option}
                            onClick={() => setSource(option)}
                            className={cn(
                                'rounded-md px-2 py-1.5 text-sm font-medium',
                                source === option ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option === 'people' ? 'From my People' : 'Someone new'}
                        </button>
                    ))}
                </div>
            )}

            {source === 'people' ? (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="passenger-person">Person</Label>
                        <select
                            id="passenger-person"
                            required
                            className={cn(selectClass, 'h-10 w-full')}
                            value={data.person_id}
                            onChange={(e) => setData((form) => ({ ...form, person_id: e.target.value, share_birthday: false }))}
                        >
                            <option value="">Choose someone…</option>
                            {myPeople.map((person) => (
                                <option key={person.id} value={person.id} disabled={person.aboard}>
                                    {person.name}
                                    {person.aboard ? ' — already aboard' : ''}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.person_id} />
                    </div>
                    {picked?.has_birthday && (
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="share-birthday"
                                checked={data.share_birthday}
                                onCheckedChange={(checked) => setData('share_birthday', checked === true)}
                                className="mt-0.5"
                            />
                            <Label htmlFor="share-birthday" className="text-sm font-normal">
                                Share their birthday with {crewName}
                            </Label>
                        </div>
                    )}
                    <p className="text-muted-foreground text-xs">
                        {crewName} gets a copy with their name{data.share_birthday ? ' and birthday' : ''} — never your notes. Your own entry stays
                        private.
                    </p>
                </>
            ) : (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="passenger-name">Name</Label>
                        <Input id="passenger-name" required maxLength={100} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <p className="text-muted-foreground text-xs">
                        For someone without an account — a child, a club member. They're added to {crewName} only, not your People.
                    </p>
                </>
            )}

            <Button disabled={processing} variant="outline" className="w-full">
                Add passenger
            </Button>
        </form>
    );
}

function RenameDialog({ crew }: { crew: { id: number; name: string } }) {
    const [open, setOpen] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({ name: crew.name });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(crewRoutes.update.url(crew.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Pencil /> Rename
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>Rename crew</DialogTitle>
                    <div className="grid gap-2">
                        <Label htmlFor="rename">Name</Label>
                        <Input id="rename" value={data.name} maxLength={60} onChange={(e) => setData('name', e.target.value)} required />
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
    );
}

function LeaveDialog({ crew, lastCaptain }: { crew: { id: number; name: string }; lastCaptain: boolean }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <LogOut /> Leave crew
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Leave {crew.name}?</DialogTitle>
                <DialogDescription>
                    {lastCaptain
                        ? "You're the only captain, so make someone else captain first — every crew needs one."
                        : `You'll lose access to everything shared with ${crew.name}. A captain can invite you back.`}
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">Stay</Button>
                    </DialogClose>
                    {!lastCaptain && (
                        <DialogClose asChild>
                            <Button variant="destructive" onClick={() => router.post(crewRoutes.leave.url(crew.id))}>
                                Leave
                            </Button>
                        </DialogClose>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({ crew }: { crew: { id: number; name: string } }) {
    const { data, setData, delete: destroy, processing, errors } = useForm({ confirm: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        destroy(crewRoutes.destroy.url(crew.id), { preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" className="text-destructive">
                    <Trash2 /> Delete crew
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>Delete {crew.name}?</DialogTitle>
                    <DialogDescription>
                        Everyone loses access to what's shared with it. Its passengers move to your own People, so nobody's lost.
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor="confirm">Type “{crew.name}” to confirm</Label>
                        <Input id="confirm" value={data.confirm} onChange={(e) => setData('confirm', e.target.value)} autoComplete="off" />
                        <InputError message={errors.confirm} />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button variant="destructive" disabled={processing}>
                            Delete
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
