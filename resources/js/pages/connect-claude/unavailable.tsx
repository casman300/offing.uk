import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard } from '@/routes';

export default function Unavailable() {
    return (
        <AuthLayout title="Connecting AI assistants isn’t on for you yet" description="It’s still being tried out. Nothing has been connected.">
            <Head title="AI assistants" />
            <Button asChild variant="outline" className="w-full">
                <Link href={dashboard.url()}>Back to Offing</Link>
            </Button>
        </AuthLayout>
    );
}
