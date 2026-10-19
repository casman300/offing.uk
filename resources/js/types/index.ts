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
    calendar: boolean;
}

export interface Flash {
    status: string | null;
    deleted: { id: number; name: string } | null;
    feedUrl: { id: number; url: string } | null;
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

/** An event as the agenda shows it: dates and times are in the viewer's timezone (all-day events are plain dates). */
export interface CalendarEvent {
    id: number;
    title: string;
    location: string | null;
    description: string | null;
    all_day: boolean;
    private: boolean;
    busy: boolean;
    tzid: string;
    /** YYYY-MM-DD */
    start_date: string;
    /** HH:MM; null for all-day events. */
    start_time: string | null;
    /** YYYY-MM-DD; the last day for all-day events (inclusive). */
    end_date: string;
    end_time: string | null;
    /** The start where the event was made, when it reads differently from the viewer's. */
    origin: { date: string; time: string; tzid: string } | null;
    deleted_at: string | null;
}

/** Form values for an event, in the event's own timezone. */
// A type alias, not an interface, so Inertia's useForm accepts it.
export type EventFormValues = {
    title: string;
    location: string;
    description: string;
    all_day: boolean;
    tzid: string;
    start_date: string;
    start_time: string;
    end_date: string;
    end_time: string;
    private: boolean;
    busy: boolean;
};
