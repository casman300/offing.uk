import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import barometer from '@/routes/barometer';
import { type BarometerReading, type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Download, Gauge, Plus } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Barometer', href: '/barometer' }];

interface Props {
    today: string;
    readings: BarometerReading[];
    nextPageUrl: string | null;
    previousPageUrl: string | null;
}

export default function BarometerIndex({ today, readings, nextPageUrl, previousPageUrl }: Props) {
    const { flash } = usePage<SharedData>().props;

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
                    <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <Button asChild variant="outline">
                            <Link href={barometer.export.url()}>
                                <Download /> Export
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={barometer.create.url()}>
                                <Plus /> Take a reading
                            </Link>
                        </Button>
                    </div>
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
                        <p className="mt-1 text-sm">When anxiety shows up, take a reading: a rating is all it needs.</p>
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
                                                href={barometer.edit.url(reading.id)}
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
