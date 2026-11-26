import ColourSwatches from '@/components/colour-swatches';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import calendarRoutes from '@/routes/calendar';
import subscriptions from '@/routes/subscriptions';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FileUp, Rss, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ManagedCalendar {
    id: number;
    name: string;
    colour: string;
    owner: string;
    is_crew: boolean;
    /** A subscribed feed: read-only here, managed in Settings → Subscriptions. */
    is_feed: boolean;
    editors: 'crew' | 'captains';
    event_count: number;
    can_manage: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Calendar', href: '/calendar' },
    { title: 'Calendars', href: '/calendar/calendars' },
];

const field =
    'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';

function CalendarRow({ calendar, colours, canDelete }: { calendar: ManagedCalendar; colours: string[]; canDelete: boolean }) {
    const update = (patch: Partial<Pick<ManagedCalendar, 'name' | 'colour' | 'editors'>>) =>
        router.patch(calendarRoutes.calendars.update.url(calendar.id), patch, { preserveScroll: true });

    const destroy = () => {
        const what = calendar.event_count === 1 ? '1 event' : `${calendar.event_count} events`;
        if (window.confirm(`Delete “${calendar.name}” and its ${what}${calendar.is_crew ? ` for everyone in ${calendar.owner}` : ''}?`)) {
            router.delete(calendarRoutes.calendars.destroy.url(calendar.id), { preserveScroll: true });
        }
    };

    return (
        <li className="bg-card space-y-3 rounded-xl border p-4">
            <div className="flex items-center gap-3">
                <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: calendar.colour }} />
                {calendar.can_manage ? (
                    <Input
                        aria-label="Name"
                        defaultValue={calendar.name}
                        maxLength={60}
                        onBlur={(e) => e.target.value.trim() && e.target.value !== calendar.name && update({ name: e.target.value })}
                    />
                ) : (
                    <span className="font-medium">{calendar.name}</span>
                )}
            </div>
            <p className="text-muted-foreground text-sm">
                {calendar.is_feed ? 'Subscribed, read-only' : calendar.is_crew ? `Shared with ${calendar.owner}` : 'Just you'} ·{' '}
                {calendar.event_count === 1 ? '1 event' : `${calendar.event_count} events`}
                {calendar.is_feed && (
                    <>
                        {' · '}
                        <Link href={subscriptions.index.url()} className="underline underline-offset-4">
                            Manage in Settings
                        </Link>
                    </>
                )}
            </p>
            {calendar.can_manage && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <ColourSwatches
                        colours={colours}
                        value={calendar.colour}
                        onChange={(colour) => update({ colour })}
                        label={`Colour for ${calendar.name}`}
                    />
                    <div className="flex items-center gap-2">
                        {calendar.is_crew && (
                            <select
                                aria-label="Who can edit"
                                className={`${field} h-9 w-auto`}
                                value={calendar.editors}
                                onChange={(e) => update({ editors: e.target.value as ManagedCalendar['editors'] })}
                            >
                                <option value="crew">All crew can edit</option>
                                <option value="captains">Captains only</option>
                            </select>
                        )}
                        {canDelete && (
                            <Button variant="outline" size="sm" onClick={destroy}>
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </li>
    );
}

export default function Calendars({
    calendars,
    crews,
    colours,
}: {
    calendars: ManagedCalendar[];
    crews: { id: number; name: string }[];
    colours: string[];
}) {
    const { flash } = usePage<SharedData>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', colour: colours[1] ?? colours[0], owner: 'me' });
    const ownCount = calendars.filter((c) => !c.is_crew && !c.is_feed).length;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(calendarRoutes.calendars.store.url(), { preserveScroll: true, onSuccess: () => reset('name') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendars" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading title="Calendars" description="Yours are just for you. A crew’s calendar is shared with everyone aboard." />

                {flash.status && (
                    <div role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" size="sm">
                        <Link href={subscriptions.index.url()}>
                            <Rss /> Subscribe to a calendar
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href={calendarRoutes.import.create.url()}>
                            <FileUp /> Import an .ics file
                        </Link>
                    </Button>
                </div>

                <ul className="space-y-3">
                    {calendars.map((calendar) => (
                        <CalendarRow key={calendar.id} calendar={calendar} colours={colours} canDelete={calendar.is_crew || ownCount > 1} />
                    ))}
                </ul>

                <form onSubmit={submit} className="space-y-4 rounded-xl border border-dashed p-4">
                    <h2 className="font-semibold">Add a calendar</h2>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            required
                            maxLength={60}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Work"
                        />
                        <InputError message={errors.name} />
                    </div>
                    {crews.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="owner">Whose</Label>
                            <select id="owner" className={field} value={data.owner} onChange={(e) => setData('owner', e.target.value)}>
                                <option value="me">Just me</option>
                                {crews.map((crew) => (
                                    <option key={crew.id} value={String(crew.id)}>
                                        Shared with {crew.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <ColourSwatches colours={colours} value={data.colour} onChange={(colour) => setData('colour', colour)} label="Colour" />
                    <Button type="submit" disabled={processing}>
                        Add calendar
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
