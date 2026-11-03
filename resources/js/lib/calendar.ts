import { MONTHS, parseDate } from '@/lib/birthday';
import { type CalendarEvent } from '@/types';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/*
 * Dates here are plain YYYY-MM-DD strings and times HH:MM, already in the
 * right timezone (the server converts). The maths uses UTC so the browser's
 * own zone and DST never move a date.
 */

function toUtc(iso: string): Date {
    const { year, month, day } = parseDate(iso);

    return new Date(Date.UTC(year, month - 1, day));
}

function toIso(date: Date): string {
    return date.toISOString().slice(0, 10);
}

export function addDays(iso: string, days: number): string {
    const date = toUtc(iso);
    date.setUTCDate(date.getUTCDate() + days);

    return toIso(date);
}

export function daysBetween(from: string, to: string): number {
    return Math.round((toUtc(to).getTime() - toUtc(from).getTime()) / 86_400_000);
}

/** "Mon 20 Oct", with the year when it isn't this year. */
export function formatDay(iso: string, today: string): string {
    const date = toUtc(iso);
    const { year, month, day } = parseDate(iso);
    const label = `${WEEKDAYS[date.getUTCDay()]} ${day} ${MONTHS[month - 1].slice(0, 3)}`;

    return year === parseDate(today).year ? label : `${label} ${year}`;
}

/** Agenda heading: Today / Tomorrow / Mon 20 Oct. */
export function dayHeading(iso: string, today: string): string {
    if (iso === today) {
        return 'Today';
    }

    if (iso === addDays(today, 1)) {
        return 'Tomorrow';
    }

    return formatDay(iso, today);
}

/** The agenda day an event sits under: its start, or today if it started earlier and is still going. */
export function agendaDay(event: CalendarEvent, today: string): string {
    return event.start_date < today ? today : event.start_date;
}

/** "09:00 – 10:30", "All day", "Until Fri 24 Oct", "Until 14:00 Sat 25 Oct"… */
export function timeSummary(event: CalendarEvent, today: string): string {
    if (event.all_day) {
        return event.end_date === event.start_date ? 'All day' : `All day, until ${formatDay(event.end_date, today)}`;
    }

    if (event.end_date === event.start_date) {
        return `${event.start_time} – ${event.end_time}`;
    }

    // Overnight or multi-day: say when it ends. An end at midnight reads as the day before.
    const until = `${event.end_time} ${formatDay(event.end_date, today)}`;

    return event.start_date < today ? `Until ${until}` : `${event.start_time} – ${until}`;
}

/** "20:00 in Europe/Paris" for events made somewhere else. */
export function originSummary(event: CalendarEvent, today: string): string | null {
    if (event.origin === null) {
        return null;
    }

    const day = event.origin.date === event.start_date ? '' : ` ${formatDay(event.origin.date, today)}`;

    return `${event.origin.time}${day} in ${event.origin.tzid.replaceAll('_', ' ')}`;
}

/** Minutes since 1970 for a wall-clock date + time, ignoring any timezone. */
export function wallMinutes(date: string, time: string): number {
    const [h, m] = time.split(':').map(Number);

    return toUtc(date).getTime() / 60_000 + h * 60 + m;
}

export function fromWallMinutes(minutes: number): { date: string; time: string } {
    const d = new Date(minutes * 60_000);

    return { date: toIso(d), time: d.toISOString().slice(11, 16) };
}

/** Today's date and the current hour in a timezone. */
export function nowIn(tz: string): { date: string; hour: number } {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: tz,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        hour12: false,
    })
        .formatToParts(new Date())
        .reduce<Record<string, string>>((acc, part) => ({ ...acc, [part.type]: part.value }), {});

    return { date: `${parts.year}-${parts.month}-${parts.day}`, hour: Number(parts.hour) % 24 };
}
