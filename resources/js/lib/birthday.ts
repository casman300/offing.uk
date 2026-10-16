// Client-side mirror of app/Support/Birthday.php — keep them in step.
// "today" is always the viewer's local date as YYYY-MM-DD, supplied by the
// server, so the browser's own clock/zone never decides a birthday.

export const MIN_YEAR = 1900;

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export interface LocalDate {
    year: number;
    month: number;
    day: number;
}

export function parseDate(iso: string): LocalDate {
    const [year, month, day] = iso.split('-').map(Number);

    return { year, month, day };
}

export function isLeapYear(year: number): boolean {
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

export function daysInMonth(month: number, year: number | null): number {
    if (month === 2) {
        return year === null || isLeapYear(year) ? 29 : 28;
    }

    return [4, 6, 9, 11].includes(month) ? 30 : 31;
}

export function isValidDate(day: number, month: number, year: number | null): boolean {
    return month >= 1 && month <= 12 && day >= 1 && day <= daysInMonth(month, year);
}

/** 29 Feb is observed on 28 Feb in non-leap years. */
function observedDay(day: number, month: number, year: number): number {
    return month === 2 && day === 29 && !isLeapYear(year) ? 28 : day;
}

function hasHappenedThisYear(day: number, month: number, today: LocalDate): boolean {
    const observed = observedDay(day, month, today.year);

    return today.month > month || (today.month === month && today.day >= observed);
}

/** Age → birth year. Only meaningful once day + month are known. */
export function yearFromAge(age: number, day: number, month: number, today: LocalDate): number {
    return today.year - age - (hasHappenedThisYear(day, month, today) ? 0 : 1);
}

/** Exact age today, from a full date. */
export function ageFrom(day: number, month: number, year: number, today: LocalDate): number {
    return today.year - year - (hasHappenedThisYear(day, month, today) ? 0 : 1);
}

export function formatBirthday(day: number | null, month: number | null, year: number | null): string | null {
    if (day !== null && month !== null) {
        return `${day} ${MONTHS[month - 1]}${year !== null ? ` ${year}` : ''}`;
    }

    return year !== null ? `Born ${year}` : null;
}

export function formatShortDate(iso: string): string {
    const { day, month } = parseDate(iso);

    return `${day} ${MONTHS[month - 1].slice(0, 3)}`;
}
