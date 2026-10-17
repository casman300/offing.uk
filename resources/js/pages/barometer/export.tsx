import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BarometerColumn, type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { FileDown, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Barometer', href: '/barometer' },
    { title: 'Export', href: '/barometer/export' },
];

interface Props {
    defaults: { from: string; to: string };
    columns: Record<BarometerColumn, string>;
    omittable: BarometerColumn[];
}

/**
 * Export options (#35). The buttons are plain links, not Inertia visits:
 * they're file downloads. If the server rejects the options it redirects
 * back here with the errors.
 */
export default function BarometerExport({ defaults, columns, omittable }: Props) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [from, setFrom] = useState(defaults.from);
    const [to, setTo] = useState(defaults.to);
    const [omit, setOmit] = useState<BarometerColumn[]>([]);

    const href = (format: 'pdf' | 'csv') => route('barometer.export.download', { format, from, to, ...(omit.length > 0 ? { omit } : {}) });
    const toggle = (column: BarometerColumn, included: boolean) =>
        setOmit((current) => (included ? current.filter((c) => c !== column) : [...current, column]));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Export readings" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4">
                <Heading
                    title="Export readings"
                    description="For your therapist: a printable PDF laid out like the paper form, or a CSV for a spreadsheet."
                />

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Dates</legend>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="export-from" className="text-muted-foreground text-xs">
                                From
                            </Label>
                            <Input id="export-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="export-to" className="text-muted-foreground text-xs">
                                To
                            </Label>
                            <Input id="export-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">Up to a year at a time.</p>
                    <InputError message={errors.from ?? errors.to ?? errors.format ?? errors.omit} />
                </fieldset>

                <fieldset>
                    <legend className="mb-2 text-sm font-medium">Include</legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {omittable.map((column) => (
                            <div key={column} className="flex items-center gap-2">
                                <Checkbox
                                    id={`include-${column}`}
                                    checked={!omit.includes(column)}
                                    onCheckedChange={(checked) => toggle(column, checked === true)}
                                />
                                <Label htmlFor={`include-${column}`} className="font-normal">
                                    {columns[column]}
                                </Label>
                            </div>
                        ))}
                    </div>
                    <p className="text-muted-foreground mt-2 text-xs">The day, time and first rating are always included.</p>
                </fieldset>

                <div className="flex flex-wrap gap-2">
                    <Button asChild>
                        <a href={href('pdf')}>
                            <FileDown /> Download PDF
                        </a>
                    </Button>
                    <Button asChild variant="outline">
                        <a href={href('csv')}>
                            <FileSpreadsheet /> Download CSV
                        </a>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
