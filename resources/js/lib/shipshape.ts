// Shipshape (spec §25) — shared shapes and wording for chores.

export type ChoreGroup = 'overdue' | 'today' | 'soon' | 'later' | 'nodate';

export interface ListMember {
    id: number;
    name: string;
    kind: 'crew' | 'passenger';
    is_me: boolean;
}

export interface ListSummary {
    id: number;
    name: string;
    owner: string;
    is_crew: boolean;
    editable: boolean;
    members: ListMember[];
}

export interface ChoreRow {
    id: number;
    list_id: number;
    title: string;
    area: string | null;
    notes: string | null;
    mode: 'floating' | 'fixed';
    every: number;
    unit: 'day' | 'week' | 'month' | 'year';
    due_on: string | null;
    days: number | null;
    group: ChoreGroup;
    heads_up: boolean;
    lead_days: number | null;
    assign: 'anyone' | 'member' | 'rota';
    who: { member_id: number; name: string; is_me: boolean; kind: 'crew' | 'passenger' } | null;
    version: number;
}

export const GROUPS: { key: ChoreGroup; label: string; hint?: string }[] = [
    { key: 'overdue', label: 'Overdue' },
    { key: 'today', label: 'Today' },
    { key: 'soon', label: 'Next 7 days' },
    { key: 'later', label: 'Later' },
    { key: 'nodate', label: 'Needs a date', hint: 'Add a date and these join the schedule. They never show as overdue.' },
];

const fmt = (iso: string, opts: Intl.DateTimeFormatOptions) => new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, opts);

/** "3 days late", "Today", "Tomorrow", "Tue", "7 Nov", "Needs a date". */
export function dueLabel(chore: ChoreRow): string {
    const { days, due_on } = chore;

    if (days === null || due_on === null) return 'Needs a date';
    if (days < 0) return days === -1 ? '1 day late' : `${-days} days late`;
    if (days === 0) return 'Today';
    if (days === 1) return 'Tomorrow';
    if (days < 7) return fmt(due_on, { weekday: 'short' });
    if (days < 300) return fmt(due_on, { day: 'numeric', month: 'short' });

    return fmt(due_on, { day: 'numeric', month: 'short', year: 'numeric' });
}

const UNIT_WORDS: Record<ChoreRow['unit'], [string, string]> = {
    day: ['day', 'days'],
    week: ['week', 'weeks'],
    month: ['month', 'months'],
    year: ['year', 'years'],
};

/** "Every week", "Every 3 months". */
export function cadence(every: number, unit: ChoreRow['unit']): string {
    const [one, many] = UNIT_WORDS[unit];

    return every === 1 ? `Every ${one}` : `Every ${every} ${many}`;
}

/** "Your turn", "Sam's turn", "Sam", "You", "Anyone". */
export function whoLabel(chore: ChoreRow): string {
    if (chore.who === null) return 'Anyone';
    if (chore.assign === 'rota') return chore.who.is_me ? 'Your turn' : `${chore.who.name}'s turn`;

    return chore.who.is_me ? 'You' : chore.who.name;
}
