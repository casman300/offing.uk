import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppearanceTabs from '@/components/appearance-tabs';
import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import emailChange from '@/routes/email-change';
import profile from '@/routes/profile';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Account',
        href: '/settings/profile',
    },
];

interface ProfileProps {
    timezones: string[];
    /** A new address waiting for its confirm link to be clicked. */
    pendingEmail: string | null;
    status?: string;
}

const STATUS_MESSAGES: Record<string, string> = {
    'email-change-sent': 'Check your new inbox — your email changes once you click the link we’ve sent.',
    'email-change-cancelled': 'Email change cancelled.',
};

export default function Profile({ timezones, pendingEmail, status }: ProfileProps) {
    const { auth, errors: pageErrors } = usePage<SharedData & { errors: Record<string, string | undefined> }>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful, reset } = useForm({
        name: auth.user.name,
        email: auth.user.email,
        timezone: auth.user.timezone,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // The email field goes back to the current address: a new one only
        // takes effect from its confirm link.
        patch(profile.update.url(), { onSuccess: () => reset('email') });
    };

    const cancelEmailChange = () => router.post(emailChange.cancel.url(), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Account" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Profile information" description="Update your name and email address" />

                    {status && (
                        <p className="text-sm font-medium text-green-600" role="status">
                            {STATUS_MESSAGES[status] ?? status}
                        </p>
                    )}

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder="Email address"
                            />

                            {pendingEmail && (
                                <p className="text-muted-foreground text-sm">
                                    Waiting for you to confirm <span className="text-foreground font-medium">{pendingEmail}</span> — check that inbox.{' '}
                                    <button type="button" onClick={cancelEmailChange} className="text-foreground underline underline-offset-4">
                                        Cancel
                                    </button>
                                </p>
                            )}

                            {/* Also an email-change link's error, flashed by a redirect here. */}
                            <InputError className="mt-2" message={errors.email ?? pageErrors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="timezone">Timezone</Label>

                            <select
                                id="timezone"
                                className="border-input bg-background ring-offset-background focus-visible:ring-ring mt-1 flex h-10 w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm"
                                value={data.timezone}
                                onChange={(e) => setData('timezone', e.target.value)}
                                aria-describedby="timezone_hint"
                            >
                                {timezones.map((tz) => (
                                    <option key={tz} value={tz}>
                                        {tz.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>

                            <p id="timezone_hint" className="text-muted-foreground text-sm">
                                Decides when “today” is — for example whether someone’s birthday has happened yet.
                            </p>

                            <InputError className="mt-2" message={errors.timezone} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <div className="space-y-6">
                    <HeadingSmall title="Appearance" description="Light, dark, or follow this device. Saved in this browser." />
                    <AppearanceTabs />
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
