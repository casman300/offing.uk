import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, router } from '@inertiajs/react';

/**
 * Reached from the "Turn off reminders" link in an email — no sign-in. The
 * GET only shows this page; the button does the POST, so link scanners
 * can't switch reminders off.
 */
export default function Unsubscribe({ on, offUrl, onUrl }: { on: boolean; offUrl: string; onUrl: string }) {
    return (
        <AuthLayout
            title={on ? 'Turn off birthday reminders?' : 'Birthday reminders are off'}
            description={
                on ? 'You’ll stop getting reminder emails. Your people and birthdays stay as they are.' : 'You won’t get any more reminder emails.'
            }
        >
            <Head title="Birthday reminders" />
            <div className="flex flex-col gap-3">
                {on ? (
                    <Button onClick={() => router.post(offUrl, {}, { preserveScroll: true })}>Turn off reminders</Button>
                ) : (
                    <Button variant="outline" onClick={() => router.post(onUrl, {}, { preserveScroll: true })}>
                        Turn them back on
                    </Button>
                )}
            </div>
        </AuthLayout>
    );
}
