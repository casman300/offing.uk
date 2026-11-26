import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type CalendarSummary } from '@/lib/calendar';
import calendarRoutes from '@/routes/calendar';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, FileUp } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Calendar', href: '/calendar' },
    { title: 'Import', href: '/calendar/import' },
];

const field =
    'border-input bg-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none';

interface Preview {
    calendar_id: number;
    new: number;
    existing: number;
    cancelled: number;
    repeating: number;
    errors: string[];
    from: string | null;
    to: string | null;
    name: string | null;
}

function dateText(iso: string, timezone: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: timezone });
}

export default function Import({ calendars, preview, timezone }: { calendars: CalendarSummary[]; preview: Preview | null; timezone: string }) {
    const { data, setData, post, processing, errors, progress } = useForm<{ file: File | null; calendar_id: number | '' }>({
        file: null,
        calendar_id: preview?.calendar_id ?? calendars[0]?.id ?? '',
    });

    // A preview belongs to the file and calendar it was made for.
    const current = preview !== null && data.file !== null && preview.calendar_id === data.calendar_id ? preview : null;

    const check: FormEventHandler = (e) => {
        e.preventDefault();
        post(calendarRoutes.import.preview.url(), { forceFormData: true, preserveState: true, preserveScroll: true });
    };

    const confirm = () => post(calendarRoutes.import.store.url(), { forceFormData: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import a calendar file" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="Import a calendar file"
                    description="Add the events from an .ics file to one of your calendars, once. They become your own events, so you can change them. To keep a calendar in step with its source, subscribe to it instead."
                />

                {calendars.length === 0 ? (
                    <p className="text-muted-foreground">You don’t have a calendar you can add events to.</p>
                ) : (
                    <form onSubmit={check} className="space-y-4 rounded-xl border p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="file">File</Label>
                            <input
                                id="file"
                                type="file"
                                accept=".ics,.ical,.ifb,text/calendar"
                                className={`${field} file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium`}
                                onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                                required
                            />
                            <InputError message={errors.file} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="calendar_id">Into</Label>
                            <select
                                id="calendar_id"
                                className={field}
                                value={data.calendar_id}
                                onChange={(e) => setData('calendar_id', Number(e.target.value))}
                            >
                                {calendars.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                        {c.owner !== 'Just me' ? ` (${c.owner})` : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.calendar_id} />
                        </div>
                        {progress && <progress value={progress.percentage} max="100" className="w-full" />}
                        <Button variant={current ? 'outline' : 'default'} disabled={processing || data.file === null}>
                            <FileUp /> {current ? 'Check again' : 'Check the file'}
                        </Button>
                    </form>
                )}

                {current && (
                    <section aria-labelledby="preview-heading" className="space-y-4 rounded-xl border p-4">
                        <h2 id="preview-heading" className="font-semibold">
                            {current.name ? `“${current.name}”` : 'What’s in the file'}
                        </h2>
                        <ul className="space-y-1 text-sm">
                            <li>
                                <strong>{current.new}</strong> {current.new === 1 ? 'event' : 'events'} to import
                                {current.repeating > 0 && ` (${current.repeating} repeating)`}
                            </li>
                            {current.from && current.to && (
                                <li>
                                    From {dateText(current.from, timezone)} to {dateText(current.to, timezone)}
                                </li>
                            )}
                            {current.existing > 0 && (
                                <li className="text-muted-foreground">
                                    {current.existing} {current.existing === 1 ? 'is' : 'are'} already in that calendar and will be left alone
                                </li>
                            )}
                            {current.cancelled > 0 && <li className="text-muted-foreground">{current.cancelled} cancelled, so skipped</li>}
                        </ul>
                        {current.errors.length > 0 && (
                            <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm">
                                <p className="flex items-center gap-2 font-medium">
                                    <AlertTriangle className="size-4" /> Some events can’t be imported
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {current.errors.map((error, i) => (
                                        <li key={i}>{error}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <Button onClick={confirm} disabled={processing || current.new === 0}>
                            {current.new === 0 ? 'Nothing new to import' : `Import ${current.new} ${current.new === 1 ? 'event' : 'events'}`}
                        </Button>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
