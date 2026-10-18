import { Badge } from '@/components/ui/badge';
import { type CrewRole } from '@/types';

export const ROLE_LABELS: Record<CrewRole, string> = {
    captain: 'Captain',
    crew: 'Crew',
    passenger: 'Passenger',
};

const ROLE_HINTS: Record<CrewRole, string> = {
    captain: 'Runs the crew: members, roles and invites.',
    crew: 'Sees and edits everything shared with the crew.',
    passenger: 'No account. Crewmates act for them.',
};

export function RoleBadge({ role }: { role: CrewRole }) {
    return <Badge variant={role === 'captain' ? 'default' : role === 'crew' ? 'secondary' : 'outline'}>{ROLE_LABELS[role]}</Badge>;
}

export function RoleLegend() {
    return (
        <dl className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {(Object.keys(ROLE_LABELS) as CrewRole[]).map((role) => (
                <div key={role} className="bg-card rounded-lg border p-3">
                    <dt className="text-sm font-medium">{ROLE_LABELS[role]}</dt>
                    <dd className="text-muted-foreground text-xs">{ROLE_HINTS[role]}</dd>
                </div>
            ))}
        </dl>
    );
}
