import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type ListSummary } from '@/lib/shipshape';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowUp, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ExistingChore {
    id: number;
    title: string;
    area: string | null;
    notes: string | null;
    mode: 'floating' | 'fixed';
    every: number;
    unit: 'day' | 'week' | 'month' | 'year';
    due_on: string | null;
    lead_days: number | null;
    assign: 'anyone' | 'member' | 'rota';
    assignee_member_id: number | null;
    rota: number[];
}

const field =
    'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';

const LEADS = [
    { value: '', label: 'No heads-up — just on the day' },
    { value: '7', label: '1 week before' },
    { value: '14', label: '2 weeks before' },
    { value: '21', label: '3 weeks before' },
    { value: '30', label: 'A month before' },
];

export default function ChoreForm({ today, list, areas, chore }: { today: string; list: ListSummary; areas: string[]; chore: ExistingChore | null }) {
    const { data, setData, post, put, processing, errors } = useForm({
        title: chore?.title ?? '',
        area: chore?.area ?? '',
        notes: chore?.notes ?? '',
        mode: chore?.mode ?? ('floating' as 'floating' | 'fixed'),
        every: chore?.every ?? 1,
        unit: chore?.unit ?? ('week' as ExistingChore['unit']),
        due_on: chore ? (chore.due_on ?? '') : today,
        lead_days: chore?.lead_days ? String(chore.lead_days) : '',
        assign: chore?.assign ?? ('anyone' as ExistingChore['assign']),
        assignee_member_id: chore?.assignee_member_id ? String(chore.assignee_member_id) : '',
        rota: chore?.rota ?? ([] as number[]),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shipshape', href: '/shipshape' },
        { title: list.name, href: `/shipshape/lists/${list.id}` },
        { title: chore ? 'Edit chore' : 'New chore', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (chore) put(route('shipshape.chores.update', chore.id));
        else post(route('shipshape.chores.store', list.id));
    };

    const inRota = (id: number) => data.rota.includes(id);
    const toggleRota = (id: number) => setData('rota', inRota(id) ? data.rota.filter((m) => m !== id) : [...data.rota, id]);
    const moveUp = (index: number) => {
        if (index === 0) return;
        const next = [...data.rota];
        [next[index - 1], next[index]] = [next[index], next[index - 1]];
        setData('rota', next);
    };
    const memberName = (id: number) => {
        const m = list.members.find((member) => member.id === id);
        return m ? (m.is_me ? 'You' : m.name) : 'Someone';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={chore ? `Edit ${chore.title}` : 'New chore'} />

            <form onSubmit={submit} className="mx-auto w-full max-w-xl space-y-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">{chore ? 'Edit chore' : 'New chore'}</h1>
                    <p className="text-muted-foreground text-sm">
                        On {list.name} · {list.is_crew ? `shared with ${list.owner}` : 'just you'}
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="title">Chore</Label>
                    <Input
                        id="title"
                        required
                        maxLength={100}
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="Hoover & mop floors"
                    />
                    <InputError message={errors.title} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="area">
                        Area <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <Input
                        id="area"
                        list="areas"
                        maxLength={40}
                        value={data.area}
                        onChange={(e) => setData('area', e.target.value)}
                        placeholder="Kitchen"
                    />
                    <datalist id="areas">
                        {areas.map((area) => (
                            <option key={area} value={area} />
                        ))}
                    </datalist>
                </div>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">How does it repeat?</legend>
                    {(
                        [
                            { value: 'floating', title: 'From when it’s done', hint: 'The clock restarts when you tick it. Best for cleaning.' },
                            { value: 'fixed', title: 'On set dates', hint: 'Same pattern whatever happens. Best for bills, renewals and bin day.' },
                        ] as const
                    ).map((option) => (
                        <label
                            key={option.value}
                            className="has-[:checked]:border-primary has-[:checked]:bg-accent flex cursor-pointer items-start gap-3 rounded-lg border-2 p-3"
                        >
                            <input
                                type="radio"
                                name="mode"
                                className="mt-1"
                                checked={data.mode === option.value}
                                onChange={() => setData('mode', option.value)}
                            />
                            <span>
                                <span className="block text-sm font-semibold">{option.title}</span>
                                <span className="text-muted-foreground block text-sm">{option.hint}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>

                <div className="bg-card space-y-4 rounded-xl border p-4">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">Every</span>
                        <Input
                            type="number"
                            aria-label="How many"
                            min={1}
                            max={365}
                            className="w-20"
                            value={data.every}
                            onChange={(e) => setData('every', Number(e.target.value))}
                        />
                        <select
                            aria-label="Unit"
                            className={field}
                            value={data.unit}
                            onChange={(e) => setData('unit', e.target.value as ExistingChore['unit'])}
                        >
                            {(['day', 'week', 'month', 'year'] as const).map((unit) => (
                                <option key={unit} value={unit}>
                                    {data.every === 1 ? unit : `${unit}s`}
                                </option>
                            ))}
                        </select>
                    </div>
                    <InputError message={errors.every} />

                    <div className="grid gap-2">
                        <Label htmlFor="due_on">
                            Next due
                            {data.mode === 'fixed' && <span className="text-muted-foreground font-normal"> (leave blank if you don't know yet)</span>}
                        </Label>
                        <Input
                            id="due_on"
                            type="date"
                            value={data.due_on}
                            required={data.mode === 'floating'}
                            onChange={(e) => setData('due_on', e.target.value)}
                        />
                        <InputError message={errors.due_on} />
                        {data.mode === 'fixed' && (
                            <p className="text-muted-foreground text-xs">
                                The pattern repeats from this date — every {data.unit === 'week' ? 'same weekday' : 'same date'}.
                            </p>
                        )}
                    </div>

                    {data.mode === 'fixed' && (
                        <div className="grid gap-2">
                            <Label htmlFor="lead">Heads-up</Label>
                            <select id="lead" className={field} value={data.lead_days} onChange={(e) => setData('lead_days', e.target.value)}>
                                {LEADS.map((lead) => (
                                    <option key={lead.value} value={lead.value}>
                                        {lead.label}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">Shows it on the board early — time to shop around for renewals.</p>
                        </div>
                    )}
                </div>

                {list.is_crew && (
                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">Who does it?</legend>
                        <div role="group" aria-label="Assignment" className="bg-muted grid grid-cols-3 gap-1 rounded-lg p-1">
                            {(['anyone', 'member', 'rota'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={data.assign === value}
                                    onClick={() => setData('assign', value)}
                                    className={cn(
                                        'rounded-md py-2 text-sm font-medium',
                                        data.assign === value ? 'bg-background shadow-sm' : 'text-muted-foreground',
                                    )}
                                >
                                    {value === 'anyone' ? 'Anyone' : value === 'member' ? 'One person' : 'Rota'}
                                </button>
                            ))}
                        </div>

                        {data.assign === 'anyone' && <p className="text-muted-foreground text-sm">Whoever gets to it first.</p>}

                        {data.assign === 'member' && (
                            <div className="grid gap-2">
                                <Label htmlFor="assignee">Pinned to</Label>
                                <select
                                    id="assignee"
                                    className={field}
                                    value={data.assignee_member_id}
                                    onChange={(e) => setData('assignee_member_id', e.target.value)}
                                >
                                    <option value="">Choose…</option>
                                    {list.members.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.is_me ? 'You' : m.name}
                                            {m.kind === 'passenger' ? ' (passenger)' : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.assignee_member_id} />
                                <p className="text-muted-foreground text-xs">Anyone can still tick it off — the log records who actually did it.</p>
                            </div>
                        )}

                        {data.assign === 'rota' && (
                            <div className="space-y-3">
                                <div className="flex flex-wrap gap-2">
                                    {list.members.map((m) => (
                                        <label
                                            key={m.id}
                                            className="has-[:checked]:border-primary has-[:checked]:bg-accent flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border-2 px-3 text-sm"
                                        >
                                            <input type="checkbox" checked={inRota(m.id)} onChange={() => toggleRota(m.id)} />
                                            {m.is_me ? 'You' : m.name}
                                        </label>
                                    ))}
                                </div>
                                {data.rota.length > 0 && (
                                    <ol className="bg-card divide-y rounded-lg border">
                                        {data.rota.map((id, index) => (
                                            <li key={id} className="flex items-center gap-3 px-3 py-1.5 text-sm">
                                                <span className="text-muted-foreground w-5 font-semibold">{index + 1}</span>
                                                <span className="flex-1">{memberName(id)}</span>
                                                {index === 0 && (
                                                    <span className="bg-accent text-accent-foreground rounded-full px-2 text-xs font-semibold">
                                                        first up
                                                    </span>
                                                )}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Move ${memberName(id)} up`}
                                                    disabled={index === 0}
                                                    onClick={() => moveUp(index)}
                                                >
                                                    <ArrowUp />
                                                </Button>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                                <InputError message={errors.rota} />
                                <p className="text-muted-foreground text-xs">
                                    Passes to the next person each time it's done. Skips and covering for someone don't change whose turn is next.
                                </p>
                            </div>
                        )}
                    </fieldset>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="notes">
                        Notes <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <textarea
                        id="notes"
                        rows={3}
                        maxLength={2000}
                        className={cn(field, 'h-auto py-2')}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        placeholder="e.g. mop's under the stairs"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button disabled={processing}>{chore ? 'Save' : 'Add chore'}</Button>
                    <Button asChild variant="ghost">
                        <Link href={route('shipshape.lists.show', list.id)}>Cancel</Link>
                    </Button>
                    {chore && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive ml-auto"
                            onClick={() => router.delete(route('shipshape.chores.destroy', chore.id))}
                        >
                            <Trash2 /> Delete
                        </Button>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
