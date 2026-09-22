import { Badge } from '@/components/ui/badge';
import type { ProjectMemberRef } from '@/types/projects';

/** Read-only membership: name, role and an owner marker. Carries no email by construction. */
export function ProjectMemberList({ members }: { members: ProjectMemberRef[] }) {
    return (
        <ul className="divide-y divide-border border-y border-border">
            {members.map((member) => (
                <li
                    key={member.id}
                    className="flex items-center justify-between gap-3 py-2.5 text-sm"
                >
                    <span className="min-w-0 truncate">{member.name}</span>
                    <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        {member.role === 'manager' ? 'Manager' : 'Member'}
                        {member.isOwner ? <Badge variant="info">Owner</Badge> : null}
                    </span>
                </li>
            ))}
        </ul>
    );
}
