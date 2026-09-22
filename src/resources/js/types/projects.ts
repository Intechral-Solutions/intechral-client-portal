export type ProjectStatus = 'active' | 'on_hold' | 'completed' | 'archived';

export type MemberRole = 'member' | 'manager';

/** One card on the projects index (ProjectPresenter::card). */
export type ProjectCardData = {
    id: number;
    name: string;
    description: string | null;
    status: ProjectStatus;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    targetDate: string | null;
    /** 0 to 100, tasks in done columns over all tasks. */
    completion: number;
    overdueCount: number;
    memberCount: number;
};

/** The project's own editable fields (ProjectPresenter::detail). */
export type ProjectDetail = {
    id: number;
    name: string;
    description: string | null;
    startDate: string | null;
    targetDate: string | null;
    status: ProjectStatus;
    /** A decimal string such as `"1200.50"`; it is never parsed into a JS number. */
    budget: string | null;
};

export type CompanyOption = { id: number; name: string };

/** Current membership as every manager sees it: no email. */
export type ProjectMemberRef = {
    id: number;
    name: string;
    role: MemberRole;
    isOwner: boolean;
};

/** The administrator-only user directory for the membership editor. */
export type MemberCandidate = { id: number; name: string; email: string };
