import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { whenText } from '@/lib/calendar';
import calendarRoutes from '@/routes/calendar';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, EyeOff, Globe, Lock, MapPin, Pencil, Repeat, Trash2 } from 'lucide-react';

interface EventDetails {
    id: number;
    title: string;
    description: string | null;
    location: string | null;
    all_day: boolean;
    start: string;
    end: string;
    tzid: string;
    repeat: string | null;
    series: { id: number; repeat: string | null } | null;
    private: boolean;
    free: boolean;
    calendar: { id: number; name: string; colour: string; owner: string };
    can_edit: boolean;
}

export default function EventPage({
    event,
    occurrence,
    timezone,
}: {
    event: EventDetails;
    occurrence: { key: string; start: string; end: string } | null;
    timezone: string;
}) {
    const { flash } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Calendar', href: '/calendar' },
        { title: event.title, href: '#' },
    ];
    const shown = occurrence ?? { start: event.start, end: event.end };

    const destroy = () => {
        const prompt = event.repeat ? `Delete every “${event.title}”? You can undo straight after.` : `Delete “${event.title}”?`;
        if (window.confirm(prompt)) router.delete(calendarRoutes.events.destroy.url(event.id));
    };
    const skip = () => {
        if (occurrence) router.post(calendarRoutes.events.skip.url(event.id), { occurrence: occurrence.key });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={event.title} />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4">
                {flash.status && (
                    <div role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </div>
                )}

                <div className="flex items-start gap-3">
                    <span className="mt-2 size-3 shrink-0 rounded-full" style={{ backgroundColor: event.calendar.colour }} />
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{event.title}</h1>
                        <p className="text-muted-foreground text-sm">
                            {event.calendar.name} · {event.calendar.owner === 'Just me' ? 'just you' : `shared with ${event.calendar.owner}`}
                        </p>
                    </div>
                </div>

                <ul className="space-y-3 text-sm">
                    <li className="flex gap-3">
                        <Clock className="text-muted-foreground size-4 shrink-0" />
                        <span>{whenText(shown.start, shown.end, event.all_day)}</span>
                    </li>
                    {event.repeat && (
                        <li className="flex gap-3">
                            <Repeat className="text-muted-foreground size-4 shrink-0" />
                            <span>{event.repeat}</span>
                        </li>
                    )}
                    {event.series && (
                        <li className="flex gap-3">
                            <Repeat className="text-muted-foreground size-4 shrink-0" />
                            <span>
                                One date of a series ({event.series.repeat}), changed on its own.{' '}
                                <Link className="underline" href={calendarRoutes.events.show.url(event.series.id)}>
                                    See the series
                                </Link>
                            </span>
                        </li>
                    )}
                    {!event.all_day && event.tzid !== timezone && (
                        <li className="flex gap-3">
                            <Globe className="text-muted-foreground size-4 shrink-0" />
                            <span>Set in {event.tzid.replace(/_/g, ' ')} — shown in your time</span>
                        </li>
                    )}
                    {event.location && (
                        <li className="flex gap-3">
                            <MapPin className="text-muted-foreground size-4 shrink-0" />
                            <span>{event.location}</span>
                        </li>
                    )}
                    {event.private && (
                        <li className="flex gap-3">
                            <Lock className="text-muted-foreground size-4 shrink-0" />
                            <span>Private — anyone else who can see this calendar sees {event.free ? 'nothing' : 'just “Busy”'}</span>
                        </li>
                    )}
                    {event.free && (
                        <li className="flex gap-3">
                            <EyeOff className="text-muted-foreground size-4 shrink-0" />
                            <span>Shown as free — doesn’t block your time</span>
                        </li>
                    )}
                </ul>

                {event.description && <p className="bg-card rounded-xl border p-4 text-sm whitespace-pre-line">{event.description}</p>}

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <Link href={calendarRoutes.index.url({ query: { date: shown.start.slice(0, 10) } })}>
                            <CalendarDays /> Back to calendar
                        </Link>
                    </Button>
                    {event.can_edit && (
                        <>
                            {occurrence && (
                                <Button asChild>
                                    <Link href={calendarRoutes.events.one.edit.url(event.id, { query: { at: occurrence.key } })}>
                                        <Pencil /> Edit just this one
                                    </Link>
                                </Button>
                            )}
                            <Button asChild variant={occurrence ? 'outline' : 'default'}>
                                <Link href={calendarRoutes.events.edit.url(event.id)}>
                                    <Pencil /> {event.repeat ? 'Edit every one' : event.series ? 'Edit this one' : 'Edit'}
                                </Link>
                            </Button>
                            {occurrence && (
                                <Button variant="outline" onClick={skip}>
                                    <Trash2 /> Delete just this one
                                </Button>
                            )}
                            <Button variant="outline" onClick={destroy}>
                                <Trash2 /> {event.repeat ? 'Delete every one' : event.series ? 'Delete this one' : 'Delete'}
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
