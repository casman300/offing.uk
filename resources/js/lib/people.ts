import { formatShortDate, MONTHS } from '@/lib/birthday';
import { type Person } from '@/types';

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

/** One-line birthday summary for the people list. */
export function birthdaySummary(person: Person): string {
    if (person.days_until === 0) {
        return person.turns !== null ? `Turns ${person.turns} today 🎂` : 'Birthday today 🎂';
    }

    if (person.days_until !== null && person.next_birthday !== null) {
        const when = person.days_until === 1 ? 'tomorrow' : `in ${plural(person.days_until, 'day')}`;
        const lead = person.turns !== null ? `Turns ${person.turns} ${when}` : `Birthday ${when}`;

        return `${lead} · ${formatShortDate(person.next_birthday)}`;
    }

    if (person.age_is_approximate && person.birth_year !== null) {
        return `Born ${person.birth_year} · ~${person.age}`;
    }

    return 'No birthday yet';
}

/** Upcoming first (today on top), then year-only and no-birthday people A–Z. */
export function byUpcoming(a: Person, b: Person): number {
    if (a.days_until !== null && b.days_until !== null && a.days_until !== b.days_until) {
        return a.days_until - b.days_until;
    }

    if ((a.days_until === null) !== (b.days_until === null)) {
        return a.days_until === null ? 1 : -1;
    }

    return byName(a, b);
}

export function byName(a: Person, b: Person): number {
    return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
}

export function birthdayLongDate(person: Person): string | null {
    if (person.birth_day === null || person.birth_month === null) {
        return null;
    }

    return `${person.birth_day} ${MONTHS[person.birth_month - 1]}`;
}
