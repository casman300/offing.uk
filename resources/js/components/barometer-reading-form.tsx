import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { type BarometerReading, type BarometerTextColumn } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface BarometerReadingFormProps {
    reading?: BarometerReading;
    /** Viewer's local time now, YYYY-MM-DDTHH:MM — the default and the max. */
    now: string;
    submitLabel: string;
    onCancel?: () => void;
}

const textareaClass =
    'flex min-h-20 w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm';

/** The paper form's free-text headers, with a nudge for each. */
const TEXT_FIELDS: { name: BarometerTextColumn; label: string; hint: string }[] = [
    { name: 'situation', label: 'Situation', hint: 'Where were you, what was happening?' },
    { name: 'thoughts', label: 'Thoughts', hint: 'What went through your mind?' },
    { name: 'sensations', label: 'Physical sensations', hint: 'Breathing, heart, stomach, tension…' },
    { name: 'coping_action', label: 'What did I do?', hint: 'Anything that helped, or didn’t.' },
    { name: 'self_talk', label: 'What did I say to myself?', hint: 'What you told yourself to get through it.' },
];

const RATINGS = Array.from({ length: 11 }, (_, i) => String(i));

/**
 * Add/edit a Barometer reading. Only the time and the first rating are
 * required: the rating sits at the top so "8, now" is two taps, and
 * everything else can be filled in later. Exports keep the paper form's
 * column order regardless.
 */
export default function BarometerReadingForm({ reading, now, submitLabel, onCancel }: BarometerReadingFormProps) {
    const { data, setData, post, patch, processing, errors } = useForm({
        occurred_at: reading?.occurred_at ?? now,
        rating_before: reading ? String(reading.rating_before) : '',
        situation: reading?.situation ?? '',
        thoughts: reading?.thoughts ?? '',
        sensations: reading?.sensations ?? '',
        coping_action: reading?.coping_action ?? '',
        self_talk: reading?.self_talk ?? '',
        rating_after: reading?.rating_after != null ? String(reading.rating_after) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (reading) {
            patch(route('barometer.update', reading.id));
        } else {
            post(route('barometer.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-2">
                <Label htmlFor="occurred_at">When</Label>
                <Input
                    id="occurred_at"
                    type="datetime-local"
                    value={data.occurred_at}
                    max={now}
                    onChange={(e) => setData('occurred_at', e.target.value)}
                    required
                />
                <InputError message={errors.occurred_at} />
            </div>

            <RatingPicker
                id="rating_before"
                label="How anxious do you feel?"
                value={data.rating_before}
                onChange={(value) => setData('rating_before', value)}
                error={errors.rating_before}
            />

            {TEXT_FIELDS.map((field) => (
                <div key={field.name} className="grid gap-2">
                    <Label htmlFor={field.name}>
                        {field.label} <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <textarea
                        id={field.name}
                        className={textareaClass}
                        value={data[field.name]}
                        onChange={(e) => setData(field.name, e.target.value)}
                        placeholder={field.hint}
                        maxLength={5000}
                    />
                    <InputError message={errors[field.name]} />
                </div>
            ))}

            <RatingPicker
                id="rating_after"
                label="Second rating"
                hint="How anxious do you feel now? Often easiest to add a little later."
                value={data.rating_after}
                onChange={(value) => setData('rating_after', value)}
                error={errors.rating_after}
                optional
            />

            <div className="flex items-center gap-3">
                <Button disabled={processing || data.rating_before === ''}>{submitLabel}</Button>
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}

function RatingPicker({
    id,
    label,
    hint,
    value,
    onChange,
    error,
    optional = false,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    optional?: boolean;
}) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">
                {label} {optional && <span className="text-muted-foreground font-normal">(optional)</span>}
            </legend>
            {hint && <p className="text-muted-foreground -mt-1 text-sm">{hint}</p>}
            <ToggleGroup
                id={id}
                type="single"
                variant="outline"
                value={value}
                // Optional ratings can be cleared by tapping the chosen number again.
                onValueChange={(next) => (next !== '' || optional ? onChange(next) : undefined)}
                aria-label={label}
                className="grid grid-cols-6 gap-1.5 sm:grid-cols-11"
            >
                {RATINGS.map((rating) => (
                    <ToggleGroupItem
                        key={rating}
                        value={rating}
                        aria-label={`${rating} out of 10`}
                        className={cn(
                            'h-11 text-base tabular-nums',
                            'data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary data-[state=on]:hover:text-primary-foreground',
                        )}
                    >
                        {rating}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
            <div className="text-muted-foreground flex justify-between text-xs">
                <span>0 · calm</span>
                <span>10 · most anxious</span>
            </div>
            <InputError message={error} />
        </fieldset>
    );
}
