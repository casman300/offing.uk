import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatBirthday } from '@/lib/birthday';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface ImportBirthday {
    day: number;
    month: number;
    year: number | null;
}

interface ReviewRow {
    key: string;
    name: string;
    birthdays: ImportBirthday[];
    has_notes: boolean;
    warnings: string[];
    cards: number;
    section: Section;
    match: {
        id: number;
        name: string;
        birth_day: number | null;
        birth_month: number | null;
        birth_year: number | null;
        fills: string[];
    } | null;
    default_include: boolean;
}

type Section = 'new' | 'match' | 'conflict' | 'duplicates' | 'no_birthday';

// A type alias (not an interface) so it satisfies Inertia's FormDataConvertible.
type Choice = {
    include: boolean;
    name: string;
    action: 'add' | 'update';
    birthday: number;
    use_vcard: boolean;
};

const SECTIONS: { id: Section; title: string; hint: string }[] = [
    { id: 'new', title: 'New, with a birthday', hint: 'Not in Offing yet.' },
    { id: 'match', title: 'Already in Offing', hint: 'Only blanks are filled in — names and notes you’ve written are never changed.' },
    { id: 'conflict', title: 'Different birthday', hint: 'Offing’s date is kept unless you tick to use the contact’s date.' },
    { id: 'duplicates', title: 'Duplicates in the file', hint: 'Several contacts with this name disagree on the birthday — pick one.' },
    { id: 'no_birthday', title: 'No birthday', hint: 'Not ticked by default. Tick to add them anyway.' },
];

const WARNINGS: Record<string, string> = {
    year_ignored: 'Year ignored (out of range)',
    invalid_date: 'Birthday in the file isn’t a real date',
    duplicate_in_file: '',
};

const fmt = (b: ImportBirthday) => formatBirthday(b.day, b.month, b.year) ?? '';

export default function ReviewImport({ import: contactImport, rows }: { import: { id: number; filename: string | null }; rows: ReviewRow[] }) {
    const [choices, setChoices] = useState<Record<string, Choice>>(() =>
        Object.fromEntries(
            rows.map((row) => [
                row.key,
                { include: row.default_include, name: row.name, action: row.match ? 'update' : 'add', birthday: 0, use_vcard: false },
            ]),
        ),
    );
    const [importNotes, setImportNotes] = useState(false);
    const [processing, setProcessing] = useState(false);

    const bySection = useMemo(() => {
        const groups = Object.fromEntries(SECTIONS.map((s) => [s.id, [] as ReviewRow[]])) as Record<Section, ReviewRow[]>;
        rows.forEach((row) => groups[row.section].push(row));

        return groups;
    }, [rows]);

    const update = (key: string, patch: Partial<Choice>) => setChoices((current) => ({ ...current, [key]: { ...current[key], ...patch } }));

    const setInclude = (row: ReviewRow, include: boolean) =>
        // For a conflict, ticking the row *is* choosing the contact's date.
        update(row.key, row.section === 'conflict' ? { include, use_vcard: include } : { include });

    const selectAll = (section: Section, include: boolean) =>
        setChoices((current) => {
            const next = { ...current };
            bySection[section].forEach((row) => {
                next[row.key] = { ...next[row.key], include, ...(section === 'conflict' ? { use_vcard: include } : {}) };
            });

            return next;
        });

    const selected = Object.values(choices).filter((c) => c.include).length;

    const submit = () => {
        setProcessing(true);
        router.post(
            route('people.import.commit', contactImport.id),
            {
                rows: Object.fromEntries(Object.entries(choices).filter(([, c]) => c.include)),
                import_notes: importNotes,
            },
            { onFinish: () => setProcessing(false) },
        );
    };

    const cancel = () => router.delete(route('people.import.destroy', contactImport.id));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'People', href: '/people' },
        { title: 'Import contacts', href: '/people/import' },
        { title: 'Review', href: `/people/import/${contactImport.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Review import" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 pb-32">
                <Heading
                    title="Review import"
                    description={`${rows.length} ${rows.length === 1 ? 'contact' : 'contacts'} from ${contactImport.filename ?? 'your file'}. Nothing is saved until you press Import.`}
                />

                {SECTIONS.map((section) => {
                    const sectionRows = bySection[section.id];
                    if (sectionRows.length === 0) {
                        return null;
                    }

                    const allOn = sectionRows.every((row) => choices[row.key].include);
                    const content = (
                        <ul className="bg-card divide-border divide-y rounded-xl border">
                            {sectionRows.map((row) => (
                                <ReviewItem
                                    key={row.key}
                                    row={row}
                                    choice={choices[row.key]}
                                    onInclude={(include) => setInclude(row, include)}
                                    onChange={(patch) => update(row.key, patch)}
                                />
                            ))}
                        </ul>
                    );

                    return (
                        <section key={section.id} className="space-y-2" aria-labelledby={`section-${section.id}`}>
                            <div className="flex items-end justify-between gap-3">
                                <div>
                                    <h2 id={`section-${section.id}`} className="font-medium">
                                        {section.title} <span className="text-muted-foreground font-normal">({sectionRows.length})</span>
                                    </h2>
                                    <p className="text-muted-foreground text-sm">{section.hint}</p>
                                </div>
                                <Button variant="ghost" size="sm" className="shrink-0" onClick={() => selectAll(section.id, !allOn)}>
                                    {allOn ? 'Select none' : 'Select all'}
                                </Button>
                            </div>
                            {section.id === 'no_birthday' ? (
                                <details>
                                    <summary className="text-muted-foreground hover:text-foreground cursor-pointer py-1 text-sm">Show</summary>
                                    {content}
                                </details>
                            ) : (
                                content
                            )}
                        </section>
                    );
                })}
            </div>

            <div className="bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky bottom-0 border-t backdrop-blur">
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={importNotes} onCheckedChange={(v) => setImportNotes(v === true)} />
                        Also import notes <span className="text-muted-foreground">(only into empty notes)</span>
                    </label>
                    <div className="flex gap-2">
                        <Button variant="ghost" onClick={cancel} disabled={processing}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={processing || selected === 0}>
                            Import {selected} {selected === 1 ? 'person' : 'people'}
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function ReviewItem({
    row,
    choice,
    onInclude,
    onChange,
}: {
    row: ReviewRow;
    choice: Choice;
    onInclude: (include: boolean) => void;
    onChange: (patch: Partial<Choice>) => void;
}) {
    const [renaming, setRenaming] = useState(false);
    const id = `row-${row.key}`;
    const warnings = row.warnings.map((w) => WARNINGS[w]).filter(Boolean);
    const existing = row.match ? formatBirthday(row.match.birth_day, row.match.birth_month, row.match.birth_year) : null;
    const creating = !row.match || choice.action === 'add';

    return (
        <li className="flex gap-3 px-4 py-3">
            <Checkbox
                id={id}
                className="mt-0.5"
                checked={choice.include}
                onCheckedChange={(v) => onInclude(v === true)}
                aria-label={`Import ${row.name}`}
            />
            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="flex flex-wrap items-center gap-x-2">
                    {renaming && creating ? (
                        <Input
                            autoFocus
                            aria-label="Name"
                            value={choice.name}
                            maxLength={255}
                            className="h-8"
                            onChange={(e) => onChange({ name: e.target.value })}
                            onBlur={() => setRenaming(false)}
                            onKeyDown={(e) => e.key === 'Enter' && setRenaming(false)}
                        />
                    ) : (
                        <label htmlFor={id} className="font-medium">
                            {creating ? choice.name : row.match!.name}
                        </label>
                    )}
                    {!creating && row.match!.name !== row.name && (
                        <span className="text-muted-foreground text-sm">“{row.name}” in your contacts</span>
                    )}
                    {creating && !renaming && (
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground text-xs underline"
                            onClick={() => setRenaming(true)}
                        >
                            Rename
                        </button>
                    )}
                </div>

                {row.section === 'duplicates' ? (
                    <fieldset className="space-y-1">
                        <legend className="text-muted-foreground text-sm">{row.cards} contacts with this name — which birthday?</legend>
                        {row.birthdays.map((b, i) => (
                            <label key={i} className="flex items-center gap-2 text-sm">
                                <input type="radio" name={`${id}-bday`} checked={choice.birthday === i} onChange={() => onChange({ birthday: i })} />
                                {fmt(b)}
                            </label>
                        ))}
                    </fieldset>
                ) : row.section === 'conflict' ? (
                    <p className="text-sm">
                        <span className={choice.use_vcard ? 'text-muted-foreground line-through' : ''}>In Offing: {existing}</span>
                        <span className="text-muted-foreground"> · </span>
                        <span className={choice.use_vcard ? 'text-primary font-medium' : 'text-muted-foreground'}>
                            Contact: {fmt({ ...row.birthdays[0], year: row.birthdays[0].year ?? row.match!.birth_year })}
                        </span>
                    </p>
                ) : row.birthdays.length > 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {fmt(row.birthdays[0])}
                        {row.match && choice.action === 'update' && (
                            <> · {row.match.fills.length > 0 ? `adds the ${describeFills(row.match.fills)}` : 'nothing new to add'}</>
                        )}
                    </p>
                ) : (
                    <p className="text-muted-foreground text-sm">No birthday{row.match ? ` · matches ${row.match.name}` : ''}</p>
                )}

                {row.match && row.section !== 'conflict' && (
                    <select
                        aria-label={`What to do with ${row.name}`}
                        className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                        value={choice.action}
                        onChange={(e) => onChange({ action: e.target.value as Choice['action'] })}
                    >
                        <option value="update">Update {row.match.name}</option>
                        <option value="add">Add as a new person</option>
                    </select>
                )}

                {warnings.length > 0 && <p className="text-xs text-amber-700 dark:text-amber-400">{warnings.join(' · ')}</p>}
            </div>
        </li>
    );
}

function describeFills(fills: string[]): string {
    const hasDate = fills.includes('birth_day') || fills.includes('birth_month');
    const hasYear = fills.includes('birth_year');

    return hasDate && hasYear ? 'birthday and year' : hasDate ? 'birthday' : 'year';
}
