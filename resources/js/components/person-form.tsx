import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ageFrom, isLeapYear, isValidDate, MIN_YEAR, MONTHS, parseDate, yearFromAge } from '@/lib/birthday';
import { cn } from '@/lib/utils';
import { type Person } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface PersonFormProps {
    person?: Person;
    /** Viewer's local date, YYYY-MM-DD. */
    today: string;
    submitLabel: string;
    onCancel?: () => void;
    /** Called after a successful update (the page stays mounted across the redirect). */
    onSaved?: () => void;
}

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

const toInt = (value: string): number | null => (/^\d+$/.test(value) ? Number(value) : null);

/**
 * Add/edit form for a person. The birthday takes day + month (together or
 * not at all) and an optional year. Age is a convenience field: it fills in
 * the year and is never sent — and it needs day + month, because without
 * them we can't tell whether this year's birthday has happened yet.
 */
export default function PersonForm({ person, today, submitLabel, onCancel, onSaved }: PersonFormProps) {
    const todayDate = parseDate(today);

    const { data, setData, post, patch, processing, errors } = useForm({
        name: person?.name ?? '',
        birth_day: person?.birth_day?.toString() ?? '',
        birth_month: person?.birth_month?.toString() ?? '',
        birth_year: person?.birth_year?.toString() ?? '',
        notes: person?.notes ?? '',
    });

    const day = toInt(data.birth_day);
    const month = toInt(data.birth_month);
    const year = data.birth_year.length === 4 ? toInt(data.birth_year) : null;
    const hasDate = day !== null && month !== null;

    const ageFor = (d: number | null, m: number | null, y: number | null): string =>
        d !== null && m !== null && y !== null && isValidDate(d, m, y) && y <= todayDate.year ? String(ageFrom(d, m, y, todayDate)) : '';

    const [age, setAge] = useState(() => ageFor(day, month, year));

    const changeDate = (field: 'birth_day' | 'birth_month', value: string) => {
        setData(field, value);
        const d = field === 'birth_day' ? toInt(value) : day;
        const m = field === 'birth_month' ? toInt(value) : month;

        // Keep the stored year; the age follows the new date.
        setAge(ageFor(d, m, year));
    };

    const changeYear = (value: string) => {
        const digits = value.replace(/\D/g, '').slice(0, 4);
        setData('birth_year', digits);
        setAge(ageFor(day, month, digits.length === 4 ? Number(digits) : null));
    };

    const changeAge = (value: string) => {
        const digits = value.replace(/\D/g, '').slice(0, 3);
        setAge(digits);

        if (hasDate && digits !== '') {
            setData('birth_year', String(yearFromAge(Number(digits), day, month, todayDate)));
        } else if (digits === '') {
            setData('birth_year', '');
        }
    };

    // Live hints; the server re-checks all of this.
    let dateHint: string | null = null;
    if (hasDate && !isValidDate(day, month, null)) {
        dateHint = `${MONTHS[month - 1]} doesn't have a ${day}${ordinal(day)}.`;
    } else if (hasDate && year !== null && day === 29 && month === 2 && !isLeapYear(year)) {
        dateHint = `29 February only exists in leap years — ${year} wasn't one.`;
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (person) {
            patch(route('people.update', person.id), { preserveScroll: true, onSuccess: () => onSaved?.() });
        } else {
            post(route('people.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Name</Label>
                <Input
                    id="name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    maxLength={255}
                    autoComplete="off"
                    placeholder="e.g. Auntie Sue"
                    autoFocus={!person}
                />
                <InputError message={errors.name} />
            </div>

            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-medium">Birthday</legend>

                <div className="grid grid-cols-[5rem_1fr] gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="birth_day" className="text-muted-foreground text-xs">
                            Day
                        </Label>
                        <select
                            id="birth_day"
                            className={selectClass}
                            value={data.birth_day}
                            onChange={(e) => changeDate('birth_day', e.target.value)}
                        >
                            <option value="">–</option>
                            {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="birth_month" className="text-muted-foreground text-xs">
                            Month
                        </Label>
                        <select
                            id="birth_month"
                            className={selectClass}
                            value={data.birth_month}
                            onChange={(e) => changeDate('birth_month', e.target.value)}
                        >
                            <option value="">–</option>
                            {MONTHS.map((name, i) => (
                                <option key={name} value={i + 1}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
                <InputError message={dateHint ?? errors.birth_day ?? errors.birth_month} />

                <div className="grid grid-cols-2 gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="birth_year" className="text-muted-foreground text-xs">
                            Year born <span className="font-normal">(optional)</span>
                        </Label>
                        <Input
                            id="birth_year"
                            inputMode="numeric"
                            autoComplete="off"
                            placeholder="e.g. 1983"
                            value={data.birth_year}
                            onChange={(e) => changeYear(e.target.value)}
                            aria-describedby="birth_year_hint"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="age" className={cn('text-muted-foreground text-xs', !hasDate && 'opacity-60')}>
                            …or age now
                        </Label>
                        <Input
                            id="age"
                            inputMode="numeric"
                            autoComplete="off"
                            placeholder={hasDate ? 'e.g. 42' : ''}
                            value={hasDate ? age : ''}
                            onChange={(e) => changeAge(e.target.value)}
                            disabled={!hasDate}
                            aria-describedby="birth_year_hint"
                        />
                    </div>
                </div>
                <p id="birth_year_hint" className="text-muted-foreground text-xs">
                    {hasDate
                        ? 'Enter whichever you know — the other fills itself in. Leave both blank if you don’t know.'
                        : 'Add the birthday date to enter an age. A year on its own is fine too.'}
                </p>
                <InputError message={errors.birth_year} />
                <span className="sr-only" aria-live="polite">
                    {hasDate && age !== '' && data.birth_year !== '' ? `Born ${data.birth_year}, age ${age}` : ''}
                </span>
                {data.birth_year !== '' && Number(data.birth_year) < MIN_YEAR && data.birth_year.length === 4 && (
                    <InputError message={`The year must be ${MIN_YEAR} or later.`} />
                )}
            </fieldset>

            <div className="grid gap-2">
                <Label htmlFor="notes">
                    Notes <span className="text-muted-foreground font-normal">(optional, only you see these)</span>
                </Label>
                <textarea
                    id="notes"
                    className={cn(selectClass, 'min-h-24 resize-y')}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    maxLength={5000}
                />
                <InputError message={errors.notes} />
            </div>

            <div className="flex items-center gap-3">
                <Button disabled={processing}>{submitLabel}</Button>
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}

function ordinal(n: number): string {
    if (n % 100 >= 11 && n % 100 <= 13) {
        return 'th';
    }

    return ['th', 'st', 'nd', 'rd'][n % 10] ?? 'th';
}
