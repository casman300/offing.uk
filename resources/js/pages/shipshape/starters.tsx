import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Starter {
    key: string;
    title: string;
    area: string | null;
    mode: 'floating' | 'fixed';
    needs: 'date' | 'weekday' | null;
    present: boolean;
}

interface Group {
    key: string;
    label: string;
    items: Starter[];
}

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

export default function Starters({
    list,
    fresh,
    groups,
}: {
    list: { id: number; name: string; owner: string; is_crew: boolean };
    fresh: boolean;
    groups: Group[];
}) {
    const available = groups.flatMap((g) => g.items).filter((item) => !item.present);
    const { data, setData, post, processing } = useForm({
        keys: available.map((item) => item.key),
        dates: {} as Record<string, string>,
        weekday: 2,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shipshape', href: '/shipshape' },
        { title: list.name, href: `/shipshape/lists/${list.id}` },
        { title: 'Suggestions', href: `/shipshape/lists/${list.id}/starters` },
    ];

    const on = (key: string) => data.keys.includes(key);
    const toggle = (key: string, value: boolean) => setData('keys', value ? [...data.keys, key] : data.keys.filter((k) => k !== key));
    const toggleGroup = (group: Group, value: boolean) => {
        const keys = group.items.filter((item) => !item.present).map((item) => item.key);
        setData('keys', value ? [...new Set([...data.keys, ...keys])] : data.keys.filter((k) => !keys.includes(k)));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('shipshape.starters.store', list.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Suggestions" />

            <form onSubmit={submit} className="mx-auto w-full max-w-2xl space-y-6 p-4 pb-40">
                <div className="space-y-1">
                    <p className="text-primary text-sm font-medium">
                        {fresh ? 'New list' : 'Suggestions'} · {list.name} · {list.is_crew ? `shared with ${list.owner}` : 'just you'}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">{fresh ? 'Want a head start?' : 'Browse suggestions'}</h1>
                    <p className="text-muted-foreground text-sm">
                        Untick anything that doesn't fit. Renewals without a date are added as “needs a date” and never nag. Everything's editable
                        afterwards.
                    </p>
                </div>

                {groups.map((group) => {
                    const open = group.items.filter((item) => !item.present);
                    const allOn = open.length > 0 && open.every((item) => on(item.key));

                    return (
                        <section key={group.key} aria-labelledby={`g-${group.key}`} className="space-y-2">
                            <div className="flex items-center justify-between">
                                <h2 id={`g-${group.key}`} className="text-xs font-bold tracking-wider uppercase">
                                    {group.label}
                                </h2>
                                {open.length > 0 && (
                                    <button type="button" className="text-primary text-sm font-medium" onClick={() => toggleGroup(group, !allOn)}>
                                        {allOn ? 'Untick all' : 'Tick all'}
                                    </button>
                                )}
                            </div>
                            <ul className="bg-card divide-y rounded-xl border">
                                {group.items.map((item) => (
                                    <li key={item.key} className="space-y-2 px-3 py-2.5">
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id={`s-${item.key}`}
                                                checked={item.present || on(item.key)}
                                                disabled={item.present}
                                                onCheckedChange={(value) => toggle(item.key, value === true)}
                                            />
                                            <Label
                                                htmlFor={`s-${item.key}`}
                                                className={item.present ? 'text-muted-foreground flex-1 font-normal' : 'flex-1 font-normal'}
                                            >
                                                {item.title}
                                                {item.present && <span className="text-xs"> · already on the list</span>}
                                            </Label>
                                            <span
                                                className={
                                                    item.mode === 'fixed'
                                                        ? 'rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-900 dark:bg-orange-950 dark:text-orange-200'
                                                        : 'bg-accent text-accent-foreground rounded-full px-2 py-0.5 text-xs font-semibold'
                                                }
                                            >
                                                {item.mode === 'fixed' ? 'Fixed' : 'Floating'}
                                            </span>
                                        </div>
                                        {on(item.key) && item.needs === 'date' && (
                                            <div className="flex items-center gap-2 pl-7">
                                                <Label htmlFor={`d-${item.key}`} className="text-muted-foreground text-sm font-normal">
                                                    Next due
                                                </Label>
                                                <Input
                                                    id={`d-${item.key}`}
                                                    type="date"
                                                    className="h-9 flex-1"
                                                    value={data.dates[item.key] ?? ''}
                                                    onChange={(e) => setData('dates', { ...data.dates, [item.key]: e.target.value })}
                                                />
                                            </div>
                                        )}
                                        {on(item.key) && item.needs === 'weekday' && (
                                            <div className="flex items-center gap-2 pl-7">
                                                <Label htmlFor="weekday" className="text-muted-foreground text-sm font-normal">
                                                    Bin day
                                                </Label>
                                                <select
                                                    id="weekday"
                                                    className="border-input bg-background h-9 flex-1 rounded-md border px-2 text-sm"
                                                    value={data.weekday}
                                                    onChange={(e) => setData('weekday', Number(e.target.value))}
                                                >
                                                    {WEEKDAYS.map((day, i) => (
                                                        <option key={day} value={i + 1}>
                                                            {day}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}

                <div className="bg-background/95 fixed inset-x-0 bottom-0 border-t p-4 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0">
                    <div className="mx-auto grid max-w-2xl gap-2">
                        <Button size="lg" disabled={processing}>
                            {data.keys.length === 0 ? 'Add nothing' : `Add ${data.keys.length} ${data.keys.length === 1 ? 'chore' : 'chores'}`}
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href={route('shipshape.lists.show', list.id)}>{fresh ? 'Start with an empty list' : 'Cancel'}</Link>
                        </Button>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}
