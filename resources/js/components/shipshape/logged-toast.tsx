import shipshape from '@/routes/shipshape';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const UNDO_MS = 10_000;

/**
 * The 10-second "Logged · Undo" toast after a tick (spec §25), plus the
 * "someone just logged this" notice when a tick was stale.
 */
export function LoggedToast() {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [logged, setLogged] = useState(flash.logged);

    useEffect(() => {
        setLogged(flash.logged);
        if (!flash.logged) return;

        const timer = setTimeout(() => setLogged(null), UNDO_MS);
        return () => clearTimeout(timer);
    }, [flash.logged]);

    const undo = () => {
        if (!logged) return;
        router.post(shipshape.entries.undo.url(logged.entry_id), {}, { preserveScroll: true });
        setLogged(null);
    };

    return (
        <>
            {errors.chore && (
                <p role="alert" className="rounded-lg bg-orange-100 px-4 py-3 text-sm text-orange-950 dark:bg-orange-950 dark:text-orange-100">
                    {errors.chore}
                </p>
            )}
            {logged && (
                <div
                    role="status"
                    className="fixed inset-x-4 bottom-4 z-50 mx-auto flex max-w-md items-center gap-2 rounded-xl bg-slate-900 py-2 pr-2 pl-4 text-white shadow-lg sm:bottom-6"
                >
                    <p className="min-w-0 flex-1 text-sm">
                        <span className="font-semibold">{logged.kind === 'skip' ? 'Skipped' : 'Logged'}</span> · {logged.title}
                    </p>
                    <button type="button" onClick={undo} className="h-10 rounded-lg bg-white px-4 text-sm font-semibold text-slate-900">
                        Undo
                    </button>
                </div>
            )}
        </>
    );
}
