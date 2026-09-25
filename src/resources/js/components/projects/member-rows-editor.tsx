import { Plus, Trash2 } from 'lucide-react';
import { useRef } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { MemberCandidate, MemberRole } from '@/types/projects';

/** One editable member row. `key` is client-only identity and never leaves the browser. */
export type MemberRow = { key: string; user_id: number | ''; role: MemberRole };

let rowCounter = 0;

/**
 * A stable React identity for a row. A counter rather than crypto.randomUUID(): that API is
 * missing in insecure contexts (plain http on a non-localhost host), and identity only has to be
 * unique within the page.
 */
export function newMemberRow(row: Partial<Omit<MemberRow, 'key'>> = {}): MemberRow {
    rowCounter += 1;

    return { key: `member-row-${rowCounter}`, user_id: '', role: 'member', ...row };
}

/** The rows as the server expects them: client keys stripped. */
export function toMemberPayload(rows: MemberRow[]) {
    return rows.map(({ user_id, role }) => ({ user_id, role }));
}

/** The first row error (in row order) so a failed submit can focus it. */
export function firstMemberErrorField(errors: Record<string, string | undefined>, count: number) {
    for (let index = 0; index < count; index += 1) {
        if (errors[`members.${index}.user_id`]) return { index, field: 'user' as const };
        if (errors[`members.${index}.role`]) return { index, field: 'role' as const };
    }

    return null;
}

type MemberRowsEditorProps = {
    idPrefix: string;
    /**
     * The creator: always a manager, never removable (the server re-adds them whatever is sent).
     * Shown locked above the editable rows, so it is not one of `rows`.
     */
    owner: { id: number; name: string } | null;
    rows: MemberRow[];
    onChange: (rows: MemberRow[]) => void;
    /** The server-provided directory. Present only for actors allowed to manage membership. */
    candidates: MemberCandidate[];
    /** Form errors; nested keys look like `members.0.user_id`. */
    errors: Record<string, string | undefined>;
};

export function MemberRowsEditor({
    idPrefix,
    owner,
    rows,
    onChange,
    candidates,
    errors,
}: MemberRowsEditorProps) {
    const addButton = useRef<HTMLButtonElement>(null);
    // The key of a row just added: its select takes focus when it mounts (see the ref callback).
    const focusOnMount = useRef<string | null>(null);

    const taken = new Set<number>(rows.flatMap((row) => (row.user_id === '' ? [] : [row.user_id])));
    const remaining = candidates.filter(
        (candidate) => candidate.id !== owner?.id && !taken.has(candidate.id),
    );

    function update(key: string, patch: Partial<Omit<MemberRow, 'key'>>) {
        onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    }

    function add() {
        const row = newMemberRow();
        focusOnMount.current = row.key;
        onChange([...rows, row]);
    }

    function remove(key: string) {
        onChange(rows.filter((row) => row.key !== key));
        // The removed row's controls are gone; land somewhere predictable.
        window.requestAnimationFrame(() => addButton.current?.focus());
    }

    return (
        <div className="space-y-4">
            {owner ? (
                <div className="flex items-center justify-between gap-3 rounded-md border border-border bg-muted/40 px-3 py-2.5 text-sm">
                    <span className="min-w-0 truncate font-medium">{owner.name}</span>
                    <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        Manager
                        <Badge variant="info">Owner</Badge>
                    </span>
                </div>
            ) : null}

            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">No other members yet.</p>
            ) : (
                <ul className="space-y-4">
                    {rows.map((row, index) => {
                        const number = index + 1;
                        const userId = `${idPrefix}-${row.key}-user`;
                        const roleId = `${idPrefix}-${row.key}-role`;
                        const userError = errors[`members.${index}.user_id`];
                        const roleError = errors[`members.${index}.role`];
                        const chosen = candidates.find((candidate) => candidate.id === row.user_id);
                        const options = candidates.filter(
                            (candidate) =>
                                candidate.id !== owner?.id &&
                                (candidate.id === row.user_id || !taken.has(candidate.id)),
                        );

                        return (
                            <li key={row.key} className="grid gap-3 sm:grid-cols-[1fr_10rem_auto]">
                                <div className="space-y-2">
                                    <Label htmlFor={userId}>Member {number}</Label>
                                    <NativeSelect
                                        id={userId}
                                        ref={(element) => {
                                            if (element && focusOnMount.current === row.key) {
                                                focusOnMount.current = null;
                                                element.focus();
                                            }
                                        }}
                                        className="w-full"
                                        value={row.user_id}
                                        onChange={(event) =>
                                            update(row.key, {
                                                user_id:
                                                    event.target.value === ''
                                                        ? ''
                                                        : Number(event.target.value),
                                            })
                                        }
                                        aria-invalid={Boolean(userError)}
                                        aria-describedby={userError ? `${userId}-error` : undefined}
                                    >
                                        <option value="">Select a user</option>
                                        {options.map((candidate) => (
                                            <option key={candidate.id} value={candidate.id}>
                                                {candidate.name} ({candidate.email})
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <FormFieldError id={`${userId}-error`} message={userError} />
                                </div>
                                <div className="space-y-2">
                                    {/* The visible label just says "Role": row position already reads
                                        "Member 1", "Member 2", ... so repeating it here is noise. The
                                        select's own aria-label disambiguates it for assistive tech,
                                        set directly rather than folded into the <label> text: Chromium
                                        does not exclude aria-hidden text from a <label>'s computed name
                                        (unlike the accname algorithm jsdom/Testing Library follow), so
                                        an aria-hidden "Role" plus a visually-hidden "Role for member N"
                                        here previously produced the real browser's decision to include
                                        the visible text after all, giving a duplicated
                                        "RoleRole for member N" and swallowing focus-order lookups. */}
                                    <Label htmlFor={roleId}>Role</Label>
                                    <NativeSelect
                                        id={roleId}
                                        className="w-full"
                                        value={row.role}
                                        onChange={(event) =>
                                            update(row.key, {
                                                role: event.target.value as MemberRole,
                                            })
                                        }
                                        aria-label={`Role for member ${number}`}
                                        aria-invalid={Boolean(roleError)}
                                        aria-describedby={roleError ? `${roleId}-error` : undefined}
                                    >
                                        <option value="member">Member</option>
                                        <option value="manager">Manager</option>
                                    </NativeSelect>
                                    <FormFieldError id={`${roleId}-error`} message={roleError} />
                                </div>
                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => remove(row.key)}
                                        aria-label={`Remove ${chosen ? chosen.name : `member ${number}`}`}
                                    >
                                        <Trash2 aria-hidden="true" />
                                        Remove
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            <FormFieldError id={`${idPrefix}-members-error`} message={errors.members} />

            <Button
                ref={addButton}
                type="button"
                variant="outline"
                onClick={add}
                disabled={remaining.length === 0}
            >
                <Plus aria-hidden="true" />
                Add member
            </Button>
        </div>
    );
}
