import AdminNav from '@/components/admin-nav';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Mail, Search, UserPlus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface FlagOption {
    name: string;
    description: string;
    stage_label: string;
}

interface UserRow {
    id: number;
    name: string;
    email: string;
    timezone: string | null;
    is_admin: boolean;
    created_at: string | null;
    last_login_at: string | null;
    passkeys: number;
    flags_on: string[];
}

interface Props {
    q: string;
    timezones: string[];
    defaultTimezone: string;
    flags: FlagOption[];
    users: UserRow[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Users', href: '/admin/users' },
];

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

export default function AdminUsers({ q, timezones, defaultTimezone, flags, users }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [query, setQuery] = useState(q);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        timezone: defaultTimezone,
        flags: [] as string[],
        send_link: true as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const toggleFlag = (name: string, on: boolean) => setData('flags', on ? [...data.flags, name] : data.flags.filter((flag) => flag !== name));

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.users.index'), query.trim() === '' ? {} : { q: query.trim() }, { preserveState: true, replace: true });
    };

    const sendLink = (user: UserRow) => router.post(route('admin.users.sign-in-link', { user: user.id }), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <AdminNav />
                <Heading
                    title="Users"
                    description="There's no public sign-up, so accounts start here (or with artisan user:create). Everything is audited."
                />

                {flash.status && (
                    <p role="status" className="bg-accent text-accent-foreground rounded-lg px-4 py-3 text-sm">
                        {flash.status}
                    </p>
                )}

                <section aria-labelledby="add-user" className="bg-card space-y-5 rounded-xl border p-4">
                    <h2 id="add-user" className="flex items-center gap-2 text-sm font-medium">
                        <UserPlus className="size-4" /> Add user
                    </h2>

                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    autoComplete="off"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="sam@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    autoComplete="off"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Defaults to the start of their email"
                                />
                                <InputError message={errors.name} />
                            </div>
                        </div>

                        <div className="grid gap-2 sm:max-w-sm">
                            <Label htmlFor="timezone">Timezone</Label>
                            <select
                                id="timezone"
                                className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm"
                                value={data.timezone}
                                onChange={(e) => setData('timezone', e.target.value)}
                            >
                                {timezones.map((tz) => (
                                    <option key={tz} value={tz}>
                                        {tz.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.timezone} />
                        </div>

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">Switch on for them</legend>
                            <p className="text-muted-foreground text-sm">
                                Features still at “Admins only” stay hidden from a new user unless you tick them here (or later on Feature flags).
                            </p>
                            {flags.map((flag) => (
                                <div key={flag.name} className="flex items-start gap-3">
                                    <Checkbox
                                        id={`flag-${flag.name}`}
                                        checked={data.flags.includes(flag.name)}
                                        onCheckedChange={(checked) => toggleFlag(flag.name, checked === true)}
                                        className="mt-0.5"
                                    />
                                    <Label htmlFor={`flag-${flag.name}`} className="grid gap-1 font-normal">
                                        <span>
                                            <code className="text-sm font-medium">{flag.name}</code>{' '}
                                            <span className="text-muted-foreground text-xs">· {flag.stage_label}</span>
                                        </span>
                                        <span className="text-muted-foreground text-sm">{flag.description}</span>
                                    </Label>
                                </div>
                            ))}
                            <InputError message={errors.flags} />
                        </fieldset>

                        <div className="flex items-center gap-3">
                            <Checkbox id="send_link" checked={data.send_link} onCheckedChange={(checked) => setData('send_link', checked === true)} />
                            <Label htmlFor="send_link" className="font-normal">
                                Email them a sign-in link now
                            </Label>
                        </div>

                        <Button disabled={processing}>Add user</Button>
                    </form>
                </section>

                <section className="space-y-3">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 className="text-sm font-medium">Everyone</h2>
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
                        <p className="text-muted-foreground py-6 text-center text-sm">{q ? `No users match “${q}”.` : 'No users yet.'}</p>
                    ) : (
                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {users.map((user) => (
                                <li key={user.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium break-all">{user.email}</span>
                                            {user.name && <span className="text-muted-foreground text-sm">{user.name}</span>}
                                            {user.is_admin && <Badge variant="outline">Admin</Badge>}
                                        </div>
                                        <p className="text-muted-foreground text-sm">
                                            {user.last_login_at ? `Last signed in ${fmtDate(user.last_login_at)}` : 'Never signed in'} · added{' '}
                                            {fmtDate(user.created_at)} · {user.passkeys} {user.passkeys === 1 ? 'passkey' : 'passkeys'}
                                            {user.timezone && <> · {user.timezone.replaceAll('_', ' ')}</>}
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {user.flags_on.length === 0 ? 'No features on' : <>On: {user.flags_on.join(', ')}</>} ·{' '}
                                            <Link
                                                href={route('admin.features.index', { q: user.email })}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                Manage flags
                                            </Link>
                                        </p>
                                    </div>
                                    <Button variant="outline" size="sm" className="shrink-0" onClick={() => sendLink(user)}>
                                        <Mail /> Send sign-in link
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {users.length === 50 && <p className="text-muted-foreground text-xs">Showing the newest 50 — search to narrow it down.</p>}
                </section>
            </div>
        </AppLayout>
    );
}
