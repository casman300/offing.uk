import BarometerReadingForm from '@/components/barometer-reading-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import barometer from '@/routes/barometer';
import { type BarometerReading, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

export default function EditReading({ reading, today, now }: { reading: BarometerReading; today: string; now: string }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Barometer', href: '/barometer' },
        { title: 'Edit reading', href: `/barometer/${reading.id}/edit` },
    ];

    const destroy = () => router.delete(barometer.destroy.url(reading.id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit reading" />

            <div className="mx-auto w-full max-w-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Edit reading" />
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="ghost" aria-label="Delete this reading">
                                <Trash2 />
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete this reading?</DialogTitle>
                            <DialogDescription>It’s deleted for good — there’s no undo.</DialogDescription>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button variant="destructive" onClick={destroy}>
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
                <BarometerReadingForm
                    reading={reading}
                    today={today}
                    now={now}
                    submitLabel="Save"
                    onCancel={() => router.visit(barometer.index.url())}
                />
            </div>
        </AppLayout>
    );
}
