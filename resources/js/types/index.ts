import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    isAdmin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface Features {
    birthdayEngine: boolean;
    vcardImport: boolean;
    reminders: boolean;
    crews: boolean;
    shipshape: boolean;
    connectClaude: boolean;
    barometer: boolean;
}

export interface Flash {
    status: string | null;
    deleted: { id: number; name: string } | null;
    feedUrl: { id: number; url: string } | null;
    /** A copied crew invite link, shown once. */
    inviteUrl: string | null;
    logged: { entry_id: number; title: string; kind: 'done' | 'skip'; by: string } | null;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    features: Features;
    flash: Flash;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    timezone: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export interface Person {
    id: number;
    name: string;
    birth_day: number | null;
    birth_month: number | null;
    birth_year: number | null;
    notes: string | null;
    /** YYYY-MM-DD in the viewer's timezone; null without day + month. */
    next_birthday: string | null;
    days_until: number | null;
    /** Exact age, or year-maths age when only the year is known (see age_is_approximate). */
    age: number | null;
    age_is_approximate: boolean;
    /** The age they turn on next_birthday; null without a year. */
    turns: number | null;
    deleted_at: string | null;
}

export type CrewRole = 'captain' | 'crew' | 'passenger';

export type BarometerTextColumn = 'situation' | 'thoughts' | 'sensations' | 'coping_action' | 'self_talk';
export type BarometerColumn = BarometerTextColumn | 'rating_before' | 'rating_after';

export interface BarometerReading {
    id: number;
    /** YYYY-MM-DD in the viewer's timezone. */
    date: string;
    /** HH:MM in the viewer's timezone; null when the time wasn't recorded. */
    time: string | null;
    situation: string | null;
    thoughts: string | null;
    sensations: string | null;
    rating_before: number;
    coping_action: string | null;
    self_talk: string | null;
    rating_after: number | null;
}

/** Barometer look-back numbers (#37): App\Support\Barometer\Stats::toPageArray(). Averages are to one decimal place. */
export interface BarometerStats {
    from: string;
    to: string;
    days: number;
    count: number;
    /** Readings without a time: left out of time of day, still counted by day. */
    untimed: number;
    first: { average: number; lowest: number; highest: number } | null;
    /** Over readings with both ratings; `change` is second minus first (a drop is negative). */
    second: { paired: number; before: number; after: number; change: number } | null;
    /** Every Monday-to-Sunday week overlapping the range, oldest first. */
    weeks: { start: string; count: number; average: number | null; paired: number; change: number | null }[];
    timesOfDay: { key: string; label: string; hours: string }[];
    /** Monday first; `cells` follow `timesOfDay`; `total` includes readings without a time. */
    heatmap: { weekday: number; label: string; total: number; cells: { time: string; count: number; average: number | null }[] }[];
}
