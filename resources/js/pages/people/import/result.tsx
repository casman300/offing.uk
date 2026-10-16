import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, RotateCcw } from 'lucide-react';

interface ImportResult {
    id: number;
    filename: string | null;
    status: 'committed' | 'undone';
    counts: { created: number; updated: number; skipped: number } | null;
    undo: { removed: number; reverted: number; kept: string[] } | null;
}

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

export default function ImportResultPage({ import: result }: { import: ImportResult }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'People', href: '/people' },
        { title: 'Import contacts', href: '/people/import' },
    ];
    const counts = result.counts ?? { created: 0, updated: 0, skipped: 0 };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import finished" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4">
                {result.status === 'committed' ? (
                    <>
                        <Heading title="Import finished" description={result.filename ?? undefined} />
                        <div role="status" className="bg-card flex gap-3 rounded-xl border p-4">
                            <CheckCircle2 className="text-primary mt-0.5 size-5 shrink-0" />
                            <p>
                                Added {plural(counts.created, 'person', 'people')} and updated {counts.updated}.
                                {counts.skipped > 0 && <span className="text-muted-foreground"> {counts.skipped} skipped.</span>}
                            </p>
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <Button asChild>
                                <Link href={route('people.index')}>See people</Link>
                            </Button>
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="outline">
                                        <RotateCcw /> Undo this import
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>Undo this import?</DialogTitle>
                                    <DialogDescription>
                                        People it added are moved to Recently deleted, and birthdays it filled in are cleared. Anything you’ve changed
                                        since is left alone.
                                    </DialogDescription>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button variant="secondary">Cancel</Button>
                                        </DialogClose>
                                        <DialogClose asChild>
                                            <Button variant="destructive" onClick={() => router.post(route('people.import.undo', result.id))}>
                                                Undo import
                                            </Button>
                                        </DialogClose>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </>
                ) : (
                    <>
                        <Heading title="Import undone" description={result.filename ?? undefined} />
                        <div role="status" className="bg-card space-y-2 rounded-xl border p-4">
                            <p>
                                Removed {plural(result.undo?.removed ?? 0, 'person', 'people')} and reverted {result.undo?.reverted ?? 0}. Removed
                                people are in Recently deleted on the People page.
                            </p>
                            {(result.undo?.kept.length ?? 0) > 0 && (
                                <p className="text-muted-foreground text-sm">
                                    Kept because you’d changed them since: {result.undo!.kept.join(', ')}.
                                </p>
                            )}
                        </div>
                        <Button asChild>
                            <Link href={route('people.index')}>Back to people</Link>
                        </Button>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
