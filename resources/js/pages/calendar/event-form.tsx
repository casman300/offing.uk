import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type CalendarSummary } from '@/lib/calendar';
import calendarRoutes from '@/routes/calendar';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Freq = 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom';

type RepeatFields = {
    freq: Freq;
    interval: number;
    days: string[];
    ends: 'never' | 'count' | 'until';
    count: number | null;
    until: string | null;
    custom?: string | null;
};

type Values = {
    calendar_id: number | null;
    title: string;
    all_day: boolean;
    start_date: string;
    start_time: string;
    end_date: string;
    end_time: string;
    tzid: string;
    location: string;
    description: string;
    private: boolean;
    free: boolean;
    repeat: RepeatFields;
};

const field =
    'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';

const DAYS = [
    ['MO', 'Mon'],
    ['TU', 'Tue'],
    ['WE', 'Wed'],
    ['TH', 'Thu'],
    ['FR', 'Fri'],
    ['SA', 'Sat'],
    ['SU', 'Sun'],
] as const;

const UNITS: Record<string, string> = { daily: 'day', weekly: 'week', monthly: 'month', yearly: 'year' };

/** Shift a Y-m-d by whole days, in UTC so DST never nudges it. */
function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

function daysBetween(a: string, b: string): number {
    return Math.round((new Date(`${b}T00:00:00Z`).getTime() - new Date(`${a}T00:00:00Z`).getTime()) / 86_400_000);
}

export default function EventForm({
    event,
    values,
    calendars,
    timezones,
}: {
    event: { id: number; title: string; recurring: boolean } | null;
    values: Values;
    calendars: CalendarSummary[];
    timezones: string[];
}) {
    const { data, setData, post, put, processing, errors } = useForm<Values>({
        ...values,
        calendar_id: values.calendar_id && calendars.some((c) => c.id === values.calendar_id) ? values.calendar_id : (calendars[0]?.id ?? null),
        repeat: { ...values.repeat, freq: values.repeat.custom ? 'custom' : values.repeat.freq },
    });
    const err = errors as Record<string, string | undefined>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Calendar', href: '/calendar' },
        ...(event ? [{ title: event.title, href: calendarRoutes.events.show.url(event.id) }] : []),
        { title: event ? 'Edit' : 'New event', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (event) put(calendarRoutes.events.update.url(event.id));
        else post(calendarRoutes.events.store.url());
    };

    // Moving the start carries the end with it, keeping the length.
    const setStartDate = (value: string) => {
        if (!value) return setData('start_date', value);
        const span = daysBetween(data.start_date, data.end_date);
        setData((d) => ({ ...d, start_date: value, end_date: addDays(value, Math.max(0, span)) }));
    };
    const setStartTime = (value: string) => {
        if (!value || data.start_date !== data.end_date) return setData('start_time', value);
        const toMinutes = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
        const length = Math.max(15, toMinutes(data.end_time) - toMinutes(data.start_time));
        const end = toMinutes(value) + length;
        if (end >= 24 * 60) {
            setData((d) => ({ ...d, start_time: value, end_date: addDays(d.start_date, 1), end_time: value }));
        } else {
            const hh = String(Math.floor(end / 60)).padStart(2, '0');
            const mm = String(end % 60).padStart(2, '0');
            setData((d) => ({ ...d, start_time: value, end_time: `${hh}:${mm}` }));
        }
    };

    const setRepeat = (patch: Partial<RepeatFields>) => setData('repeat', { ...data.repeat, ...patch });
    const toggleDay = (day: string) =>
        setRepeat({ days: data.repeat.days.includes(day) ? data.repeat.days.filter((d) => d !== day) : [...data.repeat.days, day] });

    if (calendars.length === 0) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="New event" />
                <p className="mx-auto max-w-xl p-4 text-sm">None of your calendars let you add events.</p>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={event ? `Edit ${event.title}` : 'New event'} />

            <form onSubmit={submit} className="mx-auto w-full max-w-xl space-y-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">{event ? 'Edit event' : 'New event'}</h1>
                    {event?.recurring && <p className="text-muted-foreground text-sm">Changes apply to every date in the series.</p>}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="title">What</Label>
                    <Input
                        id="title"
                        required
                        autoFocus={!event}
                        maxLength={200}
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="Dinner at Sam’s"
                    />
                    <InputError message={errors.title} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="calendar">Calendar</Label>
                    <select
                        id="calendar"
                        className={field}
                        value={data.calendar_id ?? ''}
                        onChange={(e) => setData('calendar_id', Number(e.target.value))}
                    >
                        {calendars.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                                {c.owner !== 'Just me' ? ` (shared with ${c.owner})` : ''}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.calendar_id} />
                </div>

                <fieldset className="bg-card space-y-4 rounded-xl border p-4">
                    <legend className="sr-only">When</legend>
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" checked={data.all_day} onChange={(e) => setData('all_day', e.target.checked)} />
                        All day
                    </label>

                    <div className="grid grid-cols-[1fr_auto] gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="start_date">Starts</Label>
                            <Input id="start_date" type="date" required value={data.start_date} onChange={(e) => setStartDate(e.target.value)} />
                        </div>
                        {!data.all_day && (
                            <div className="grid gap-1">
                                <Label htmlFor="start_time" className="sr-only">
                                    Start time
                                </Label>
                                <Input
                                    id="start_time"
                                    type="time"
                                    required
                                    className="mt-auto w-32"
                                    value={data.start_time}
                                    onChange={(e) => setStartTime(e.target.value)}
                                />
                            </div>
                        )}
                        <div className="grid gap-1">
                            <Label htmlFor="end_date">Ends</Label>
                            <Input
                                id="end_date"
                                type="date"
                                required
                                min={data.start_date}
                                value={data.end_date}
                                onChange={(e) => setData('end_date', e.target.value)}
                            />
                        </div>
                        {!data.all_day && (
                            <div className="grid gap-1">
                                <Label htmlFor="end_time" className="sr-only">
                                    End time
                                </Label>
                                <Input
                                    id="end_time"
                                    type="time"
                                    required
                                    className="mt-auto w-32"
                                    value={data.end_time}
                                    onChange={(e) => setData('end_time', e.target.value)}
                                />
                            </div>
                        )}
                    </div>
                    <InputError message={errors.end_date ?? errors.start_time ?? errors.end_time} />

                    {!data.all_day && (
                        <div className="grid gap-1">
                            <Label htmlFor="tzid">Time zone</Label>
                            <Input id="tzid" list="timezones" required value={data.tzid} onChange={(e) => setData('tzid', e.target.value)} />
                            <datalist id="timezones">
                                {timezones.map((tz) => (
                                    <option key={tz} value={tz} />
                                ))}
                            </datalist>
                            <p className="text-muted-foreground text-xs">Repeats keep to this zone’s clock — 9am stays 9am when the clocks change.</p>
                            <InputError message={errors.tzid} />
                        </div>
                    )}
                </fieldset>

                <fieldset className="bg-card space-y-4 rounded-xl border p-4">
                    <legend className="sr-only">Repeat</legend>
                    <div className="grid gap-1">
                        <Label htmlFor="freq">Repeats</Label>
                        <select id="freq" className={field} value={data.repeat.freq} onChange={(e) => setRepeat({ freq: e.target.value as Freq })}>
                            <option value="none">Doesn’t repeat</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                            {values.repeat.custom && <option value="custom">Keep its custom pattern</option>}
                        </select>
                    </div>

                    {data.repeat.freq !== 'none' && data.repeat.freq !== 'custom' && (
                        <>
                            <div className="flex items-center gap-2 text-sm">
                                <span>Every</span>
                                <Input
                                    type="number"
                                    aria-label="How often"
                                    min={1}
                                    max={99}
                                    className="w-20"
                                    value={data.repeat.interval}
                                    onChange={(e) => setRepeat({ interval: Number(e.target.value) })}
                                />
                                <span>{data.repeat.interval === 1 ? UNITS[data.repeat.freq] : `${UNITS[data.repeat.freq]}s`}</span>
                            </div>
                            <InputError message={err['repeat.interval']} />

                            {data.repeat.freq === 'weekly' && (
                                <div className="flex flex-wrap gap-1" role="group" aria-label="On these days">
                                    {DAYS.map(([key, label]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={data.repeat.days.includes(key)}
                                            onClick={() => toggleDay(key)}
                                            className="aria-pressed:bg-primary aria-pressed:text-primary-foreground rounded-full border px-3 py-1 text-sm"
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            )}

                            <div className="grid gap-2 text-sm">
                                <span className="font-medium">Ends</span>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="ends"
                                        checked={data.repeat.ends === 'never'}
                                        onChange={() => setRepeat({ ends: 'never' })}
                                    />
                                    Never
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="ends"
                                        checked={data.repeat.ends === 'count'}
                                        onChange={() => setRepeat({ ends: 'count' })}
                                    />
                                    After
                                    <Input
                                        type="number"
                                        aria-label="Number of times"
                                        min={1}
                                        max={999}
                                        className="w-20"
                                        value={data.repeat.count ?? ''}
                                        onFocus={() => setRepeat({ ends: 'count' })}
                                        onChange={(e) => setRepeat({ ends: 'count', count: e.target.value ? Number(e.target.value) : null })}
                                    />
                                    times
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="ends"
                                        checked={data.repeat.ends === 'until'}
                                        onChange={() => setRepeat({ ends: 'until' })}
                                    />
                                    On
                                    <Input
                                        type="date"
                                        aria-label="Last date"
                                        className="w-44"
                                        min={data.start_date}
                                        value={data.repeat.until ?? ''}
                                        onFocus={() => setRepeat({ ends: 'until' })}
                                        onChange={(e) => setRepeat({ ends: 'until', until: e.target.value || null })}
                                    />
                                </label>
                                <InputError message={err['repeat.count'] ?? err['repeat.until']} />
                            </div>
                        </>
                    )}
                </fieldset>

                <div className="grid gap-2">
                    <Label htmlFor="location">
                        Where <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <Input id="location" maxLength={255} value={data.location} onChange={(e) => setData('location', e.target.value)} />
                    <InputError message={errors.location} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">
                        Notes <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <textarea
                        id="description"
                        rows={4}
                        maxLength={5000}
                        className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-hidden md:text-sm"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                    <InputError message={errors.description} />
                </div>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">Who sees it</legend>
                    <label className="flex items-start gap-3 text-sm">
                        <input type="checkbox" className="mt-1" checked={data.private} onChange={(e) => setData('private', e.target.checked)} />
                        <span>
                            <span className="block font-medium">Private</span>
                            <span className="text-muted-foreground block">
                                Only you see what it is. Anyone else who can see this calendar sees “Busy”.
                            </span>
                        </span>
                    </label>
                    <label className="flex items-start gap-3 text-sm">
                        <input type="checkbox" className="mt-1" checked={data.free} onChange={(e) => setData('free', e.target.checked)} />
                        <span>
                            <span className="block font-medium">Show me as free</span>
                            <span className="text-muted-foreground block">
                                It doesn’t block your time.{data.private ? ' Private and free: nobody else sees it at all.' : ''}
                            </span>
                        </span>
                    </label>
                </fieldset>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        {event ? 'Save' : 'Add event'}
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={event ? calendarRoutes.events.show.url(event.id) : calendarRoutes.index.url()}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
