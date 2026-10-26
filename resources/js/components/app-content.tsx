import { SidebarInset } from '@/components/ui/sidebar';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
}

export function AppContent({ variant = 'header', children, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        // min-w-0: as a flex item the inset would otherwise refuse to shrink
        // below its widest unbreakable content (a truncated chore title), and
        // the whole page would scroll sideways on a phone.
        return (
            <SidebarInset className="min-w-0" {...props}>
                {children}
            </SidebarInset>
        );
    }

    return (
        <main className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl" {...props}>
            {children}
        </main>
    );
}
