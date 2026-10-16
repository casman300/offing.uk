import BarometerReadingForm from '@/components/barometer-reading-form';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Barometer', href: '/barometer' },
    { title: 'Take a reading', href: '/barometer/create' },
];

export default function CreateReading({ now }: { now: string }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Take a reading" />

            <div className="mx-auto w-full max-w-xl p-4">
                <Heading title="Take a reading" description="Only the time and a rating are needed — add the rest now or later." />
                <BarometerReadingForm now={now} submitLabel="Save reading" onCancel={() => router.visit(route('barometer.index'))} />
            </div>
        </AppLayout>
    );
}
