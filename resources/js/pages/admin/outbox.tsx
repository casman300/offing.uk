import AdminNav from '@/components/admin-nav';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Message {
    id: number;
    user: string | null;
    channel: string;
    slot: string | null;
    birthday: string | null;
    send_at: string;
    status: 'pending' | 'sent' | 'skipped' | 'failed';
    attempts: number;
    next_attempt_at: string | null;
    sent_at: string | null;
    last_error: string | null;
}

const STATUSES = ['pending', 'sent', 'skipped', 'failed'] as const;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Outbox', href: '/admin/outbox' },
];

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export default function AdminOutbox({ status, counts, messages }: { status: string | null; counts: Record<string, number>; messages: Message[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Outbox" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <AdminNav />
                <Heading
                    title="Outbox"
                    description="Reminders the scanner has queued and what happened to them. Shows birthday dates, never people's names."
                />

                <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                    <FilterLink active={status === null} href="/admin/outbox" label={`All (${Object.values(counts).reduce((a, b) => a + b, 0)})`} />
                    {STATUSES.map((s) => (
                        <FilterLink
                            key={s}
                            active={status === s}
                            href={`/admin/outbox?status=${s}`}
                            label={`${s[0].toUpperCase()}${s.slice(1)} (${counts[s] ?? 0})`}
                        />
                    ))}
                </div>

                {messages.length === 0 ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">Nothing here yet.</p>
                ) : (
                    <ul className="bg-card divide-border divide-y rounded-xl border">
                        {messages.map((m) => (
                            <li key={m.id} className="space-y-1 px-4 py-3 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={m.status === 'failed' ? 'destructive' : m.status === 'sent' ? 'default' : 'secondary'}>
                                        {m.status}
                                    </Badge>
                                    <span className="font-medium">{m.user ?? 'deleted user'}</span>
                                    <span className="text-muted-foreground">
                                        {m.slot === 'day_before' ? 'day before' : m.slot === 'on_the_day' ? 'on the day' : m.slot} · birthday{' '}
                                        {m.birthday} · {m.channel}
                                    </span>
                                </div>
                                <p className="text-muted-foreground">
                                    Due {fmt(m.send_at)}
                                    {m.sent_at && <> · sent {fmt(m.sent_at)}</>}
                                    {m.attempts > 0 && (
                                        <>
                                            {' '}
                                            · {m.attempts} {m.attempts === 1 ? 'attempt' : 'attempts'}
                                        </>
                                    )}
                                    {m.status === 'pending' && m.next_attempt_at && <> · next try {fmt(m.next_attempt_at)}</>}
                                </p>
                                {m.last_error && (
                                    <p className={cn('text-xs', m.status === 'failed' ? 'text-destructive' : 'text-muted-foreground')}>
                                        {m.last_error}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}

function FilterLink({ active, href, label }: { active: boolean; href: string; label: string }) {
    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                'rounded-md border px-3 py-1.5 text-sm',
                active ? 'bg-accent text-accent-foreground border-transparent' : 'text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
        </Link>
    );
}
