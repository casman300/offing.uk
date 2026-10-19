import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { addDays, daysBetween, fromWallMinutes, nowIn, wallMinutes } from '@/lib/calendar';
import { type EventFormValues } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface EventFormProps {
    /** Present when editing. */
    eventId?: number;
    initial?: EventFormValues;
    /** Viewer's profile timezone — the default for new events. */
    timezone: string;
    timezones: string[];
    submitLabel: string;
    onCancel?: () => void;
}

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

const pad = (n: number) => String(n).padStart(2, '0');

/** Next whole hour, for an hour — or 09:00 tomorrow late at night. */
function blankEvent(timezone: string): EventFormValues {
    const now = nowIn(timezone);
    const late = now.hour >= 23;
    const date = late ? addDays(now.date, 1) : now.date;
    const start = `${pad(late ? 9 : now.hour + 1)}:00`;
    const end = fromWallMinutes(wallMinutes(date, start) + 60);

    return {
        title: '',
        location: '',
        description: '',
        all_day: false,
        tzid: timezone,
        start_date: date,
        start_time: start,
        end_date: end.date,
        end_time: end.time,
        private: false,
        busy: true,
    };
}

/**
 * Add/edit form for a one-off or all-day event. Dates and times are in the
 * event's own timezone (the viewer's by default). Moving the start keeps the
 * length, like most calendar apps; the server re-checks everything.
 */
export default function EventForm({ eventId, initial, timezone, timezones, submitLabel, onCancel }: EventFormProps) {
    const { data, setData, post, patch, processing, errors } = useForm<EventFormValues>(initial ?? blankEvent(timezone));
    const [showZone, setShowZone] = useState(data.tzid !== timezone);

    const moveStart = (field: 'start_date' | 'start_time', value: string) => {
        if (value === '') {
            setData(field, value);

            return;
        }

        const next = { ...data, [field]: value };

        if (data.all_day) {
            const length = Math.max(0, daysBetween(data.start_date, data.end_date));
            setData({ ...next, end_date: addDays(next.start_date, length) });

            return;
        }

        if (data.start_time && data.end_time) {
            const length = Math.max(0, wallMinutes(data.end_date, data.end_time) - wallMinutes(data.start_date, data.start_time));
            const end = fromWallMinutes(wallMinutes(next.start_date, next.start_time) + length);
            setData({ ...next, end_date: end.date, end_time: end.time });

            return;
        }

        setData(next);
    };

    const toggleAllDay = (allDay: boolean) => {
        // Coming back from all-day with no times yet: default to an hour from 09:00.
        if (!allDay && (data.start_time === '' || data.end_time === '')) {
            setData({ ...data, all_day: false, start_time: '09:00', end_date: data.start_date, end_time: '10:00' });

            return;
        }

        setData('all_day', allDay);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (eventId !== undefined) {
            patch(route('calendar.events.update', eventId), { preserveScroll: true });
        } else {
            post(route('calendar.events.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-2">
                <Label htmlFor="title">Title</Label>
                <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    required
                    maxLength={255}
                    autoComplete="off"
                    placeholder="e.g. Dentist"
                    autoFocus={eventId === undefined}
                />
                <InputError message={errors.title} />
            </div>

            <fieldset className="grid gap-4">
                <legend className="mb-2 text-sm font-medium">When</legend>

                <div className="flex items-center gap-2">
                    <Checkbox id="all_day" checked={data.all_day} onCheckedChange={(checked) => toggleAllDay(checked === true)} />
                    <Label htmlFor="all_day">All day</Label>
                </div>

                <div className="grid grid-cols-[1fr_auto] gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="start_date" className="text-muted-foreground text-xs">
                            {data.all_day ? 'From' : 'Starts'}
                        </Label>
                        <Input
                            id="start_date"
                            type="date"
                            value={data.start_date}
                            onChange={(e) => moveStart('start_date', e.target.value)}
                            required
                        />
                    </div>
                    {!data.all_day && (
                        <div className="grid gap-2">
                            <Label htmlFor="start_time" className="text-muted-foreground text-xs">
                                Time
                            </Label>
                            <Input
                                id="start_time"
                                type="time"
                                value={data.start_time}
                                onChange={(e) => moveStart('start_time', e.target.value)}
                                required
                                className="w-32"
                            />
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-[1fr_auto] gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="end_date" className="text-muted-foreground text-xs">
                            {data.all_day ? 'To' : 'Ends'}
                        </Label>
                        <Input
                            id="end_date"
                            type="date"
                            value={data.end_date}
                            min={data.start_date}
                            onChange={(e) => setData('end_date', e.target.value)}
                            required
                        />
                    </div>
                    {!data.all_day && (
                        <div className="grid gap-2">
                            <Label htmlFor="end_time" className="text-muted-foreground text-xs">
                                Time
                            </Label>
                            <Input
                                id="end_time"
                                type="time"
                                value={data.end_time}
                                onChange={(e) => setData('end_time', e.target.value)}
                                required
                                className="w-32"
                            />
                        </div>
                    )}
                </div>

                <InputError message={errors.start_date ?? errors.start_time ?? errors.end_date ?? errors.end_time} />

                {!data.all_day &&
                    (showZone ? (
                        <div className="grid gap-2">
                            <Label htmlFor="tzid" className="text-muted-foreground text-xs">
                                Timezone
                            </Label>
                            <select id="tzid" className={selectClass} value={data.tzid} onChange={(e) => setData('tzid', e.target.value)}>
                                {timezones.map((tz) => (
                                    <option key={tz} value={tz}>
                                        {tz.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-sm">The times above are in this timezone. You'll see them in yours.</p>
                            <InputError message={errors.tzid} />
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Times are in {data.tzid.replaceAll('_', ' ')}.{' '}
                            <button type="button" className="text-foreground underline underline-offset-4" onClick={() => setShowZone(true)}>
                                Change
                            </button>
                        </p>
                    ))}
            </fieldset>

            <div className="grid gap-2">
                <Label htmlFor="location">
                    Location <span className="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <Input id="location" value={data.location} onChange={(e) => setData('location', e.target.value)} maxLength={255} autoComplete="off" />
                <InputError message={errors.location} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">
                    Notes <span className="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <textarea
                    id="description"
                    rows={3}
                    maxLength={5000}
                    className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm"
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                />
                <InputError message={errors.description} />
            </div>

            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-medium">Who sees it</legend>

                <div className="flex items-start gap-2">
                    <Checkbox id="private" checked={data.private} onCheckedChange={(checked) => setData('private', checked === true)} />
                    <div className="grid gap-1">
                        <Label htmlFor="private">Private</Label>
                        <p className="text-muted-foreground text-sm">Only you see the details, even once you share your calendar.</p>
                    </div>
                </div>

                <div className="flex items-start gap-2">
                    <Checkbox id="busy" checked={!data.busy} onCheckedChange={(checked) => setData('busy', checked !== true)} />
                    <div className="grid gap-1">
                        <Label htmlFor="busy">Show me as free</Label>
                        <p className="text-muted-foreground text-sm">For things that don't stop you doing something else.</p>
                    </div>
                </div>
            </fieldset>

            <div className="flex items-center gap-3">
                <Button disabled={processing}>{submitLabel}</Button>
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}
