import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, router } from '@inertiajs/react';

/**
 * Reached from "Turn off check-ins" in a check-in email (#36) — no sign-in.
 * The GET only shows this page; the button does the POST, so link scanners
 * can't switch check-ins off. Worded so it says nothing sensitive to
 * whoever is looking at the screen.
 */
export default function CheckIns({ on, offUrl, onUrl }: { on: boolean; offUrl: string; onUrl: string }) {
    return (
        <AuthLayout
            title={on ? 'Turn off check-ins?' : 'Check-ins are off'}
            description={
                on
                    ? 'You’ll stop getting a “time for a quick check-in” message after a reading. Everything you’ve logged stays as it is.'
                    : 'You won’t get any more check-in messages. You can turn them back on from the Barometer page.'
            }
        >
            <Head title="Check-ins" />
            <div className="flex flex-col gap-3">
                {on ? (
                    <Button onClick={() => router.post(offUrl, {}, { preserveScroll: true })}>Turn off check-ins</Button>
                ) : (
                    <Button variant="outline" onClick={() => router.post(onUrl, {}, { preserveScroll: true })}>
                        Turn them back on
                    </Button>
                )}
            </div>
        </AuthLayout>
    );
}
