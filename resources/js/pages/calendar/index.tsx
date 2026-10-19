import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { agendaDay, dayHeading, originSummary, timeSummary } from '@/lib/calendar';
import { type BreadcrumbItem, type CalendarEvent, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronRight, Lock, MapPin, Plus, RotateCcw } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Calendar', href: '/calendar' }];

const STATUS: Record<string, string> = {
    'event-created': 'Added.',
    'event-updated': 'Saved.',
    'event-restored': 'Restored.',
    'event-saved-past': 'Saved. It’s already over, so it isn’t in the list below.',
};

export default function CalendarIndex({
    events,
    recentlyDeleted,
    today,
}: {
    events: CalendarEvent[];
    recentlyDeleted: CalendarEvent[];
    today: string;
}) {
    const { flash } = usePage<SharedData>().props;

    const days = useMemo(() => {
        const grouped = new Map<string, CalendarEvent[]>();

        for (const event of events) {
            const day = agendaDay(event, today);
            grouped.set(day, [...(grouped.get(day) ?? []), event]);
        }

        return [...grouped.entries()].sort(([a], [b]) => a.localeCompare(b));
    }, [events, today]);

    const restore = (id: number) => router.post(route('calendar.events.restore', id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Calendar" description="What's in the offing." />
                    <Button asChild className="shrink-0">
                        <Link href={route('calendar.events.create')}>
                            <Plus /> Add event
                        </Link>
                    </Button>
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

                <Transition
                    show={flash.status !== null && flash.status in STATUS}
                    appear
                    enter="transition ease-in-out"
                    enterFrom="opacity-0"
                    leave="transition ease-in-out"
                    leaveTo="opacity-0"
                >
                    <p role="status" className="text-muted-foreground text-sm">
                        {flash.status ? STATUS[flash.status] : null}
                    </p>
                </Transition>

                {days.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center">
                        <CalendarDays className="mx-auto mb-3 size-8" />
                        <p className="text-foreground font-medium">Nothing coming up</p>
                        <p className="mt-1 text-sm">Add an event and it'll show here — and in your calendar feed.</p>
                    </div>
                ) : (
                    <div className="space-y-6">
                        {days.map(([day, dayEvents]) => (
                            <section key={day} aria-labelledby={`day-${day}`}>
                                <h2
                                    id={`day-${day}`}
                                    className={
                                        day === today ? 'text-primary mb-2 text-sm font-semibold' : 'text-muted-foreground mb-2 text-sm font-medium'
                                    }
                                >
                                    {dayHeading(day, today)}
                                </h2>
                                <ul className="divide-border bg-card divide-y overflow-hidden rounded-xl border">
                                    {dayEvents.map((event) => (
                                        <li key={event.id}>
                                            <EventRow event={event} today={today} />
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}

                {recentlyDeleted.length > 0 && (
                    <details className="group">
                        <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-sm">
                            Recently deleted ({recentlyDeleted.length})
                        </summary>
                        <ul className="divide-border mt-3 divide-y rounded-xl border">
                            {recentlyDeleted.map((event) => (
                                <li key={event.id} className="flex items-center justify-between gap-3 px-4 py-2">
                                    <span className="text-muted-foreground truncate">{event.title}</span>
                                    <Button size="sm" variant="ghost" onClick={() => restore(event.id)}>
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

function EventRow({ event, today }: { event: CalendarEvent; today: string }) {
    const origin = originSummary(event, today);

    return (
        <Link
            href={route('calendar.events.edit', event.id)}
            className="hover:bg-accent/60 focus-visible:bg-accent flex items-center gap-3 px-4 py-3 outline-none"
        >
            <div className="text-muted-foreground w-28 shrink-0 text-sm tabular-nums">{timeSummary(event, today)}</div>
            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-1.5 truncate font-medium">
                    {event.title}
                    {event.private && <Lock className="text-muted-foreground size-3.5 shrink-0" aria-label="Private" />}
                    {!event.busy && <span className="text-muted-foreground text-xs font-normal">· free</span>}
                </p>
                {event.location && (
                    <p className="text-muted-foreground flex items-center gap-1 truncate text-sm">
                        <MapPin className="size-3.5 shrink-0" /> {event.location}
                    </p>
                )}
                {origin && <p className="text-muted-foreground text-xs">{origin}</p>}
            </div>
            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
        </Link>
    );
}
