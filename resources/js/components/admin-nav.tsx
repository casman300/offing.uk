import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

const ITEMS = [
    { title: 'Users', url: '/admin/users' },
    { title: 'Feature flags', url: '/admin/features' },
    { title: 'Outbox', url: '/admin/outbox' },
];

export default function AdminNav() {
    const current = window.location.pathname;

    return (
        <nav aria-label="Admin" className="flex gap-1 border-b">
            {ITEMS.map((item) => (
                <Link
                    key={item.url}
                    href={item.url}
                    className={cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm',
                        current === item.url
                            ? 'border-primary text-foreground font-medium'
                            : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {item.title}
                </Link>
            ))}
        </nav>
    );
}
