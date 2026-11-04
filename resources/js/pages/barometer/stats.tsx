import BarometerCareNote from '@/components/barometer-care-note';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import barometer from '@/routes/barometer';
import { type BarometerStats, type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BarChart3, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Barometer', href: barometer.index.url() },
    { title: 'Look back', href: barometer.stats.url() },
];

interface Props {
    stats: BarometerStats;
    today: string;
    presets: { label: string; from: string; to: string }[];
}

/**
 * Barometer look-back (#37): the same numbers the AI assistant gets, over
 * a date range. Every word here describes; none interprets ("down 2.4",
 * never "improving").
 */
export default function BarometerStatsPage({ stats, today, presets }: Props) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [from, setFrom] = useState(stats.from);
    const [to, setTo] = useState(stats.to);

    const show = (range: { from: string; to: string }) => router.get(barometer.stats.url(), range, { preserveScroll: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        show({ from, to });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Look back" />

            <div className="mx-auto w-full max-w-2xl min-w-0 space-y-6 p-4">
                <Heading title="Look back" description="Numbers from your readings. They describe your log; they don’t diagnose anything." />

                <form onSubmit={submit} className="space-y-3" aria-label="Date range">
                    <div className="flex flex-wrap gap-2">
                        {presets.map((preset) => {
                            const active = preset.from === stats.from && preset.to === stats.to;
                            return (
                                <Button
                                    key={preset.label}
                                    type="button"
                                    size="sm"
                                    variant={active ? 'secondary' : 'outline'}
                                    aria-pressed={active}
                                    onClick={() => show(preset)}
                                >
                                    {preset.label}
                                </Button>
                            );
                        })}
                    </div>
                    <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="stats-from" className="text-muted-foreground text-xs">
                                From
                            </Label>
                            <Input id="stats-from" className="min-w-0" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="stats-to" className="text-muted-foreground text-xs">
                                To
                            </Label>
                            <Input
                                id="stats-to"
                                className="min-w-0"
                                type="date"
                                value={to}
                                min={from}
                                max={today}
                                onChange={(e) => setTo(e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        {longDate(stats.from)} to {longDate(stats.to)} · {plural(stats.days, 'day')} · up to a year at a time.
                    </p>
                    <InputError message={errors.from ?? errors.to} />
                </form>

                {stats.count === 0 || stats.first === null ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center">
                        <BarChart3 className="mx-auto mb-3 size-8" />
                        <p className="text-foreground font-medium">No readings in this range</p>
                        <p className="mt-1 text-sm">Pick a longer range, or take a reading: the numbers build up from there.</p>
                        <Button asChild className="mt-4" size="sm">
                            <Link href={barometer.create.url()}>
                                <Plus /> Take a reading
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        <SecondRating stats={stats} />
                        <Weeks stats={stats} />
                        <Heatmap stats={stats} />
                    </>
                )}

                <BarometerCareNote />
            </div>
        </AppLayout>
    );
}

/** The headline: first rating to second, over the readings that have both. */
function SecondRating({ stats }: { stats: BarometerStats }) {
    const { first, second } = stats;

    return (
        <Card>
            <CardHeader>
                <CardDescription>First rating to second rating</CardDescription>
                {second === null ? (
                    <CardTitle className="text-lg">No second ratings in this range</CardTitle>
                ) : (
                    <CardTitle className="text-2xl tabular-nums">
                        Average {one(second.before)}, {change(second.change)} after what you did
                    </CardTitle>
                )}
            </CardHeader>
            <CardContent className="text-muted-foreground space-y-1 text-sm">
                {second === null ? (
                    <p>After you’ve done something about the anxiety, add a second rating to the reading and the change shows here.</p>
                ) : (
                    <p>
                        {one(second.before)} then {one(second.after)}, over the {plural(second.paired, 'reading')} with a second rating (of{' '}
                        {stats.count}).
                    </p>
                )}
                {first !== null && (
                    <p>
                        {plural(stats.count, 'reading')} in all. First rating: average {one(first.average)}, lowest {first.lowest}, highest{' '}
                        {first.highest}.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

/** Week by week: one bar per week, its length the average first rating out of 10. */
function Weeks({ stats }: { stats: BarometerStats }) {
    return (
        <section aria-labelledby="weeks-heading" className="space-y-3">
            <div>
                <h3 id="weeks-heading" className="font-medium">
                    By week
                </h3>
                <p className="text-muted-foreground text-sm">
                    Average first rating (0 calm – 10 most anxious) and number of readings, Monday to Sunday.
                </p>
            </div>
            <ul className="space-y-1.5">
                {stats.weeks.map((week) => (
                    <li key={week.start} className="grid grid-cols-[4.5rem_minmax(0,1fr)_6.5rem] items-center gap-3 text-sm">
                        <span className="text-muted-foreground tabular-nums">{shortDate(week.start)}</span>
                        {week.average === null ? (
                            <span className="bg-muted h-2 rounded-full" aria-hidden />
                        ) : (
                            <span className="bg-muted h-5 overflow-hidden rounded" aria-hidden>
                                <span className="bg-primary block h-full rounded" style={{ width: `${Math.max(week.average * 10, 2)}%` }} />
                            </span>
                        )}
                        <span className="tabular-nums" title={weekTitle(week)}>
                            {week.average === null ? (
                                <span className="text-muted-foreground">No readings</span>
                            ) : (
                                <>
                                    <span className="font-semibold">{one(week.average)}</span>
                                    <span className="text-muted-foreground"> · {week.count}</span>
                                </>
                            )}
                            <span className="sr-only">{weekTitle(week)}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function weekTitle(week: BarometerStats['weeks'][number]): string {
    if (week.average === null) {
        return `Week starting ${longDate(week.start)}: no readings`;
    }

    const changed = week.change === null ? '' : `; ${change(week.change)} on ${plural(week.paired, 'second rating')}`;

    return `Week starting ${longDate(week.start)}: ${plural(week.count, 'reading')}, average ${one(week.average)}${changed}`;
}

/** Weekday × time of day, shaded by how many readings. A plain CSS grid, no chart library. */
function Heatmap({ stats }: { stats: BarometerStats }) {
    const max = Math.max(1, ...stats.heatmap.flatMap((row) => row.cells.map((cell) => cell.count)));
    const timed = stats.count - stats.untimed;
    const times = Object.fromEntries(stats.timesOfDay.map((time) => [time.key, time]));

    return (
        <section aria-labelledby="heatmap-heading" className="space-y-3">
            <div>
                <h3 id="heatmap-heading" className="font-medium">
                    When readings happen
                </h3>
                <p className="text-muted-foreground text-sm">
                    Number of readings by weekday and time of day (where they happened), with their average first rating.
                </p>
            </div>

            <div
                role="table"
                aria-label="Readings by weekday and time of day"
                className="grid grid-cols-[2.25rem_repeat(4,minmax(0,1fr))_2.5rem] gap-1"
            >
                <div role="row" className="contents">
                    <span role="columnheader" />
                    {stats.timesOfDay.map((time) => (
                        <span key={time.key} role="columnheader" className="text-muted-foreground pb-1 text-center text-xs leading-tight">
                            {time.label}
                            <span className="block text-[0.65rem] opacity-80">{time.hours}</span>
                        </span>
                    ))}
                    <span role="columnheader" className="text-muted-foreground pb-1 text-center text-xs">
                        All
                    </span>
                </div>
                {stats.heatmap.map((row) => (
                    <div key={row.weekday} role="row" className="contents">
                        <span role="rowheader" className="text-muted-foreground self-center text-xs">
                            {row.label}
                        </span>
                        {row.cells.map((cell) => {
                            const share = cell.count === 0 ? 0 : 15 + Math.round((85 * cell.count) / max);
                            const label = `${row.label} ${times[cell.time]?.label.toLowerCase() ?? cell.time}: ${plural(cell.count, 'reading')}${
                                cell.average === null ? '' : `, average ${one(cell.average)}`
                            }`;
                            return (
                                <span
                                    key={cell.time}
                                    role="cell"
                                    tabIndex={0}
                                    title={label}
                                    aria-label={label}
                                    className={`focus-visible:ring-ring flex h-12 flex-col items-center justify-center rounded-md text-sm leading-tight tabular-nums outline-none focus-visible:ring-2 ${
                                        cell.count === 0
                                            ? 'bg-muted/60 text-muted-foreground'
                                            : share > 65
                                              ? 'text-primary-foreground'
                                              : 'text-foreground'
                                    }`}
                                    style={
                                        cell.count === 0
                                            ? undefined
                                            : { backgroundColor: `color-mix(in oklab, var(--primary) ${share}%, transparent)` }
                                    }
                                >
                                    {cell.count === 0 ? (
                                        <span aria-hidden>·</span>
                                    ) : (
                                        <>
                                            <span className="font-semibold">{cell.count}</span>
                                            {cell.average !== null && <span className="text-[0.65rem] opacity-90">avg {one(cell.average)}</span>}
                                        </>
                                    )}
                                </span>
                            );
                        })}
                        <span
                            role="cell"
                            className="self-center text-center text-sm font-medium tabular-nums"
                            aria-label={`${row.label}: ${plural(row.total, 'reading')} in all`}
                        >
                            {row.total}
                        </span>
                    </div>
                ))}
            </div>

            <p className="text-muted-foreground text-xs">
                Morning 5am–noon, afternoon noon–5pm, evening 5–10pm, night 10pm–5am (the night counts on the day it started).
                {stats.untimed > 0 &&
                    ` ${plural(stats.untimed, 'reading')} without a time ${stats.untimed === 1 ? 'is' : 'are'} counted under All only, so the squares add up to ${timed}.`}
            </p>
        </section>
    );
}

/** One decimal place, as the numbers are averages. */
function one(value: number): string {
    return value.toFixed(1);
}

/** "down 2.4" / "up 0.5" / "unchanged": a change in rating, described, never judged. */
function change(value: number): string {
    if (value < 0) {
        return `down ${one(-value)}`;
    }
    if (value > 0) {
        return `up ${one(value)}`;
    }

    return 'unchanged';
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

const toUtc = (date: string) => {
    const [y, m, d] = date.split('-').map(Number);
    return Date.UTC(y, m - 1, d);
};
const shortFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const longFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

/** Dates are local YYYY-MM-DD strings, so format them in UTC. */
function shortDate(date: string): string {
    return shortFormat.format(toUtc(date));
}

function longDate(date: string): string {
    return longFormat.format(toUtc(date));
}
