import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BarometerColumn, type BarometerReading, type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Download, Gauge, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Barometer', href: '/barometer' }];

interface Props {
    today: string;
    readings: BarometerReading[];
    nextPageUrl: string | null;
    previousPageUrl: string | null;
    exportDefaults: { from: string; to: string };
    columns: Record<BarometerColumn, string>;
    omittable: BarometerColumn[];
}

export default function BarometerIndex({ today, readings, nextPageUrl, previousPageUrl, exportDefaults, columns, omittable }: Props) {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;

    const days = useMemo(() => {
        const groups: { date: string; readings: BarometerReading[] }[] = [];
        for (const reading of readings) {
            const last = groups[groups.length - 1];
            if (last?.date === reading.date) {
                last.readings.push(reading);
            } else {
                groups.push({ date: reading.date, readings: [reading] });
            }
        }

        return groups;
    }, [readings]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Barometer" />

            <div className="mx-auto w-full max-w-2xl min-w-0 space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Barometer" description="Your private anxiety log. Only you can see it." />
                    <Button asChild className="shrink-0">
                        <Link href={route('barometer.create')}>
                            <Plus /> Take a reading
                        </Link>
                    </Button>
                </div>

                <Transition
                    show={flash.status === 'reading-saved' || flash.status === 'reading-deleted'}
                    appear
                    enter="transition ease-in-out"
                    enterFrom="opacity-0"
                    leave="transition ease-in-out"
                    leaveTo="opacity-0"
                >
                    <p role="status" className="text-muted-foreground text-sm">
                        {flash.status === 'reading-deleted' ? 'Reading deleted.' : 'Reading saved.'}
                    </p>
                </Transition>

                {readings.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center">
                        <Gauge className="mx-auto mb-3 size-8" />
                        <p className="text-foreground font-medium">No readings yet</p>
                        <p className="mt-1 text-sm">When anxiety shows up, take a reading: a time and a rating is enough.</p>
                    </div>
                ) : (
                    <div className="space-y-5">
                        {days.map((day) => (
                            <section key={day.date} aria-labelledby={`day-${day.date}`}>
                                <h3 id={`day-${day.date}`} className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">
                                    {dayLabel(day.date, today)}
                                </h3>
                                <ul className="divide-border bg-card divide-y overflow-hidden rounded-xl border">
                                    {day.readings.map((reading) => (
                                        <li key={reading.id}>
                                            <Link
                                                href={route('barometer.edit', reading.id)}
                                                className="hover:bg-accent/60 focus-visible:bg-accent grid grid-cols-[3rem_auto_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 outline-none"
                                            >
                                                {reading.time !== null ? (
                                                    <span className="text-muted-foreground text-sm tabular-nums">{reading.time}</span>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm" title="Time not recorded">
                                                        <span aria-hidden>—</span>
                                                        <span className="sr-only">Time not recorded</span>
                                                    </span>
                                                )}
                                                <Ratings reading={reading} />
                                                <span className="text-muted-foreground truncate text-sm">{reading.situation ?? ''}</span>
                                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}

                        {(previousPageUrl || nextPageUrl) && (
                            <nav className="flex justify-between" aria-label="More readings">
                                {previousPageUrl ? (
                                    <Button asChild variant="ghost">
                                        <Link href={previousPageUrl}>Newer</Link>
                                    </Button>
                                ) : (
                                    <span />
                                )}
                                {nextPageUrl && (
                                    <Button asChild variant="ghost">
                                        <Link href={nextPageUrl}>Older</Link>
                                    </Button>
                                )}
                            </nav>
                        )}
                    </div>
                )}

                <ExportPanel defaults={exportDefaults} columns={columns} omittable={omittable} errors={errors} />

                <p className="text-muted-foreground border-t pt-4 text-xs leading-relaxed">
                    Barometer follows a common CBT (cognitive behavioural therapy) anxiety-tracking format. It works best alongside a therapist, and
                    isn’t a substitute for care. If you’re struggling right now, Samaritans are there day or night on{' '}
                    <a href="tel:116123" className="text-foreground underline underline-offset-2">
                        116 123
                    </a>{' '}
                    (free, UK).
                </p>
            </div>
        </AppLayout>
    );
}

function Ratings({ reading }: { reading: BarometerReading }) {
    return (
        <span className="flex shrink-0 items-center gap-1.5 tabular-nums" aria-label={ratingsLabel(reading)}>
            <span className="bg-secondary rounded-md px-2 py-0.5 font-semibold">{reading.rating_before}</span>
            {reading.rating_after !== null ? (
                <>
                    <span className="text-muted-foreground" aria-hidden>
                        →
                    </span>
                    <span className="bg-secondary rounded-md px-2 py-0.5 font-semibold">{reading.rating_after}</span>
                </>
            ) : (
                <span className="text-muted-foreground text-xs">add 2nd</span>
            )}
        </span>
    );
}

function ExportPanel({
    defaults,
    columns,
    omittable,
    errors,
}: {
    defaults: { from: string; to: string };
    columns: Record<BarometerColumn, string>;
    omittable: BarometerColumn[];
    errors: Record<string, string>;
}) {
    const [from, setFrom] = useState(defaults.from);
    const [to, setTo] = useState(defaults.to);
    const [omit, setOmit] = useState<BarometerColumn[]>([]);

    const href = (format: 'pdf' | 'csv') => route('barometer.export', { format, from, to, ...(omit.length > 0 ? { omit } : {}) });
    const toggle = (column: BarometerColumn, included: boolean) =>
        setOmit((current) => (included ? current.filter((c) => c !== column) : [...current, column]));

    return (
        <section aria-labelledby="export-heading" className="bg-card space-y-4 rounded-xl border p-4">
            <div>
                <h3 id="export-heading" className="font-medium">
                    Export for your therapist
                </h3>
                <p className="text-muted-foreground text-sm">A printable PDF laid out like the paper form, or a CSV for a spreadsheet.</p>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <div className="grid gap-2">
                    <Label htmlFor="export-from">From</Label>
                    <Input id="export-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
                    <InputError message={errors.from} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="export-to">To</Label>
                    <Input id="export-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
                    <InputError message={errors.to} />
                </div>
            </div>

            <fieldset>
                <legend className="mb-2 text-sm font-medium">Include</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {omittable.map((column) => (
                        <div key={column} className="flex items-center gap-2">
                            <Checkbox
                                id={`include-${column}`}
                                checked={!omit.includes(column)}
                                onCheckedChange={(checked) => toggle(column, checked === true)}
                            />
                            <Label htmlFor={`include-${column}`} className="font-normal">
                                {columns[column]}
                            </Label>
                        </div>
                    ))}
                </div>
                <p className="text-muted-foreground mt-2 text-xs">The day, time and first rating are always included.</p>
            </fieldset>

            <div className="flex flex-wrap gap-2">
                {/* Plain links, not Inertia visits: these are file downloads. */}
                <Button asChild>
                    <a href={href('pdf')}>
                        <Download /> Download PDF
                    </a>
                </Button>
                <Button asChild variant="outline">
                    <a href={href('csv')}>
                        <Download /> Download CSV
                    </a>
                </Button>
            </div>
        </section>
    );
}

function ratingsLabel(reading: BarometerReading): string {
    return reading.rating_after !== null
        ? `Rated ${reading.rating_before}, then ${reading.rating_after}`
        : `Rated ${reading.rating_before}, no second rating yet`;
}

const dayFormat = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

/** "Today", "Yesterday", or "Tue 14 Oct 2026" — dates are local YYYY-MM-DD strings, so do the maths in UTC. */
function dayLabel(date: string, today: string): string {
    const toUtc = (d: string) => {
        const [y, m, day] = d.split('-').map(Number);
        return Date.UTC(y, m - 1, day);
    };
    const diff = Math.round((toUtc(today) - toUtc(date)) / 86_400_000);

    if (diff === 0) {
        return 'Today';
    }
    if (diff === 1) {
        return 'Yesterday';
    }

    return dayFormat.format(toUtc(date)).replace(/,/g, '');
}
