import Heading from '@/components/heading';
import PersonForm from '@/components/person-form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatShortDate } from '@/lib/birthday';
import { birthdayLongDate } from '@/lib/people';
import people from '@/routes/people';
import { type BreadcrumbItem, type Person, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

export default function ShowPerson({ person, today }: { person: Person; today: string }) {
    const { flash } = usePage<SharedData>().props;
    const [editing, setEditing] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'People', href: '/people' },
        { title: person.name, href: `/people/${person.id}` },
    ];

    const destroy = () => router.delete(people.destroy.url(person.id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={person.name} />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4">
                {editing ? (
                    <>
                        <Heading title={`Edit ${person.name}`} />
                        <PersonForm
                            person={person}
                            today={today}
                            submitLabel="Save"
                            onCancel={() => setEditing(false)}
                            onSaved={() => setEditing(false)}
                            key={person.id}
                        />
                    </>
                ) : (
                    <>
                        <div className="flex items-start justify-between gap-4">
                            <h1 className="text-2xl font-semibold tracking-tight">{person.name}</h1>
                            <div className="flex shrink-0 gap-2">
                                <Button variant="outline" onClick={() => setEditing(true)}>
                                    <Pencil /> Edit
                                </Button>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="ghost" aria-label={`Delete ${person.name}`}>
                                            <Trash2 />
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>Delete {person.name}?</DialogTitle>
                                        <DialogDescription>
                                            You can undo this straight away, or restore them later from “Recently deleted”.
                                        </DialogDescription>
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button variant="secondary">Cancel</Button>
                                            </DialogClose>
                                            <Button variant="destructive" onClick={destroy}>
                                                Delete
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </div>

                        <Transition
                            show={flash.status === 'person-updated' || flash.status === 'person-restored'}
                            appear
                            enter="transition ease-in-out"
                            enterFrom="opacity-0"
                            leave="transition ease-in-out"
                            leaveTo="opacity-0"
                        >
                            <p role="status" className="text-muted-foreground text-sm">
                                {flash.status === 'person-restored' ? 'Restored.' : 'Saved.'}
                            </p>
                        </Transition>

                        <dl className="bg-card divide-border divide-y rounded-xl border">
                            <BirthdayRow person={person} />
                            {person.notes && (
                                <div className="px-4 py-3">
                                    <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Notes</dt>
                                    <dd className="mt-1 whitespace-pre-line">{person.notes}</dd>
                                </div>
                            )}
                        </dl>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function BirthdayRow({ person }: { person: Person }) {
    const date = birthdayLongDate(person);
    let primary: string;
    let secondary: string | null = null;

    if (date !== null) {
        primary = person.birth_year !== null ? `${date} ${person.birth_year}` : date;

        if (person.days_until === 0) {
            secondary = person.turns !== null ? `Turns ${person.turns} today 🎂` : 'Birthday today 🎂';
        } else if (person.next_birthday !== null) {
            const when = formatShortDate(person.next_birthday);
            secondary = person.age !== null ? `${person.age} · turns ${person.turns} on ${when}` : `Next birthday ${when}`;
        }
    } else if (person.birth_year !== null) {
        primary = `Born ${person.birth_year}`;
        secondary = `~${person.age} (birthday date unknown)`;
    } else {
        primary = 'Not set';
    }

    return (
        <div className="px-4 py-3">
            <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Birthday</dt>
            <dd className="mt-1">
                <span className={date === null && person.birth_year === null ? 'text-muted-foreground' : undefined}>{primary}</span>
                {secondary && <span className="text-muted-foreground block text-sm">{secondary}</span>}
            </dd>
        </div>
    );
}
