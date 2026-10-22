import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import shipshape from '@/routes/shipshape';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Lock, Plus, Ship } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ListRow {
    id: number;
    name: string;
    owner: string;
    is_crew: boolean;
    chore_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Shipshape', href: '/shipshape' },
    { title: 'Lists', href: '/shipshape/lists' },
];

export default function Lists({ lists, crews }: { lists: ListRow[]; crews: { id: number; name: string }[] }) {
    const { features } = usePage<SharedData>().props;
    const { data, setData, post, processing, errors } = useForm({
        name: crews.length > 0 && lists.length === 0 ? crews[0].name : '',
        owner: crews.length > 0 ? String(crews[0].id) : 'me',
    });

    const create: FormEventHandler = (e) => {
        e.preventDefault();
        post(shipshape.lists.store.url());
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Lists" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading title="Lists" description="A list is who a set of chores is shared with — just you, or a whole crew." />

                {lists.length > 0 && (
                    <ul className="space-y-2">
                        {lists.map((list) => (
                            <li key={list.id}>
                                <Link
                                    href={shipshape.lists.show.url(list.id)}
                                    className="bg-card hover:bg-accent/40 flex items-center gap-3 rounded-xl border p-4"
                                >
                                    <span
                                        className="bg-accent text-accent-foreground flex size-10 shrink-0 items-center justify-center rounded-lg"
                                        aria-hidden
                                    >
                                        {list.is_crew ? <Ship className="size-5" /> : <Lock className="size-5" />}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium">{list.name}</span>
                                        <span className="text-muted-foreground block text-sm">
                                            {list.is_crew ? `Shared with ${list.owner}` : 'Just you'} · {list.chore_count}{' '}
                                            {list.chore_count === 1 ? 'chore' : 'chores'}
                                        </span>
                                    </span>
                                    <ChevronRight className="text-muted-foreground size-4" aria-hidden />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <form onSubmit={create} className="bg-card space-y-4 rounded-xl border p-4">
                    <h2 className="flex items-center gap-2 text-sm font-medium">
                        <Plus className="size-4" aria-hidden /> New list
                    </h2>
                    <div className="grid gap-2">
                        <Label htmlFor="list-name">Name</Label>
                        <Input
                            id="list-name"
                            required
                            maxLength={60}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Home"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">Shared with</legend>
                        {[
                            { value: 'me', label: 'Just me', hint: 'Private — only you see it.' },
                            ...crews.map((crew) => ({ value: String(crew.id), label: crew.name, hint: 'Everyone in the crew sees and ticks it.' })),
                        ].map((option) => (
                            <label
                                key={option.value}
                                className="has-[:checked]:border-primary has-[:checked]:bg-accent flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                            >
                                <input
                                    type="radio"
                                    name="owner"
                                    value={option.value}
                                    checked={data.owner === option.value}
                                    onChange={() => setData('owner', option.value)}
                                    className="mt-1 accent-current"
                                />
                                <span>
                                    <span className="block text-sm font-medium">{option.label}</span>
                                    <span className="text-muted-foreground block text-xs">{option.hint}</span>
                                </span>
                            </label>
                        ))}
                        {crews.length === 0 && features.crews && (
                            <p className="text-muted-foreground text-xs">
                                To share chores, start a crew on the{' '}
                                <Link href="/crew" className="text-primary underline">
                                    Crew
                                </Link>{' '}
                                page first.
                            </p>
                        )}
                    </fieldset>
                    <Button disabled={processing}>Create list</Button>
                </form>
            </div>
        </AppLayout>
    );
}
