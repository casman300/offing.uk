import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { BIRTHDAYS, type CalendarSummary, type OccurrenceData } from '@/lib/calendar';
import calendarRoutes from '@/routes/calendar';
import peopleRoutes from '@/routes/people';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type DatesSetArg, type EventChangeArg, type EventClickArg, type EventContentArg, type EventInput } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin, { type DateClickArg } from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { EyeOff, Lock, Plus, Repeat, RotateCcw, Settings2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Calendar', href: '/calendar' }];

const VIEWS = { month: 'dayGridMonth', week: 'timeGridWeek', agenda: 'listMonth' } as const;
type ViewKey = keyof typeof VIEWS;

const HIDDEN_KEY = 'offing.calendar.hidden';

/** A Date from the calendar (running in "UTC") back to the wall-clock string the server expects. */
function wallClock(date: Date, allDay: boolean): string {
    const iso = date.toISOString();
    return allDay ? iso.slice(0, 10) : iso.slice(0, 19);
}

interface PendingMove {
    arg: EventChangeArg;
    data: OccurrenceData;
    start: string;
    end: string;
    allDay: boolean;
    sameDay: boolean;
}

function readHidden(): number[] {
    try {
        const raw = window.localStorage.getItem(HIDDEN_KEY);
        return raw ? (JSON.parse(raw) as number[]) : [];
    } catch {
        return [];
    }
}

function writeHidden(ids: number[]) {
    try {
        window.localStorage.setItem(HIDDEN_KEY, JSON.stringify(ids));
    } catch {
        // Private mode or blocked storage: the toggle still works for this visit.
    }
}

/** Where the page starts: ?view= and ?date= from the URL, else month (agenda on a phone) around today. */
function initialState(now: string): { view: ViewKey; date: string } {
    const params = new URLSearchParams(window.location.search);
    const view = params.get('view');
    const date = params.get('date');
    const narrow = window.matchMedia('(max-width: 640px)').matches;

    return {
        view: view && view in VIEWS ? (view as ViewKey) : narrow ? 'agenda' : 'month',
        date: date && /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : now.slice(0, 10),
    };
}

function EventContent({ arg }: { arg: EventContentArg }) {
    const data = arg.event.extendedProps as OccurrenceData;
    const icons = (
        <>
            {data.private && !data.busy_block && <Lock className="inline size-3 shrink-0" aria-label="Private" />}
            {data.free && !data.person_id && <EyeOff className="inline size-3 shrink-0" aria-label="Shown as free" />}
            {(data.recurring || data.override) && !data.busy_block && <Repeat className="inline size-3 shrink-0" aria-label="Repeats" />}
        </>
    );

    if (arg.view.type.startsWith('list')) {
        return (
            <span className="inline-flex items-center gap-1.5">
                {arg.event.title}
                {data.location && <span className="text-muted-foreground">· {data.location}</span>}
                {icons}
            </span>
        );
    }

    return (
        <span className="flex min-w-0 items-center gap-1 overflow-hidden px-1 text-xs">
            {arg.timeText && !arg.event.allDay && <span className="shrink-0 font-semibold">{arg.timeText}</span>}
            <span className="truncate">{arg.event.title}</span>
            {icons}
        </span>
    );
}

export default function CalendarIndex({
    calendars,
    now,
    timezone,
    birthdays,
}: {
    calendars: CalendarSummary[];
    now: string;
    timezone: string;
    birthdays: boolean;
}) {
    const { flash } = usePage<SharedData>().props;
    const ref = useRef<FullCalendar>(null);
    const [start] = useState(() => initialState(now));
    const [view, setView] = useState<ViewKey>(start.view);
    const [hidden, setHidden] = useState<number[]>(() => readHidden());

    const toggle = (id: number) => {
        const next = hidden.includes(id) ? hidden.filter((h) => h !== id) : [...hidden, id];
        setHidden(next);
        writeHidden(next);
    };

    const hiddenRef = useRef(hidden);
    useEffect(() => {
        hiddenRef.current = hidden;
        ref.current?.getApi().refetchEvents();
    }, [hidden]);

    const fetchEvents = useCallback(
        (info: { startStr: string; endStr: string }, success: (events: EventInput[]) => void, failure: (error: Error) => void) => {
            fetch(calendarRoutes.occurrences.url({ query: { start: info.startStr, end: info.endStr } }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    return response.json() as Promise<OccurrenceData[]>;
                })
                .then((items) =>
                    success(
                        items
                            .filter((item) => !hiddenRef.current.includes(item.calendar_id))
                            .map((item) => ({
                                id: item.id,
                                title: item.title,
                                start: item.start,
                                end: item.end,
                                allDay: item.all_day,
                                backgroundColor: item.colour,
                                borderColor: item.colour,
                                textColor: '#ffffff',
                                classNames: [item.busy_block ? 'offing-busy' : 'cursor-pointer', item.free && !item.person_id ? 'offing-free' : ''],
                                editable: item.can_edit,
                                extendedProps: item,
                            })),
                    ),
                )
                .catch(failure);
        },
        [],
    );

    const datesSet = (arg: DatesSetArg) => {
        const key = (Object.keys(VIEWS) as ViewKey[]).find((k) => VIEWS[k] === arg.view.type) ?? 'month';
        setView(key);
        const date = arg.view.calendar.getDate().toISOString().slice(0, 10);
        const url = new URL(window.location.href);
        url.searchParams.set('view', key);
        url.searchParams.set('date', date);
        window.history.replaceState(window.history.state, '', url);
    };

    const eventClick = (arg: EventClickArg) => {
        const data = arg.event.extendedProps as OccurrenceData;
        if (data.person_id) return router.visit(peopleRoutes.show.url(data.person_id));
        if (data.event_id === null) return;
        router.visit(calendarRoutes.events.show.url(data.event_id, { query: data.recurring ? { at: data.occurrence } : {} }));
    };

    const editable = calendars.some((c) => c.can_edit);

    const dateClick = (arg: DateClickArg) => {
        if (!editable) return;
        const iso = arg.date.toISOString();
        const query: Record<string, string> = { date: iso.slice(0, 10) };
        if (arg.allDay) {
            if (arg.view.type === 'timeGridWeek') query.all_day = '1';
        } else {
            query.time = iso.slice(11, 16);
        }
        router.visit(calendarRoutes.events.create.url({ query }));
    };

    const [pending, setPending] = useState<PendingMove | null>(null);

    const sendMove = (move: PendingMove, scope: 'one' | 'all') => {
        setPending(null);
        if (move.data.event_id === null) return move.arg.revert();
        router.post(
            calendarRoutes.events.move.url(move.data.event_id),
            { start: move.start, end: move.end, all_day: move.allDay, occurrence: move.data.recurring ? move.data.occurrence : null, scope },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => ref.current?.getApi().refetchEvents(),
                onError: () => move.arg.revert(),
            },
        );
    };

    // Dragging or resizing: a one-off just moves; a series date asks "this one or every one?".
    const eventChange = (arg: EventChangeArg) => {
        const data = arg.event.extendedProps as OccurrenceData;
        const start = arg.event.start;
        if (!start || data.event_id === null) return arg.revert();
        const allDay = arg.event.allDay;
        // A timed event dropped into the all-day row has no end: make it one day.
        const end = arg.event.end ?? new Date(start.getTime() + (allDay ? 86_400_000 : 3_600_000));
        const move: PendingMove = {
            arg,
            data,
            start: wallClock(start, allDay),
            end: wallClock(end, allDay),
            allDay,
            sameDay: allDay === data.all_day && wallClock(start, true) === data.start.slice(0, 10),
        };
        if (data.recurring) setPending(move);
        else sendMove(move, 'one');
    };

    const changeView = (key: ViewKey) => ref.current?.getApi().changeView(VIEWS[key]);

    const plugins = useMemo(() => [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin], []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar" />

            <div className="flex w-full flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Calendars shown">
                        {[
                            ...calendars,
                            ...(birthdays ? [{ id: BIRTHDAYS, name: 'Birthdays', colour: '#db2777', owner: 'Just me', is_crew: false }] : []),
                        ].map((c) => {
                            const on = !hidden.includes(c.id);
                            return (
                                <button
                                    key={c.id}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => toggle(c.id)}
                                    title={c.is_crew ? `Shared with ${c.owner}` : 'Just you'}
                                    className="hover:bg-accent flex items-center gap-2 rounded-full border px-3 py-1 text-sm"
                                >
                                    <span
                                        className="size-3 rounded-full border-2"
                                        style={{ borderColor: c.colour, backgroundColor: on ? c.colour : 'transparent' }}
                                    />
                                    <span className={on ? '' : 'text-muted-foreground line-through'}>{c.name}</span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={calendarRoutes.calendars.index.url()}>
                                <Settings2 /> Calendars
                            </Link>
                        </Button>
                        {editable && (
                            <Button asChild size="sm">
                                <Link href={calendarRoutes.events.create.url()}>
                                    <Plus /> New event
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {flash.eventDeleted && (
                    <div
                        role="status"
                        className="bg-accent text-accent-foreground flex items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm"
                    >
                        <span>
                            Deleted <strong>{flash.eventDeleted.title}</strong>.
                        </span>
                        <Button size="sm" variant="outline" onClick={() => router.post(calendarRoutes.events.restore.url(flash.eventDeleted!.id))}>
                            <RotateCcw /> Undo
                        </Button>
                    </div>
                )}
                {flash.status && !flash.eventDeleted && (
                    <div role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="sm" onClick={() => ref.current?.getApi().prev()} aria-label="Previous">
                            ‹
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => ref.current?.getApi().today()}>
                            Today
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => ref.current?.getApi().next()} aria-label="Next">
                            ›
                        </Button>
                    </div>
                    <div className="flex rounded-md border p-0.5" role="group" aria-label="View">
                        {(Object.keys(VIEWS) as ViewKey[]).map((key) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={view === key}
                                onClick={() => changeView(key)}
                                className="aria-pressed:bg-primary aria-pressed:text-primary-foreground rounded px-3 py-1 text-sm capitalize"
                            >
                                {key}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="offing-calendar">
                    <FullCalendar
                        ref={ref}
                        plugins={plugins}
                        initialView={VIEWS[start.view]}
                        initialDate={start.date}
                        // Times arrive as wall-clock in the profile timezone; "UTC" stops the browser shifting them.
                        timeZone="UTC"
                        now={now}
                        headerToolbar={{ left: '', center: 'title', right: '' }}
                        firstDay={1}
                        locale="en-gb"
                        // The week needs its own scroller so it can open at 08:00.
                        height={view === 'week' ? 720 : 'auto'}
                        nowIndicator
                        dayMaxEvents
                        scrollTime="08:00:00"
                        slotLabelFormat={{ hour: '2-digit', minute: '2-digit', hour12: false }}
                        eventTimeFormat={{ hour: '2-digit', minute: '2-digit', hour12: false }}
                        events={fetchEvents}
                        eventContent={(arg) => <EventContent arg={arg} />}
                        eventClick={eventClick}
                        eventChange={eventChange}
                        eventDurationEditable
                        eventStartEditable
                        dateClick={dateClick}
                        datesSet={datesSet}
                        noEventsText="Nothing in the offing."
                    />
                </div>
                <Dialog
                    open={pending !== null}
                    onOpenChange={(open) => {
                        if (!open && pending) {
                            pending.arg.revert();
                            setPending(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogTitle>Move “{pending?.data.title}”</DialogTitle>
                        <DialogDescription>
                            It repeats. Move just this date, or change the time of every one?
                            {pending && !pending.sameDay && ' (Every one can only change time on the same day — open it to change the pattern.)'}
                        </DialogDescription>
                        <DialogFooter className="gap-2">
                            <Button
                                variant="outline"
                                onClick={() => {
                                    pending?.arg.revert();
                                    setPending(null);
                                }}
                            >
                                Cancel
                            </Button>
                            <Button variant="outline" disabled={!pending?.sameDay} onClick={() => pending && sendMove(pending, 'all')}>
                                Every one
                            </Button>
                            <Button onClick={() => pending && sendMove(pending, 'one')}>Just this one</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <p className="text-muted-foreground text-xs">Times shown in {timezone.replace(/_/g, ' ')}.</p>
            </div>
        </AppLayout>
    );
}
