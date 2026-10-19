import EventForm from '@/components/event-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type EventFormValues } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

export default function EditEvent({
    event,
    timezone,
    timezones,
}: {
    event: EventFormValues & { id: number };
    timezone: string;
    timezones: string[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Calendar', href: '/calendar' },
        { title: event.title, href: `/calendar/events/${event.id}/edit` },
    ];

    const { id, ...initial } = event;
    const destroy = () => router.delete(route('calendar.events.destroy', id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${event.title}`} />

            <div className="mx-auto w-full max-w-xl space-y-2 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Edit event" />
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="ghost" aria-label={`Delete ${event.title}`}>
                                <Trash2 />
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete “{event.title}”?</DialogTitle>
                            <DialogDescription>You can undo this straight away, or restore it later from “Recently deleted”.</DialogDescription>
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

                <EventForm
                    key={id}
                    eventId={id}
                    initial={initial}
                    timezone={timezone}
                    timezones={timezones}
                    submitLabel="Save"
                    onCancel={() => router.visit(route('calendar.index'))}
                />
            </div>
        </AppLayout>
    );
}
