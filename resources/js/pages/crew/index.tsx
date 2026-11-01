import { RoleBadge, RoleLegend } from '@/components/crew-roles';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import crewRoutes from '@/routes/crew';
import { type BreadcrumbItem, type CrewRole, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Mail, Plus, Ship } from 'lucide-react';
import { FormEventHandler } from 'react';

interface CrewSummary {
    id: number;
    name: string;
    role: CrewRole;
    crew_count: number;
    passenger_count: number;
}

interface PendingInvite {
    id: number;
    crew: string;
    role: CrewRole;
    invited_by: string | null;
    /** Set when it gives you the account for a passenger of that name. */
    passenger: string | null;
    expires_at: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Crew', href: '/crew' }];

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

export default function CrewIndex({ crews, invites }: { crews: CrewSummary[]; invites: PendingInvite[] }) {
    const { flash, errors: pageErrors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });

    const create: FormEventHandler = (e) => {
        e.preventDefault();
        post(crewRoutes.store.url(), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Crew" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading title="Crew" description="Everything you share lives here — your household, your clubs." />

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}

                {pageErrors.invite && (
                    <p role="alert" className="bg-destructive/10 text-destructive rounded-lg px-4 py-3 text-sm">
                        {pageErrors.invite}
                    </p>
                )}

                {invites.length > 0 && (
                    <section aria-labelledby="invites" className="space-y-3">
                        <h2 id="invites" className="text-sm font-medium">
                            Waiting for you
                        </h2>
                        <ul className="space-y-2">
                            {invites.map((invite) => (
                                <li key={invite.id} className="bg-card flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center">
                                    <Mail className="text-primary size-5 shrink-0" aria-hidden />
                                    <p className="flex-1 text-sm">
                                        <span className="font-medium">{invite.invited_by ?? 'Someone'}</span> invited you to join{' '}
                                        <span className="font-medium">{invite.crew}</span> as {invite.role === 'captain' ? 'a captain' : 'crew'}
                                        {invite.passenger && <> — you're aboard as {invite.passenger}, and that all comes with you</>}.
                                    </p>
                                    <div className="flex gap-2">
                                        <Button size="sm" onClick={() => router.post(crewRoutes.invites.accept.url(invite.id))}>
                                            Accept
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => router.post(crewRoutes.invites.decline.url(invite.id), {}, { preserveScroll: true })}
                                        >
                                            Decline
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <RoleLegend />

                <section aria-labelledby="your-crews" className="space-y-3">
                    <h2 id="your-crews" className="text-sm font-medium">
                        Your crews
                    </h2>
                    {crews.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">
                            Not aboard any crew yet. Start one for your household below.
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {crews.map((crew) => (
                                <li key={crew.id}>
                                    <Link
                                        href={crewRoutes.show.url(crew.id)}
                                        className="bg-card hover:bg-accent/40 flex items-center gap-3 rounded-xl border p-4 transition-colors"
                                    >
                                        <span className="bg-accent text-accent-foreground flex size-10 shrink-0 items-center justify-center rounded-lg">
                                            <Ship className="size-5" aria-hidden />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium">{crew.name}</span>
                                            <span className="text-muted-foreground block text-sm">
                                                {plural(crew.crew_count, 'account', 'accounts')}
                                                {crew.passenger_count > 0 && <> · {plural(crew.passenger_count, 'passenger', 'passengers')}</>}
                                            </span>
                                        </span>
                                        <RoleBadge role={crew.role} />
                                        <ChevronRight className="text-muted-foreground size-4" aria-hidden />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <form onSubmit={create} className="bg-card space-y-3 rounded-xl border p-4">
                    <Label htmlFor="crew-name">Start a new crew</Label>
                    <div className="flex gap-2">
                        <Input
                            id="crew-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Home"
                            maxLength={60}
                            required
                        />
                        <Button disabled={processing}>
                            <Plus /> Create
                        </Button>
                    </div>
                    <InputError message={errors.name} />
                    <p className="text-muted-foreground text-sm">You'll be its captain. Invite your crew and add passengers next.</p>
                </form>
            </div>
        </AppLayout>
    );
}
