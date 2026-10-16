import Heading from '@/components/heading';
import PersonForm from '@/components/person-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'People', href: '/people' },
    { title: 'Add person', href: '/people/create' },
];

export default function CreatePerson({ today }: { today: string }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add person" />

            <div className="mx-auto w-full max-w-xl p-4">
                <Heading title="Add person" description="Only a name is needed — add a birthday now or later." />
                <PersonForm today={today} submitLabel="Add person" onCancel={() => router.visit(route('people.index'))} />
            </div>
        </AppLayout>
    );
}
