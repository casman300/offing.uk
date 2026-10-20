import { ChoreRow } from '@/components/shipshape/chore-row';
import { LoggedToast } from '@/components/shipshape/logged-toast';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type ChoreRow as Chore, GROUPS, type ListSummary } from '@/lib/shipshape';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Lightbulb, Plus, RotateCcw, Trash2 } from 'lucide-react';

interface Props {
    today: string;
    list: ListSummary & { editors: 'crew' | 'captains'; can_change_editors: boolean };
    chores: Chore[];
    deleted: { id: number; title: string }[];
}

export default function ListShow({ today, list, chores, deleted }: Props) {
    const { flash } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shipshape', href: '/shipshape' },
        { title: 'Lists', href: '/shipshape/lists' },
        { title: list.name, href: `/shipshape/lists/${list.id}` },
    ];

    const setEditors = (editors: string) => router.patch(route('shipshape.lists.update', list.id), { editors }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={list.name} />

            <div className="mx-auto w-full max-w-2xl space-y-5 p-4 pb-28">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{list.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {list.is_crew ? `Shared with ${list.owner} — everyone aboard sees and ticks these.` : 'Just you.'}
                        </p>
                    </div>
                    {list.editable && (
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('shipshape.starters.show', list.id)}>
                                    <Lightbulb /> Suggestions
                                </Link>
                            </Button>
                            <Button asChild size="sm">
                                <Link href={route('shipshape.chores.create', list.id)}>
                                    <Plus /> Chore
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}
                <LoggedToast />

                {chores.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">No chores on this list yet.</p>
                ) : (
                    GROUPS.map((group) => {
                        const items = chores.filter((chore) => chore.group === group.key);
                        if (items.length === 0) return null;

                        return (
                            <section key={group.key} className="space-y-2">
                                <h2 className="text-xs font-bold tracking-wider uppercase">
                                    {group.label} <span className="text-muted-foreground font-medium">{items.length}</span>
                                </h2>
                                <ul className="space-y-2">
                                    {items.map((chore) => (
                                        <ChoreRow key={chore.id} chore={chore} list={list} today={today} showList={false} />
                                    ))}
                                </ul>
                            </section>
                        );
                    })
                )}

                {list.editable && deleted.length > 0 && (
                    <details className="bg-card rounded-xl border">
                        <summary className="text-muted-foreground cursor-pointer px-4 py-3 text-sm font-medium">
                            Recently deleted ({deleted.length})
                        </summary>
                        <ul className="divide-y border-t">
                            {deleted.map((chore) => (
                                <li key={chore.id} className="flex items-center gap-3 px-4 py-2">
                                    <span className="flex-1 text-sm">{chore.title}</span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(route('shipshape.chores.restore', chore.id), {}, { preserveScroll: true })}
                                    >
                                        <RotateCcw /> Restore
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}

                {list.is_crew && (
                    <section className="bg-card space-y-2 rounded-xl border p-4">
                        <h2 className="text-sm font-medium">Who can edit</h2>
                        <p className="text-muted-foreground text-sm">
                            Anyone aboard can always tick things off. This decides who can add, change and delete chores.
                        </p>
                        {list.can_change_editors ? (
                            <div role="group" aria-label="Who can edit" className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1">
                                {(['crew', 'captains'] as const).map((value) => (
                                    <button
                                        key={value}
                                        type="button"
                                        aria-pressed={list.editors === value}
                                        onClick={() => setEditors(value)}
                                        className={
                                            list.editors === value
                                                ? 'bg-background rounded-md px-2 py-1.5 text-sm font-medium shadow-sm'
                                                : 'text-muted-foreground rounded-md px-2 py-1.5 text-sm'
                                        }
                                    >
                                        {value === 'crew' ? 'All crew' : 'Captains only'}
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm font-medium">{list.editors === 'crew' ? 'All crew' : 'Captains only'}</p>
                        )}
                    </section>
                )}

                {list.editable && (
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="ghost" className="text-destructive">
                                <Trash2 /> Delete list
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete “{list.name}”?</DialogTitle>
                            <DialogDescription>
                                Its chores disappear from {list.is_crew ? "everyone's" : 'your'} board. Their history is kept.
                            </DialogDescription>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button variant="destructive" onClick={() => router.delete(route('shipshape.lists.destroy', list.id))}>
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}
            </div>
        </AppLayout>
    );
}
