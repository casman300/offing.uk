import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

import ColourSwatches from '@/components/colour-swatches';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import subscriptions from '@/routes/subscriptions';

interface Subscription {
    id: number;
    name: string;
    colour: string;
    /** Only the host is ever sent back: the full address is often a secret. */
    host: string;
    status: 'pending' | 'ok' | 'error';
    last_error: string | null;
    event_count: number;
    last_fetched_at: string | null;
    last_changed_at: string | null;
    next_fetch_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Subscriptions', href: '/settings/subscriptions' }];

export default function Subscriptions({ subscriptions: items, colours, max }: { subscriptions: Subscription[]; colours: string[]; max: number }) {
    const { flash } = usePage<SharedData>().props;
    const atLimit = items.length >= max;
    const { data, setData, post, processing, errors, reset } = useForm({ url: '', name: '', colour: colours[1] ?? colours[0] });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(subscriptions.store.url(), { preserveScroll: true, onSuccess: () => reset('url', 'name') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Subscriptions" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Subscribed calendars"
                        description="Show another calendar — work, school, a club — alongside yours. Only you see it, and it stays read-only: change things where it comes from."
                    />

                    {flash.status && (
                        <div role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                            {flash.status}
                        </div>
                    )}

                    {items.length > 0 && (
                        <ul className="space-y-3">
                            {items.map((item) => (
                                <SubscriptionRow key={item.id} item={item} colours={colours} />
                            ))}
                        </ul>
                    )}

                    <form onSubmit={submit} className="space-y-4 rounded-xl border border-dashed p-4">
                        <h2 className="font-semibold">Subscribe to a calendar</h2>
                        <div className="grid gap-2">
                            <Label htmlFor="sub-url">Calendar address (iCal / .ics)</Label>
                            <Input
                                id="sub-url"
                                type="text"
                                inputMode="url"
                                autoComplete="off"
                                spellCheck={false}
                                value={data.url}
                                onChange={(e) => setData('url', e.target.value)}
                                placeholder="https://… or webcal://…"
                                maxLength={2000}
                                required
                                disabled={atLimit}
                            />
                            <InputError message={errors.url} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sub-name">Name</Label>
                            <Input
                                id="sub-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                maxLength={60}
                                required
                                disabled={atLimit}
                                placeholder="e.g. Work"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Colour</Label>
                            <ColourSwatches colours={colours} value={data.colour} onChange={(colour) => setData('colour', colour)} label="Colour" />
                        </div>
                        <Button disabled={processing || atLimit}>{processing ? 'Fetching…' : 'Subscribe'}</Button>
                        {atLimit && (
                            <p className="text-muted-foreground text-sm">You have {max} subscriptions, the most allowed. Remove one first.</p>
                        )}
                    </form>

                    <div className="text-muted-foreground space-y-2 text-sm">
                        <p className="text-foreground font-medium">Where to find the address</p>
                        <ul className="list-disc space-y-1 pl-5">
                            <li>
                                <strong>Google Calendar</strong>: on a computer, open Settings, pick the calendar under “Settings for my calendars”,
                                then <em>Integrate calendar → Secret address in iCal format</em>.
                            </li>
                            <li>
                                <strong>Outlook</strong>: Settings → Calendar → Shared calendars → <em>Publish a calendar</em>, then copy the ICS
                                link.
                            </li>
                            <li>
                                <strong>Apple iCloud</strong>: share the calendar as a <em>Public Calendar</em> and copy its link.
                            </li>
                            <li>Clubs and schools (TeamSnap, Spond…) usually have a “subscribe” or “export to calendar” link.</li>
                        </ul>
                        <p>
                            Treat a secret address like a password. Offing stores it encrypted and only ever shows its website, and fetches it about
                            once an hour.
                        </p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function SubscriptionRow({ item, colours }: { item: Subscription; colours: string[] }) {
    const save = (patch: Partial<Pick<Subscription, 'name' | 'colour'>>) =>
        router.patch(subscriptions.update.url(item.id), { name: item.name, colour: item.colour, ...patch }, { preserveScroll: true });
    const refresh = () => router.post(subscriptions.refresh.url(item.id), {}, { preserveScroll: true });
    const remove = () => router.delete(subscriptions.destroy.url(item.id), { preserveScroll: true });

    return (
        <li className="bg-card space-y-3 rounded-xl border p-4">
            <div className="flex items-center gap-3">
                <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: item.colour }} />
                <Input
                    aria-label={`Name of ${item.name}`}
                    defaultValue={item.name}
                    maxLength={60}
                    onBlur={(e) => e.target.value.trim() && e.target.value !== item.name && save({ name: e.target.value })}
                />
            </div>
            <p className="text-muted-foreground text-sm">
                From <span className="text-foreground font-mono">{item.host}</span> ·{' '}
                {item.event_count === 1 ? '1 event' : `${item.event_count} events`}
                {item.last_fetched_at && ` · checked ${relativeTime(item.last_fetched_at)}`}
            </p>
            <Status item={item} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <ColourSwatches colours={colours} value={item.colour} onChange={(colour) => save({ colour })} label={`Colour for ${item.name}`} />
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={refresh}>
                        <RefreshCw /> Refresh now
                    </Button>
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="outline" size="sm" aria-label={`Remove ${item.name}`}>
                                <Trash2 /> Remove
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Remove “{item.name}”?</DialogTitle>
                            <DialogDescription>
                                Its events disappear from your Offing calendar. Nothing changes where it comes from, and you can subscribe again
                                later.
                            </DialogDescription>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button variant="destructive" onClick={remove}>
                                    Remove
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </li>
    );
}

function Status({ item }: { item: Subscription }) {
    if (item.status === 'error') {
        return (
            <div role="alert" className="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border px-3 py-2 text-sm">
                <p className="font-medium">Couldn’t fetch it{item.next_fetch_at ? ` — trying again ${relativeTime(item.next_fetch_at)}` : ''}.</p>
                {item.last_error && <p>{item.last_error}</p>}
            </div>
        );
    }

    if (item.status === 'pending') {
        return <p className="text-muted-foreground text-sm">Waiting for its first fetch.</p>;
    }

    return item.last_error ? <p className="text-sm text-amber-700 dark:text-amber-400">{item.last_error}</p> : null;
}

function relativeTime(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }

    return seconds >= 0 ? 'in a moment' : 'just now';
}
