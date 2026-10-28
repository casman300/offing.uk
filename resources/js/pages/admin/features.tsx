import AdminNav from '@/components/admin-nav';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { RotateCcw, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Stage = 'off' | 'admins' | 'beta' | 'percentage' | 'everyone';

interface StageOption {
    value: Stage;
    label: string;
}

interface FeatureRow {
    name: string;
    description: string;
    stage: Stage;
    stage_label: string;
    percentage: number;
    /** true = an admin chose the stage; false = it's the one declared in code. */
    stage_overridden: boolean;
    declared_stage_label: string;
    declared_percentage: number;
    overrides_on: number;
    overrides_off: number;
    /** Users it's on for right now, overrides included. */
    enabled: number;
}

interface UserFlag {
    /** null = no override; the stage decides. */
    stored: boolean | null;
    stage_default: boolean;
}

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_beta_tester: boolean;
    flags: Record<string, UserFlag>;
}

const selectClass =
    'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 rounded-md border px-3 py-1 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Feature flags', href: '/admin/features' },
];

interface Props {
    flags: FeatureRow[];
    users: UserRow[];
    q: string;
    stages: StageOption[];
    userCount: number;
}

export default function AdminFeatures({ flags, users, q, stages, userCount }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [query, setQuery] = useState(q);

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(admin.features.index.url(), query.trim() === '' ? {} : { q: query.trim() }, { preserveState: true, replace: true });
    };

    const set = (feature: string, user: number, value: 'on' | 'off' | 'default') =>
        router.post(admin.features.update.url({ feature, user }), { value }, { preserveScroll: true });

    const reset = (feature: string) => router.post(admin.features.reset.url({ feature }), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Feature flags" />

            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <AdminNav />
                <Heading
                    title="Feature flags"
                    description="Each flag's stage decides who gets it: admins, beta testers (ticked on Users), a percentage or everyone. Per-user overrides win over the stage. Every change is audited and takes effect on the user's next page load."
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
                                        On for {feature.enabled} of {userCount} {userCount === 1 ? 'user' : 'users'}
                                        {(feature.overrides_on > 0 || feature.overrides_off > 0) &&
                                            ` · overrides: ${feature.overrides_on} on, ${feature.overrides_off} off`}
                                        {feature.stage_overridden &&
                                            ` · code default: ${feature.declared_stage_label}${feature.declared_percentage > 0 ? ` (${feature.declared_percentage}%)` : ''}`}
                                    </p>
                                </div>
                                <div className="flex shrink-0 flex-col gap-2 sm:items-end">
                                    <StageControl key={`${feature.stage}-${feature.percentage}`} feature={feature} stages={stages} />
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={feature.overrides_on + feature.overrides_off === 0}
                                                className="self-start sm:self-end"
                                            >
                                                <RotateCcw /> Clear overrides
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogTitle>Clear overrides for {feature.name}?</DialogTitle>
                                            <DialogDescription>
                                                Removes every per-user override for this flag ({feature.overrides_on} on, {feature.overrides_off}{' '}
                                                off). Everyone then follows its stage ({feature.stage_label}).
                                            </DialogDescription>
                                            <DialogFooter>
                                                <DialogClose asChild>
                                                    <Button variant="secondary">Cancel</Button>
                                                </DialogClose>
                                                <DialogClose asChild>
                                                    <Button variant="destructive" onClick={() => reset(feature.name)}>
                                                        Clear overrides
                                                    </Button>
                                                </DialogClose>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                </div>
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
                                        {user.is_beta_tester && <Badge variant="outline">Beta tester</Badge>}
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

function StageControl({ feature, stages }: { feature: FeatureRow; stages: StageOption[] }) {
    const [stage, setStage] = useState<Stage>(feature.stage);
    const [percentage, setPercentage] = useState(String(feature.percentage));
    const [saving, setSaving] = useState(false);

    const dirty = stage !== feature.stage || (stage === 'percentage' && Number(percentage) !== feature.percentage);
    const valid = stage !== 'percentage' || (percentage !== '' && Number(percentage) >= 0 && Number(percentage) <= 100);

    const save = (value: Stage | 'default') =>
        router.post(
            admin.features.stage.url({ feature: feature.name }),
            { stage: value, percentage: value === 'percentage' ? Number(percentage) : null },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );

    return (
        <div className="flex flex-wrap items-center gap-2">
            <select
                aria-label={`Rollout stage for ${feature.name}`}
                className={selectClass}
                value={stage}
                onChange={(e) => setStage(e.target.value as Stage)}
            >
                {stages.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {stage === 'percentage' && (
                <div className="flex items-center gap-1">
                    <Input
                        type="number"
                        min={0}
                        max={100}
                        aria-label={`Percentage of users for ${feature.name}`}
                        className="h-9 w-20"
                        value={percentage}
                        onChange={(e) => setPercentage(e.target.value)}
                    />
                    <span className="text-muted-foreground text-sm">%</span>
                </div>
            )}
            <Button size="sm" disabled={!dirty || !valid || saving} onClick={() => save(stage)}>
                Save
            </Button>
            {feature.stage_overridden && !dirty && (
                <Button size="sm" variant="ghost" disabled={saving} onClick={() => save('default')}>
                    Use code default
                </Button>
            )}
        </div>
    );
}

function FlagControl({ feature, flag, onSet }: { feature: string; flag: UserFlag; onSet: (value: 'on' | 'off' | 'default') => void }) {
    const effective = flag.stored ?? flag.stage_default;
    const overridden = flag.stored !== null && flag.stored !== flag.stage_default;

    const state =
        flag.stored === null
            ? `${effective ? 'On' : 'Off'} (stage)`
            : overridden
              ? `${effective ? 'On' : 'Off'} — override`
              : `${effective ? 'On' : 'Off'} — override, same as stage`;

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
