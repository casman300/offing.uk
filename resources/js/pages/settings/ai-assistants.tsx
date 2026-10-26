import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import aiAssistants from '@/routes/ai-assistants';

interface Level {
    value: string;
    label: string;
}

interface Connection {
    id: number;
    name: string;
    host: string | null;
    level: string;
    include_private: boolean;
    barometer: boolean;
    created_at: string;
    last_used_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AI assistants',
        href: '/settings/ai-assistants',
    },
];

const selectClass =
    'border-input bg-background ring-offset-background focus-visible:ring-ring h-10 rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-50 md:text-sm';

export default function AiAssistants({
    mcpUrl,
    levels,
    connections,
    barometerAvailable,
}: {
    mcpUrl: string;
    levels: Level[];
    connections: Connection[];
    barometerAvailable: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI assistants" />

            <SettingsLayout>
                <div className="space-y-8">
                    <div className="space-y-6">
                        <HeadingSmall
                            title="AI assistants"
                            description="Connect your own AI assistant to Offing, then ask it what’s coming up, whose birthday is next or what chores are due."
                        />

                        <CopyUrl url={mcpUrl} />

                        <ul className="space-y-2 text-sm">
                            <li>
                                <span className="font-medium">Claude (web, desktop and phone):</span> on the web or desktop, Settings → Connectors →
                                Add custom connector. Name it “Offing”, paste the address above, then Connect. It’s on your phone from then on.
                            </li>
                            <li>
                                <span className="font-medium">ChatGPT:</span> Settings → Apps & Connectors, then add a connector with the address
                                above. Depending on your plan, you may need to turn on developer mode first.
                            </li>
                            <li>
                                <span className="font-medium">Claude Code:</span>{' '}
                                <code className="bg-muted rounded px-1 py-0.5 text-xs">claude mcp add --transport http offing {mcpUrl}</code>, then{' '}
                                <code className="bg-muted rounded px-1 py-0.5 text-xs">/mcp</code> to sign in.
                            </li>
                            <li>
                                <span className="font-medium">Other assistants:</span> anything that supports remote MCP connectors with OAuth sign-in
                                should work. Use the address above.
                            </li>
                        </ul>

                        <p className="text-muted-foreground text-sm">
                            We test with Claude, but Offing uses the open MCP standard, so other assistants should work too.
                        </p>

                        <p className="text-muted-foreground text-sm">
                            When you connect, Offing asks what the assistant may do. It sees what you can see here. Anything it reads is sent to the
                            company that runs it, under their privacy policy.
                        </p>
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall
                            title="Connected apps"
                            description="Change what each one can do, or disconnect it. Changes apply straight away."
                        />

                        {connections.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nothing connected yet.</p>
                        ) : (
                            <ul className="bg-card divide-border divide-y rounded-xl border">
                                {connections.map((connection) => (
                                    <ConnectionRow
                                        key={connection.id}
                                        connection={connection}
                                        levels={levels}
                                        barometerAvailable={barometerAvailable}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function CopyUrl({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor="mcp-url">Connector address</Label>
            <div className="flex gap-2">
                <Input id="mcp-url" readOnly value={url} onFocus={(e) => e.currentTarget.select()} className="font-mono text-xs" />
                <Button type="button" variant="secondary" onClick={copy} aria-label="Copy connector address">
                    {copied ? <Check /> : <Copy />}
                </Button>
            </div>
        </div>
    );
}

function ConnectionRow({ connection, levels, barometerAvailable }: { connection: Connection; levels: Level[]; barometerAvailable: boolean }) {
    const { data, setData, patch, processing, isDirty } = useForm({
        level: connection.level,
        include_private: connection.include_private,
        barometer: connection.barometer,
    });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        patch(aiAssistants.update.url(connection.id), { preserveScroll: true });
    };

    const revoke = () => router.delete(aiAssistants.destroy.url(connection.id), { preserveScroll: true });

    return (
        <li className="space-y-3 px-4 py-3">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="truncate font-medium">
                        {connection.name}
                        {connection.host && <span className="text-muted-foreground font-normal"> · {connection.host}</span>}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {connection.last_used_at ? `Last used ${relativeTime(connection.last_used_at)}` : 'Not used yet'}
                        {' · '}connected {formatDate(connection.created_at)}
                    </p>
                </div>
                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="ghost" aria-label={`Disconnect ${connection.name}`}>
                            <Trash2 />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Disconnect “{connection.name}”?</DialogTitle>
                        <DialogDescription>It loses access straight away. You can connect it again whenever you like.</DialogDescription>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button variant="destructive" onClick={revoke}>
                                Disconnect
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>

            <form onSubmit={save} className="flex flex-wrap items-center gap-4">
                <select
                    aria-label={`What ${connection.name} can do`}
                    className={selectClass}
                    value={data.level}
                    onChange={(e) => setData('level', e.target.value)}
                >
                    {levels.map((level) => (
                        <option key={level.value} value={level.value}>
                            {level.label}
                        </option>
                    ))}
                </select>
                <div className="flex items-center space-x-2">
                    <Checkbox
                        id={`private-${connection.id}`}
                        checked={data.include_private}
                        onCheckedChange={(checked) => setData('include_private', checked === true)}
                    />
                    <Label htmlFor={`private-${connection.id}`}>Include private events</Label>
                </div>
                {barometerAvailable && (
                    <div className="flex items-center space-x-2">
                        <Checkbox
                            id={`barometer-${connection.id}`}
                            checked={data.barometer}
                            onCheckedChange={(checked) => setData('barometer', checked === true)}
                        />
                        <Label htmlFor={`barometer-${connection.id}`}>Barometer: add readings and see summary numbers</Label>
                    </div>
                )}
                {isDirty && (
                    <Button size="sm" disabled={processing}>
                        Save
                    </Button>
                )}
            </form>
        </li>
    );
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
