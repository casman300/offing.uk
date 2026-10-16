import AdminNav from '@/components/admin-nav';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { RotateCcw, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface FeatureRow {
    name: string;
    description: string;
    stage: 'off' | 'admins' | 'percentage' | 'everyone';
    stage_label: string;
    percentage: number;
    on: number;
    off: number;
    unchecked: number;
}

interface UserFlag {
    /** null = not checked yet; the stage will decide. */
    stored: boolean | null;
    stage_default: boolean;
}

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    flags: Record<string, UserFlag>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Feature flags', href: '/admin/features' },
];

export default function AdminFeatures({ flags, users, q }: { flags: FeatureRow[]; users: UserRow[]; q: string }) {
    const { flash } = usePage<SharedData>().props;
    const [query, setQuery] = useState(q);

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.features.index'), query.trim() === '' ? {} : { q: query.trim() }, { preserveState: true, replace: true });
    };

    const set = (feature: string, user: number, value: 'on' | 'off' | 'default') =>
        router.post(route('admin.features.update', { feature, user }), { value }, { preserveScroll: true });

    const reset = (feature: string) => router.post(route('admin.features.reset', { feature }), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Feature flags" />

            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <AdminNav />
                <Heading
                    title="Feature flags"
                    description="Each flag's default comes from its rollout stage in code. Per-user overrides here win over the stage; every change is audited."
                />

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}

                <section className="space-y-3">
                    <h2 className="text-sm font-medium">Flags</h2>
                    <ul className="bg-card divide-border divide-y rounded-xl border">
                        {flags.map((feature) => (
                            <li key={feature.name} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="text-sm font-medium">{feature.name}</code>
                                        <Badge variant={feature.stage === 'everyone' ? 'default' : 'secondary'}>
                                            {feature.stage_label}
                                            {feature.stage === 'percentage' && ` · ${feature.percentage}%`}
                                        </Badge>
                                    </div>
                                    <p className="text-muted-foreground text-sm">{feature.description}</p>
                                    <p className="text-muted-foreground text-xs">
                                        On for {feature.on} · off for {feature.off} · not checked yet {feature.unchecked}
                                    </p>
                                </div>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" size="sm" className="shrink-0">
                                            <RotateCcw /> Reset to default
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>Reset {feature.name}?</DialogTitle>
                                        <DialogDescription>
                                            Clears every stored value for this flag, including per-user overrides. Each user then gets the current
                                            stage ({feature.stage_label}) the next time they load a page.
                                        </DialogDescription>
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button variant="secondary">Cancel</Button>
                                            </DialogClose>
                                            <DialogClose asChild>
                                                <Button variant="destructive" onClick={() => reset(feature.name)}>
                                                    Reset
                                                </Button>
                                            </DialogClose>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="space-y-3">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 className="text-sm font-medium">Users</h2>
                        <form onSubmit={search} className="relative sm:w-72">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                type="search"
                                aria-label="Search users by email or name"
                                placeholder="Search email or name"
                                className="pl-9"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </form>
                    </div>

                    {users.length === 0 ? (
                        <p className="text-muted-foreground py-6 text-center text-sm">No users match “{q}”.</p>
                    ) : (
                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {users.map((user) => (
                                <li key={user.id} className="space-y-3 px-4 py-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{user.email}</span>
                                        {user.name && <span className="text-muted-foreground text-sm">{user.name}</span>}
                                        {user.is_admin && <Badge variant="outline">Admin</Badge>}
                                    </div>
                                    {flags.map((feature) => (
                                        <FlagControl
                                            key={feature.name}
                                            feature={feature.name}
                                            flag={user.flags[feature.name]}
                                            onSet={(value) => set(feature.name, user.id, value)}
                                        />
                                    ))}
                                </li>
                            ))}
                        </ul>
                    )}
                    {users.length === 25 && <p className="text-muted-foreground text-xs">Showing the first 25 — search to narrow it down.</p>}
                </section>
            </div>
        </AppLayout>
    );
}

function FlagControl({ feature, flag, onSet }: { feature: string; flag: UserFlag; onSet: (value: 'on' | 'off' | 'default') => void }) {
    const effective = flag.stored ?? flag.stage_default;
    const overridden = flag.stored !== null && flag.stored !== flag.stage_default;

    const state =
        flag.stored === null
            ? `${effective ? 'On' : 'Off'} by default (not checked yet)`
            : overridden
              ? `${effective ? 'On' : 'Off'} — override`
              : `${effective ? 'On' : 'Off'} (default)`;

    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div className="text-sm">
                <code className="text-muted-foreground">{feature}</code>{' '}
                <span className={cn(effective ? 'text-primary' : 'text-muted-foreground', overridden && 'font-medium')}>{state}</span>
            </div>
            <div className="flex gap-1" role="group" aria-label={`${feature} for this user`}>
                <Button size="sm" variant={effective ? 'default' : 'outline'} aria-pressed={effective} onClick={() => onSet('on')}>
                    On
                </Button>
                <Button size="sm" variant={!effective ? 'default' : 'outline'} aria-pressed={!effective} onClick={() => onSet('off')}>
                    Off
                </Button>
                <Button size="sm" variant="ghost" disabled={flag.stored === null} onClick={() => onSet('default')}>
                    Default
                </Button>
            </div>
        </div>
    );
}
