import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import people from '@/routes/people';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'People', href: '/people' },
    { title: 'Import contacts', href: '/people/import' },
];

export default function ImportContacts({ maxCards }: { maxCards: number }) {
    const { setData, post, processing, errors, progress } = useForm<{ file: File | null }>({ file: null });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(people.import.store.url(), { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import contacts" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4">
                <Heading
                    title="Import contacts"
                    description="Bring in birthdays from your phone. You’ll review everything before anything is saved."
                />

                <section className="bg-card space-y-3 rounded-xl border p-4">
                    <h2 className="font-medium">On an iPhone</h2>
                    <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                        <li>
                            Open <strong className="text-foreground">Contacts</strong> and tap <strong className="text-foreground">Lists</strong> (top
                            left).
                        </li>
                        <li>
                            Touch and hold <strong className="text-foreground">All Contacts</strong>, then tap{' '}
                            <strong className="text-foreground">Export</strong>.
                        </li>
                        <li>Save the .vcf file to Files, then choose it below.</li>
                    </ol>
                    <p className="text-muted-foreground text-sm">On Android or a computer, export your contacts as a vCard (.vcf) file.</p>
                </section>

                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="file">Contacts file</Label>
                        <input
                            id="file"
                            type="file"
                            accept=".vcf,.vcard,text/vcard,text/x-vcard"
                            required
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            className="file:bg-secondary file:text-secondary-foreground block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-2 file:text-sm file:font-medium"
                            aria-describedby="file_hint"
                        />
                        <p id="file_hint" className="text-muted-foreground text-xs">
                            Up to 10 MB and {maxCards.toLocaleString()} contacts. We only keep names and birthdays (and notes, if you choose) — never
                            phone numbers, emails, addresses or photos.
                        </p>
                        <InputError message={errors.file} />
                    </div>

                    {progress && (
                        <progress value={progress.percentage} max="100" className="w-full">
                            {progress.percentage}%
                        </progress>
                    )}

                    <div className="flex items-center gap-3">
                        <Button disabled={processing}>{processing ? 'Reading…' : 'Continue'}</Button>
                        <Button asChild variant="ghost">
                            <Link href={people.index.url()}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
