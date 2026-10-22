import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { cadence, type ChoreRow as Chore, dueLabel, type ListSummary, whoLabel } from '@/lib/shipshape';
import { cn } from '@/lib/utils';
import shipshape from '@/routes/shipshape';
import { Link, router, useForm } from '@inertiajs/react';
import { Check, ChevronsRight, Ellipsis, RefreshCw } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const addDays = (iso: string, n: number) => {
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
};

export function ChoreRow({ chore, list, today, showList = true }: { chore: Chore; list: ListSummary; today: string; showList?: boolean }) {
    const [busy, setBusy] = useState(false);
    const late = chore.group === 'overdue';
    const undated = chore.due_on === null;

    const tick = () => {
        setBusy(true);
        router.post(
            shipshape.chores.log.url(chore.id),
            { kind: 'done', version: chore.version },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const meta = [showList ? list.name : null, chore.area].filter(Boolean).join(' · ');

    return (
        <li className={cn('bg-card flex items-center gap-2 rounded-xl border py-2 pr-1 pl-1', late && 'border-orange-300 dark:border-orange-900')}>
            <button
                type="button"
                onClick={tick}
                disabled={busy || undated}
                aria-label={undated ? `${chore.title} needs a date before it can be ticked` : `Tick off ${chore.title}`}
                className="flex size-11 shrink-0 items-center justify-center rounded-full disabled:opacity-50"
            >
                <span
                    className={cn(
                        'flex size-6 items-center justify-center rounded-full border-2',
                        late ? 'border-orange-700 dark:border-orange-400' : 'border-muted-foreground/60',
                        busy && 'bg-primary border-primary text-primary-foreground',
                    )}
                >
                    {busy && <Check className="size-4" strokeWidth={3} aria-hidden />}
                </span>
            </button>

            <div className="min-w-0 flex-1">
                {list.editable ? (
                    <Link href={shipshape.chores.edit.url(chore.id)} className="block truncate font-medium hover:underline">
                        {chore.title}
                    </Link>
                ) : (
                    <span className="block truncate font-medium">{chore.title}</span>
                )}
                <span className="text-muted-foreground flex flex-wrap items-center gap-x-1.5 text-sm">
                    {meta && <span>{meta}</span>}
                    {chore.heads_up && (
                        <span className="rounded-full bg-orange-100 px-1.5 text-xs font-semibold text-orange-900 dark:bg-orange-950 dark:text-orange-200">
                            Heads-up
                        </span>
                    )}
                </span>
            </div>

            <div className="flex shrink-0 flex-col items-end gap-0.5 text-right">
                <span
                    className={cn(
                        'text-sm',
                        late && 'font-semibold text-orange-800 dark:text-orange-300',
                        chore.group === 'today' && 'text-primary font-semibold',
                        undated && 'text-muted-foreground italic',
                    )}
                >
                    {dueLabel(chore)}
                </span>
                <span className="text-muted-foreground flex items-center gap-1 text-xs">
                    {chore.assign === 'rota' && <RefreshCw className="size-3" aria-label="Rota" />}
                    {list.is_crew ? whoLabel(chore) : cadence(chore.every, chore.unit)}
                </span>
            </div>

            {!undated && <LogSheet chore={chore} list={list} today={today} />}
        </li>
    );
}

function LogSheet({ chore, list, today }: { chore: Chore; list: ListSummary; today: string }) {
    const [open, setOpen] = useState(false);
    const [when, setWhen] = useState<'today' | 'yesterday' | 'pick'>('today');
    const me = list.members.find((m) => m.is_me);
    const { data, setData, post, processing, errors, transform } = useForm({
        done_by: (me?.id ?? '') as number | '',
        done_on: today,
        reason: '',
        skipping: false as boolean,
    });

    const submit =
        (kind: 'done' | 'skip'): FormEventHandler =>
        (e) => {
            e.preventDefault();
            transform((form) => ({
                kind,
                version: chore.version,
                done_on: kind === 'done' ? form.done_on : today,
                done_by: form.done_by === '' ? null : form.done_by,
                reason: kind === 'skip' ? form.reason : null,
            }));
            post(shipshape.chores.log.url(chore.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
        };

    const pickWhen = (value: typeof when) => {
        setWhen(value);
        if (value === 'today') setData('done_on', today);
        if (value === 'yesterday') setData('done_on', addDays(today, -1));
    };

    const covering = chore.who !== null && data.done_by !== '' && data.done_by !== chore.who.member_id;
    const doer = list.members.find((m) => m.id === data.done_by);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={`More ways to log ${chore.title}`} className="shrink-0">
                    <Ellipsis />
                </Button>
            </SheetTrigger>
            <SheetContent side="bottom" className="mx-auto max-h-[90vh] max-w-lg overflow-y-auto rounded-t-2xl">
                <SheetHeader>
                    <SheetTitle>{chore.title}</SheetTitle>
                    <SheetDescription>
                        {[list.name, chore.area, cadence(chore.every, chore.unit), chore.mode === 'fixed' ? 'fixed dates' : null]
                            .filter(Boolean)
                            .join(' · ')}
                    </SheetDescription>
                </SheetHeader>

                <form onSubmit={submit(data.skipping ? 'skip' : 'done')} className="space-y-5 px-4 pb-6">
                    {list.is_crew && list.members.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">Who did it?</legend>
                            <div className="flex flex-wrap gap-2">
                                {list.members.map((member) => (
                                    <button
                                        key={member.id}
                                        type="button"
                                        aria-pressed={data.done_by === member.id}
                                        onClick={() => setData('done_by', member.id)}
                                        className={cn(
                                            'flex min-h-11 items-center gap-2 rounded-lg border-2 px-3 text-sm',
                                            data.done_by === member.id ? 'border-primary bg-accent' : 'border-border',
                                        )}
                                    >
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'flex size-6 items-center justify-center rounded-full text-xs font-semibold',
                                                member.kind === 'passenger'
                                                    ? 'border border-dashed border-current'
                                                    : 'bg-primary text-primary-foreground',
                                            )}
                                        >
                                            {member.name.charAt(0)}
                                        </span>
                                        {member.is_me ? 'You' : member.name}
                                    </button>
                                ))}
                            </div>
                            {covering && chore.assign === 'rota' && (
                                <p className="bg-accent text-accent-foreground rounded-md px-3 py-2 text-sm">
                                    {doer?.is_me ? "You're" : `${doer?.name} is`} covering {chore.who?.is_me ? 'your' : `${chore.who?.name}'s`} turn.
                                    The rota carries on from {chore.who?.is_me ? 'yours' : 'theirs'}, so nobody loses their place.
                                </p>
                            )}
                        </fieldset>
                    )}

                    {!data.skipping && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">When?</legend>
                            <div className="grid grid-cols-3 gap-2">
                                {(['today', 'yesterday', 'pick'] as const).map((value) => (
                                    <Button
                                        key={value}
                                        type="button"
                                        variant={when === value ? 'default' : 'outline'}
                                        aria-pressed={when === value}
                                        onClick={() => pickWhen(value)}
                                    >
                                        {value === 'today' ? 'Today' : value === 'yesterday' ? 'Yesterday' : 'Pick a date'}
                                    </Button>
                                ))}
                            </div>
                            {when === 'pick' && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`done-on-${chore.id}`}>Date it was done</Label>
                                    <Input
                                        id={`done-on-${chore.id}`}
                                        type="date"
                                        max={today}
                                        value={data.done_on}
                                        onChange={(e) => setData('done_on', e.target.value)}
                                    />
                                </div>
                            )}
                            <InputError message={errors.done_on} />
                        </fieldset>
                    )}

                    {data.skipping && (
                        <div className="bg-muted/50 space-y-2 rounded-lg p-3">
                            <Label htmlFor={`reason-${chore.id}`}>Why skip? (optional)</Label>
                            <Input
                                id={`reason-${chore.id}`}
                                value={data.reason}
                                maxLength={200}
                                placeholder="e.g. away this week"
                                onChange={(e) => setData('reason', e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">A skip moves the date on but isn't counted as done.</p>
                        </div>
                    )}

                    <InputError message={(errors as Record<string, string>).chore} />

                    <div className="grid gap-2">
                        <Button disabled={processing} size="lg">
                            {data.skipping ? 'Skip it' : doer && !doer.is_me ? `Log it as ${doer.name}` : 'Log it'}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => setData('skipping', !data.skipping)}>
                            {data.skipping ? (
                                'Cancel skip'
                            ) : (
                                <>
                                    <ChevronsRight /> Skip this one
                                </>
                            )}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}
