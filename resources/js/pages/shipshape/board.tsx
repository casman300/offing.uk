import Heading from '@/components/heading';
import { ChoreRow } from '@/components/shipshape/chore-row';
import { LoggedToast } from '@/components/shipshape/logged-toast';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type ChoreRow as Chore, GROUPS, type ListSummary } from '@/lib/shipshape';
import { cn } from '@/lib/utils';
import shipshape from '@/routes/shipshape';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Anchor, ListChecks } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shipshape', href: '/shipshape' }];

export default function Board({ today, lists, chores }: { today: string; lists: ListSummary[]; chores: Chore[] }) {
    const { flash } = usePage<SharedData>().props;
    const [filter, setFilter] = useState<number | 'all'>('all');
    const byId = useMemo(() => Object.fromEntries(lists.map((list) => [list.id, list])), [lists]);
    const visible = filter === 'all' ? chores : chores.filter((chore) => chore.list_id === filter);

    const overdue = visible.filter((c) => c.group === 'overdue').length;
    const dueToday = visible.filter((c) => c.group === 'today').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shipshape" />

            <div className="mx-auto w-full max-w-2xl space-y-5 p-4 pb-28">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Shipshape"
                        description={
                            chores.length === 0
                                ? 'Your household chores.'
                                : [overdue ? `${overdue} overdue` : null, dueToday ? `${dueToday} left today` : 'nothing left today']
                                      .filter(Boolean)
                                      .join(' · ')
                        }
                    />
                    <Button asChild variant="outline" size="sm" className="shrink-0">
                        <Link href={shipshape.lists.index.url()}>
                            <ListChecks /> Lists
                        </Link>
                    </Button>
                </div>

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}
                <LoggedToast />

                {lists.length > 1 && (
                    <div role="group" aria-label="Filter by list" className="flex flex-wrap gap-2">
                        {[{ id: 'all' as const, name: 'All lists' }, ...lists].map((list) => (
                            <button
                                key={list.id}
                                type="button"
                                aria-pressed={filter === list.id}
                                onClick={() => setFilter(list.id)}
                                className={cn(
                                    'h-9 rounded-full border px-4 text-sm font-medium',
                                    filter === list.id ? 'bg-primary text-primary-foreground border-primary' : 'bg-card hover:bg-accent',
                                )}
                            >
                                {list.name}
                            </button>
                        ))}
                    </div>
                )}

                {lists.length === 0 ? (
                    <EmptyState />
                ) : visible.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">No chores here yet.</p>
                ) : (
                    GROUPS.map((group) => {
                        const items = visible.filter((chore) => chore.group === group.key);
                        if (items.length === 0) return null;

                        return (
                            <section key={group.key} aria-labelledby={`group-${group.key}`} className="space-y-2">
                                <h2
                                    id={`group-${group.key}`}
                                    className={cn(
                                        'text-xs font-bold tracking-wider uppercase',
                                        group.key === 'overdue' ? 'text-orange-800 dark:text-orange-300' : 'text-foreground',
                                    )}
                                >
                                    {group.label} <span className="text-muted-foreground font-medium">{items.length}</span>
                                </h2>
                                {group.hint && <p className="text-muted-foreground text-sm">{group.hint}</p>}
                                <ul className="space-y-2">
                                    {items.map((chore) => (
                                        <ChoreRow key={chore.id} chore={chore} list={byId[chore.list_id]} today={today} showList={lists.length > 1} />
                                    ))}
                                </ul>
                            </section>
                        );
                    })
                )}
            </div>
        </AppLayout>
    );
}

function EmptyState() {
    return (
        <div className="bg-card space-y-3 rounded-xl border p-6 text-center">
            <Anchor className="text-primary mx-auto size-8" aria-hidden />
            <p className="font-medium">Nothing on the board yet.</p>
            <p className="text-muted-foreground text-sm">
                Start a list — just for you, or shared with your crew — and we'll suggest some chores to get going.
            </p>
            <Button asChild>
                <Link href={shipshape.lists.index.url()}>Start a list</Link>
            </Button>
        </div>
    );
}
