import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import PushDevices, { type PushDevice } from '@/components/push-devices';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import profile from '@/routes/profile';
import reminders from '@/routes/reminders';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Slot = { enabled: boolean; time: string };
type Delivery = 'email' | 'push' | 'both';

const DELIVERY_LABELS: Record<Delivery, string> = {
    push: 'Notifications, with email as backup',
    email: 'Email only',
    both: 'Notifications and email',
};
type SlotKey = 'day_before' | 'on_the_day';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reminder settings', href: '/settings/reminders' }];

const SLOT_LABELS: Record<SlotKey, string> = {
    day_before: 'The day before, at',
    on_the_day: 'On the day, at',
};

// 15-minute steps, matching the server's validation.
const TIMES = Array.from({ length: 96 }, (_, i) => `${String(Math.floor(i / 4)).padStart(2, '0')}:${String((i % 4) * 15).padStart(2, '0')}`);

const selectClass =
    'border-input bg-background ring-offset-background focus-visible:ring-ring h-10 rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-50 md:text-sm';

export default function ReminderSettings({
    enabled,
    timezone,
    slots,
    status,
    push,
}: {
    enabled: boolean;
    timezone: string;
    slots: Record<SlotKey, Slot>;
    status: string | null;
    /** Present only when this host can send push (VAPID keys set). */
    push: { publicKey: string; delivery: Delivery; devices: PushDevice[] } | null;
}) {
    const { errors: pageErrors } = usePage<{ errors: Record<string, string> }>().props;
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({ enabled, slots, delivery: push?.delivery ?? 'email' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(reminders.update.url(), { preserveScroll: true });
    };

    const setSlot = (key: SlotKey, patchSlot: Partial<Slot>) => setData('slots', { ...data.slots, [key]: { ...data.slots[key], ...patchSlot } });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reminder settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Birthday reminders" description="Reminders before and on the birthdays of the people you've added." />

                    <form onSubmit={submit} className="space-y-6">
                        <label className="flex items-start gap-3">
                            <Checkbox className="mt-0.5" checked={data.enabled} onCheckedChange={(v) => setData('enabled', v === true)} />
                            <span>
                                <span className="font-medium">Send me birthday reminders</span>
                                <span className="text-muted-foreground block text-sm">
                                    Off until you switch it on. You can turn it off from any reminder email too.
                                </span>
                            </span>
                        </label>

                        <fieldset className="space-y-3" disabled={!data.enabled}>
                            <legend className="sr-only">When</legend>
                            {(Object.keys(SLOT_LABELS) as SlotKey[]).map((key) => (
                                <div key={key} className="flex flex-wrap items-center gap-3">
                                    <Checkbox
                                        id={`slot-${key}`}
                                        checked={data.slots[key].enabled}
                                        onCheckedChange={(v) => setSlot(key, { enabled: v === true })}
                                        disabled={!data.enabled}
                                    />
                                    <Label htmlFor={`slot-${key}`} className={!data.enabled ? 'opacity-50' : undefined}>
                                        {SLOT_LABELS[key]}
                                    </Label>
                                    <select
                                        aria-label={`${SLOT_LABELS[key]} time`}
                                        className={selectClass}
                                        value={data.slots[key].time}
                                        disabled={!data.enabled || !data.slots[key].enabled}
                                        onChange={(e) => setSlot(key, { time: e.target.value })}
                                    >
                                        {TIMES.map((t) => (
                                            <option key={t} value={t}>
                                                {t}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={(errors as Record<string, string>)[`slots.${key}.time`]} />
                                </div>
                            ))}
                            <p className="text-muted-foreground text-sm">
                                Times are in your timezone ({timezone.replaceAll('_', ' ')}) —{' '}
                                <Link href={profile.edit.url()} className="underline">
                                    change it in Profile
                                </Link>
                                . Several birthdays at the same time come as one message.
                            </p>
                        </fieldset>

                        {push && (
                            <div className="grid gap-2">
                                <Label htmlFor="delivery" className={!data.enabled ? 'opacity-50' : undefined}>
                                    Deliver by
                                </Label>
                                <select
                                    id="delivery"
                                    className={selectClass}
                                    value={data.delivery}
                                    disabled={!data.enabled}
                                    onChange={(e) => setData('delivery', e.target.value as Delivery)}
                                >
                                    {(Object.keys(DELIVERY_LABELS) as Delivery[]).map((d) => (
                                        <option key={d} value={d}>
                                            {DELIVERY_LABELS[d]}
                                        </option>
                                    ))}
                                </select>
                                {data.delivery !== 'email' && push.devices.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        No devices yet — turn on notifications below, or reminders keep coming by email.
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>
                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-muted-foreground text-sm">Saved</p>
                            </Transition>
                        </div>
                    </form>

                    {push && (
                        <div className="border-t pt-6">
                            <PushDevices publicKey={push.publicKey} devices={push.devices} />
                            {status === 'device-test-sent' && (
                                <p className="text-muted-foreground mt-2 text-sm">Test sent — it should appear in a few seconds.</p>
                            )}
                        </div>
                    )}

                    <div className="space-y-2 border-t pt-6">
                        <HeadingSmall title="Check it arrives" description="Sends a sample reminder for your next few birthdays to your email now." />
                        <Button variant="outline" onClick={() => router.post(reminders.test.url(), {}, { preserveScroll: true })}>
                            Send me a test reminder
                        </Button>
                        {status === 'reminders-test-sent' && <p className="text-muted-foreground text-sm">Sent — check your inbox (and spam).</p>}
                        {status === 'reminders-test-empty' && <p className="text-muted-foreground text-sm">Add someone with a birthday first.</p>}
                        <InputError message={pageErrors.test} />
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
