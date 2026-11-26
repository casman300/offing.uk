/** Shared types and formatting for the calendar pages (spec §23 Phase 1b). */

export interface CalendarSummary {
    id: number;
    name: string;
    colour: string;
    owner: string;
    is_crew?: boolean;
    /** A subscribed external calendar: read-only, only the subscriber sees it. */
    is_feed?: boolean;
    can_edit?: boolean;
}

/** One occurrence as `calendar.occurrences` sends it: wall-clock times in the viewer's zone. */
export interface OccurrenceData {
    id: string;
    event_id: number | null;
    /** Set on a birthday (projected from a person, not an event). */
    person_id?: number;
    occurrence: string;
    title: string;
    location: string | null;
    all_day: boolean;
    start: string;
    end: string;
    calendar_id: number;
    colour: string;
    private: boolean;
    free: boolean;
    recurring: boolean;
    /** One date of a series, edited on its own. */
    override: boolean;
    can_edit: boolean;
    busy_block: boolean;
}

/** The pseudo-calendar id birthdays come under, so they can be hidden like a calendar. */
export const BIRTHDAYS = -1;

/** Wall-clock strings carry no zone; read them as UTC so the browser's own zone never shifts them. */
const asUtc = (value: string) => new Date(value.length === 10 ? `${value}T00:00:00Z` : `${value}Z`);

const dateFmt = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' });

/** "Thu 26 Nov 2026, 09:00 – 10:30", "Thu 26 Nov – Sat 28 Nov 2026" (all-day ends are exclusive). */
export function whenText(start: string, end: string, allDay: boolean): string {
    const s = asUtc(start);
    const e = asUtc(end);

    if (allDay) {
        const last = new Date(e.getTime() - 86_400_000);
        return last.getTime() <= s.getTime() ? `${dateFmt.format(s)} · all day` : `${dateFmt.format(s)} – ${dateFmt.format(last)}`;
    }

    if (start.slice(0, 10) === end.slice(0, 10)) {
        return `${dateFmt.format(s)}, ${timeFmt.format(s)} – ${timeFmt.format(e)}`;
    }

    return `${dateFmt.format(s)}, ${timeFmt.format(s)} – ${dateFmt.format(e)}, ${timeFmt.format(e)}`;
}
