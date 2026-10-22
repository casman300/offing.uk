import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import reminders from '@/routes/reminders';
import { router, usePage } from '@inertiajs/react';
import { BellRing, Share, SquarePlus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface PushDevice {
    id: number;
    name: string;
    endpoint_hash: string;
    added_at: string | null;
    last_push_at: string | null;
}

type DeviceState = 'checking' | 'unsupported' | 'ios-not-installed' | 'denied' | 'ready' | 'on';

const SW_URL = '/push-sw.js';

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);

    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

async function sha256Hex(text: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));

    return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}

const isIOS = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : null);

/**
 * "Notifications on this device" + the list of devices. iOS only allows push
 * from a Home Screen app and only asks permission on a tap, so this walks
 * through those steps rather than prompting on load.
 */
export default function PushDevices({ publicKey, devices }: { publicKey: string; devices: PushDevice[] }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [state, setState] = useState<DeviceState>('checking');
    const [thisHash, setThisHash] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        (async () => {
            if (isIOS() && !isStandalone()) {
                return setState('ios-not-installed');
            }
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                return setState('unsupported');
            }
            if (Notification.permission === 'denied') {
                return setState('denied');
            }

            const registration = await navigator.serviceWorker.register(SW_URL, { scope: '/' });
            const subscription = await registration.pushManager.getSubscription();
            const hash = subscription ? await sha256Hex(subscription.endpoint) : null;
            setThisHash(hash);
            setState(hash && devices.some((d) => d.endpoint_hash === hash) ? 'on' : 'ready');
        })().catch(() => setState('unsupported'));
    }, [devices]);

    const turnOn = async () => {
        setBusy(true);
        setError(null);

        try {
            // Must be the first thing in the tap handler: iOS only allows it from a user gesture.
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setState(permission === 'denied' ? 'denied' : 'ready');
                return;
            }

            const registration = await navigator.serviceWorker.register(SW_URL, { scope: '/' });
            await navigator.serviceWorker.ready;
            const subscription =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
                }));

            router.post(reminders.devices.store.url(), { subscription: subscription.toJSON() } as never, {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Couldn’t turn on notifications.');
            setBusy(false);
        }
    };

    return (
        <div className="space-y-4">
            <HeadingSmall title="Notifications on your phone" description="Get reminders as notifications. Email stays as the backup." />

            <div className="bg-card rounded-xl border p-4">
                {state === 'checking' && <p className="text-muted-foreground text-sm">Checking this device…</p>}

                {state === 'ios-not-installed' && (
                    <div className="space-y-3">
                        <p className="font-medium">First, add Offing to your Home Screen</p>
                        <p className="text-muted-foreground text-sm">iPhone only allows notifications from apps on the Home Screen.</p>
                        <ol className="space-y-2 text-sm">
                            <li className="flex items-center gap-2">
                                <Share className="size-4 shrink-0" /> Tap <strong>Share</strong> in Safari’s toolbar
                            </li>
                            <li className="flex items-center gap-2">
                                <SquarePlus className="size-4 shrink-0" /> Choose <strong>Add to Home Screen</strong>
                            </li>
                            <li className="flex items-center gap-2">
                                <BellRing className="size-4 shrink-0" /> Open Offing from the new icon and come back here
                            </li>
                        </ol>
                    </div>
                )}

                {state === 'unsupported' && (
                    <p className="text-muted-foreground text-sm">This browser can’t receive notifications. You’ll still get emails.</p>
                )}

                {state === 'denied' && (
                    <div className="space-y-1 text-sm">
                        <p className="font-medium">Notifications are blocked for Offing on this device</p>
                        <p className="text-muted-foreground">
                            On iPhone: Settings → Notifications → Offing → Allow Notifications. In a desktop browser, use the site settings next to
                            the address bar. Then reload this page.
                        </p>
                    </div>
                )}

                {state === 'ready' && (
                    <div className="space-y-2">
                        <Button onClick={turnOn} disabled={busy}>
                            <BellRing /> {busy ? 'Turning on…' : 'Turn on notifications on this device'}
                        </Button>
                        <p className="text-muted-foreground text-xs">Your device will ask for permission.</p>
                    </div>
                )}

                {state === 'on' && (
                    <p className="flex items-center gap-2 text-sm">
                        <BellRing className="text-primary size-4" /> Notifications are on for this device.
                    </p>
                )}

                <InputError className="mt-2" message={error ?? errors.subscription} />
            </div>

            {devices.length > 0 && (
                <ul className="divide-border divide-y rounded-xl border">
                    {devices.map((device) => (
                        <li key={device.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                            <div className="min-w-0">
                                <p className="font-medium">
                                    {device.name}
                                    {device.endpoint_hash === thisHash && <span className="text-muted-foreground font-normal"> · this device</span>}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    Added {fmt(device.added_at)}
                                    {device.last_push_at && <> · last notified {fmt(device.last_push_at)}</>}
                                </p>
                            </div>
                            <div className="flex gap-1">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => router.post(reminders.devices.test.url(device.id), {}, { preserveScroll: true })}
                                >
                                    Send a test notification
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Remove ${device.name}`}
                                    onClick={() => router.delete(reminders.devices.destroy.url(device.id), { preserveScroll: true })}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <InputError message={errors.device} />
        </div>
    );
}
