import { ChoreRow } from '@/components/shipshape/chore-row';
import { LoggedToast } from '@/components/shipshape/logged-toast';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type ChoreRow as Chore, type ListSummary } from '@/lib/shipshape';
import people from '@/routes/people';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Anchor, Cake, ChevronRight } from 'lucide-react';
import { useMemo } from 'react';

interface UpcomingBirthday {
    id: number;
    name: string;
    days: number;
    date: string | null;
    turns: number | null;
}

interface Props {
    today: string;
    chores: Chore[] | null;
    lists: ListSummary[];
    birthdays: UpcomingBirthday[] | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const when = (days: number, date: string | null) => {
    if (days === 0) return 'Today';
    if (days === 1) return 'Tomorrow';
    return date
        ? new Date(`${date}T12:00:00`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
        : `In ${days} days`;
};

/** "In the offing" — today's and overdue chores, birthdays coming up (spec §25). */
export default function Dashboard({ today, chores, lists, birthdays }: Props) {
    const { auth, flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const byId = useMemo(() => Object.fromEntries(lists.map((list) => [list.id, list])), [lists]);
    const longDate = new Date(`${today}T12:00:00`).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
    const nothingOn = chores === null && birthdays === null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 pb-28">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">In the offing</h1>
                    <p className="text-muted-foreground text-sm">
                        {longDate} · hello {auth.user.name.split(' ')[0]}
                    </p>
                </div>

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}
                {errors.email && (
                    <p role="alert" className="bg-destructive/10 text-destructive rounded-lg px-4 py-3 text-sm">
                        {errors.email}
                    </p>
                )}

                <LoggedToast />

                {chores !== null && (
                    <section aria-labelledby="dash-chores" className="space-y-2">
                        <div className="flex items-center justify-between">
                            <h2 id="dash-chores" className="flex items-center gap-2 text-xs font-bold tracking-wider uppercase">
                                <Anchor className="size-4" aria-hidden /> Today's chores
                            </h2>
                            <Link href="/shipshape" className="text-primary flex items-center text-sm font-medium">
                                Shipshape <ChevronRight className="size-4" aria-hidden />
                            </Link>
                        </div>
                        {chores.length === 0 ? (
                            <p className="text-muted-foreground bg-card rounded-xl border p-4 text-sm">
                                {lists.length === 0 ? 'No chore lists yet.' : 'All shipshape — nothing due today.'}
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {chores.map((chore) => (
                                    <ChoreRow key={chore.id} chore={chore} list={byId[chore.list_id]} today={today} />
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {birthdays !== null && (
                    <section aria-labelledby="dash-birthdays" className="space-y-2">
                        <h2 id="dash-birthdays" className="flex items-center gap-2 text-xs font-bold tracking-wider uppercase">
                            <Cake className="size-4" aria-hidden /> Birthdays coming up
                        </h2>
                        {birthdays.length === 0 ? (
                            <p className="text-muted-foreground bg-card rounded-xl border p-4 text-sm">None in the next two weeks.</p>
                        ) : (
                            <ul className="bg-card divide-y rounded-xl border">
                                {birthdays.map((b) => (
                                    <li key={b.id}>
                                        <Link href={people.show.url(b.id)} className="hover:bg-accent/40 flex items-center gap-3 px-4 py-3">
                                            <span className="flex-1 font-medium">{b.name}</span>
                                            <span className="text-muted-foreground text-sm">
                                                {when(b.days, b.date)}
                                                {b.turns !== null && <> · turns {b.turns}</>}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {nothingOn && (
                    <div className="bg-card space-y-2 rounded-xl border p-6 text-center">
                        <p className="font-medium">Nothing in the offing yet.</p>
                        <p className="text-muted-foreground text-sm">Features are switched on as they're ready. Check back soon.</p>
                        <Button asChild variant="outline">
                            <Link href="/settings/profile">Settings</Link>
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
