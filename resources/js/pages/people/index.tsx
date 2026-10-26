import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { birthdaySummary, byName, byUpcoming } from '@/lib/people';
import { cn } from '@/lib/utils';
import peopleRoutes from '@/routes/people';
import { type BreadcrumbItem, type Person, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Cake, ChevronRight, Plus, RotateCcw, Search, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'People', href: '/people' }];

type Sort = 'upcoming' | 'name';

export default function PeopleIndex({ people, recentlyDeleted }: { people: Person[]; recentlyDeleted: Person[]; today: string }) {
    const { flash, features } = usePage<SharedData>().props;
    const [sort, setSort] = useState<Sort>('upcoming');
    const [query, setQuery] = useState('');

    const visible = useMemo(() => {
        const q = query.trim().toLocaleLowerCase();
        const filtered = q === '' ? people : people.filter((p) => p.name.toLocaleLowerCase().includes(q));

        return [...filtered].sort(sort === 'upcoming' ? byUpcoming : byName);
    }, [people, query, sort]);

    const restore = (id: number) => router.post(peopleRoutes.restore.url(id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="People" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="People" description="Everyone whose birthday you want to remember." />
                    <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                        {features.vcardImport && (
                            <Button asChild variant="outline">
                                <Link href={peopleRoutes.import.create.url()}>
                                    <Upload /> Import contacts
                                </Link>
                            </Button>
                        )}
                        <Button asChild>
                            <Link href={peopleRoutes.create.url()}>
                                <Plus /> Add person
                            </Link>
                        </Button>
                    </div>
                </div>

                {flash.deleted && (
                    <div
                        role="status"
                        className="bg-accent text-accent-foreground flex items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm"
                    >
                        <span>
                            Deleted <strong>{flash.deleted.name}</strong>.
                        </span>
                        <Button size="sm" variant="outline" onClick={() => restore(flash.deleted!.id)}>
                            <RotateCcw /> Undo
                        </Button>
                    </div>
                )}

                {people.length > 0 && (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="relative sm:w-64">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                type="search"
                                aria-label="Search people"
                                placeholder="Search"
                                className="pl-9"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={sort}
                            onValueChange={(value) => value && setSort(value as Sort)}
                            aria-label="Sort people"
                        >
                            <ToggleGroupItem value="upcoming" className="px-3">
                                Next birthday
                            </ToggleGroupItem>
                            <ToggleGroupItem value="name" className="px-3">
                                A–Z
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                )}

                {people.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center">
                        <Cake className="mx-auto mb-3 size-8" />
                        <p className="text-foreground font-medium">No one here yet</p>
                        <p className="mt-1 text-sm">Add the people whose birthdays you never want to miss.</p>
                    </div>
                ) : visible.length === 0 ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">No one matches “{query}”.</p>
                ) : (
                    <ul className="divide-border bg-card divide-y overflow-hidden rounded-xl border">
                        {visible.map((person) => (
                            <li key={person.id}>
                                <Link
                                    href={peopleRoutes.show.url(person.id)}
                                    className="hover:bg-accent/60 focus-visible:bg-accent flex items-center gap-3 px-4 py-3 outline-none"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium">{person.name}</p>
                                        <p className={cn('text-sm', person.days_until === 0 ? 'text-primary font-medium' : 'text-muted-foreground')}>
                                            {birthdaySummary(person)}
                                        </p>
                                    </div>
                                    <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {recentlyDeleted.length > 0 && (
                    <details className="group">
                        <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-sm">
                            Recently deleted ({recentlyDeleted.length})
                        </summary>
                        <ul className="divide-border mt-3 divide-y rounded-xl border">
                            {recentlyDeleted.map((person) => (
                                <li key={person.id} className="flex items-center justify-between gap-3 px-4 py-2">
                                    <span className="text-muted-foreground truncate">{person.name}</span>
                                    <Button size="sm" variant="ghost" onClick={() => restore(person.id)}>
                                        <RotateCcw /> Restore
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </div>
        </AppLayout>
    );
}
