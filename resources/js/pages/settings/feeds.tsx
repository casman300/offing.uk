import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import feeds from '@/routes/feeds';

interface FeedToken {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
    last_user_agent: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Calendar feeds',
        href: '/settings/feeds',
    },
];

export default function Feeds({ tokens, maxActive }: { tokens: FeedToken[]; maxActive: number }) {
    const { flash } = usePage<SharedData>().props;
    const atLimit = tokens.length >= maxActive;

    const { data, setData, post, errors, processing, reset } = useForm({ name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(feeds.store.url(), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar feeds" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Calendar feeds"
                        description="Subscribe to a private link in Apple Calendar, Google Calendar or Outlook to see your Offing birthdays there, with reminders."
                    />

                    {flash.feedUrl && <NewFeedUrl url={flash.feedUrl.url} />}

                    {tokens.length > 0 && (
                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {tokens.map((token) => (
                                <TokenRow key={token.id} token={token} />
                            ))}
                        </ul>
                    )}

                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid gap-2">
                            <Label htmlFor="feed-name">New feed link</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="feed-name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    maxLength={50}
                                    required
                                    disabled={atLimit}
                                    placeholder="e.g. My iPhone"
                                />
                                <Button disabled={processing || atLimit}>Create link</Button>
                            </div>
                            <InputError message={errors.name} />
                            <p className="text-muted-foreground text-sm">
                                {atLimit
                                    ? `You have ${maxActive} links, the most allowed. Revoke one to make another.`
                                    : `Give each device or account its own link (up to ${maxActive}), so you can revoke one without breaking the others.`}
                            </p>
                        </div>
                    </form>

                    <p className="text-muted-foreground text-sm">
                        Anyone with a link can see every birthday in it, so treat it like a password. Links are shown once. If you lose one, revoke it
                        and make a new one.
                    </p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function NewFeedUrl({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);
    const webcal = url.replace(/^https?:/, 'webcal:');

    const copy = async () => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
    };

    return (
        <div role="status" className="border-primary/40 bg-primary/5 space-y-3 rounded-xl border p-4">
            <p className="font-medium">Copy this link now. It won’t be shown again.</p>
            <div className="flex gap-2">
                <Input readOnly value={url} onFocus={(e) => e.currentTarget.select()} aria-label="Feed link" className="font-mono text-xs" />
                <Button type="button" variant="secondary" onClick={copy} aria-label="Copy feed link">
                    {copied ? <Check /> : <Copy />}
                </Button>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline">
                    <a href={webcal}>Add to Apple Calendar</a>
                </Button>
                <Button asChild size="sm" variant="outline">
                    <a href={`https://calendar.google.com/calendar/r?cid=${encodeURIComponent(webcal)}`} target="_blank" rel="noreferrer noopener">
                        Add to Google Calendar
                    </a>
                </Button>
            </div>
        </div>
    );
}

function TokenRow({ token }: { token: FeedToken }) {
    const revoke = () => router.delete(feeds.destroy.url(token.id), { preserveScroll: true });

    return (
        <li className="flex items-center justify-between gap-4 px-4 py-3">
            <div className="min-w-0">
                <p className="truncate font-medium">{token.name}</p>
                <p className="text-muted-foreground text-sm">
                    {token.last_used_at ? `Last fetched ${relativeTime(token.last_used_at)}${clientName(token.last_user_agent)}` : 'Not fetched yet'}
                    {' · '}created {formatDate(token.created_at)}
                </p>
            </div>
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="ghost" aria-label={`Revoke ${token.name}`}>
                        <Trash2 />
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Revoke “{token.name}”?</DialogTitle>
                    <DialogDescription>
                        Any calendar subscribed to this link stops updating straight away. This can’t be undone, but you can make a new link.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={revoke}>
                            Revoke
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </li>
    );
}

/** A friendly name for the calendar app that last polled, from its User-Agent. */
function clientName(userAgent: string | null): string {
    if (!userAgent) return '';
    if (/google/i.test(userAgent)) return ' by Google Calendar';
    if (/microsoft|outlook/i.test(userAgent)) return ' by Outlook';
    if (/dataaccessd|CalendarAgent|iOS|Mac ?OS|CFNetwork/i.test(userAgent)) return ' by Apple Calendar';
    return '';
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

    return 'just now';
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
