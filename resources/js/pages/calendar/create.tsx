import EventForm from '@/components/event-form';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Calendar', href: '/calendar' },
    { title: 'Add event', href: '/calendar/events/create' },
];

export default function CreateEvent({ timezone, timezones }: { timezone: string; timezones: string[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add event" />

            <div className="mx-auto w-full max-w-xl p-4">
                <Heading title="Add event" />
                <EventForm timezone={timezone} timezones={timezones} submitLabel="Add event" onCancel={() => router.visit(route('calendar.index'))} />
            </div>
        </AppLayout>
    );
}
