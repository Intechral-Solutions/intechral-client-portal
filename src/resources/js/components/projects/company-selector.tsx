import { FormFieldError } from '@/components/forms/form-field-error';
import type { CompanyOption } from '@/types/projects';

type CompanySelectorProps = {
    idPrefix: string;
    companies: CompanyOption[];
    selected: number[];
    onChange: (selected: number[]) => void;
    /** Any validation message for the `companies` field or one of its entries. */
    error?: string;
};

/**
 * Company checkboxes with the informational copy. Native checkboxes: labelled, keyboard and
 * screen-reader friendly with no extra machinery. The link is metadata (EPIC-011E D2).
 */
export function CompanySelector({
    idPrefix,
    companies,
    selected,
    onChange,
    error,
}: CompanySelectorProps) {
    const hintId = `${idPrefix}-companies-hint`;
    const errorId = `${idPrefix}-companies-error`;

    return (
        <fieldset className="space-y-3" aria-describedby={error ? `${hintId} ${errorId}` : hintId}>
            <legend className="sr-only">Linked companies</legend>
            <p id={hintId} className="text-sm text-text-secondary">
                Linking a company records the client relationship. It does not grant that
                company&apos;s organization members access to the project; only project members (and
                administrators) can open it.
            </p>
            <div className="space-y-2">
                {companies.map((company) => (
                    <label key={company.id} className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-input accent-accent"
                            checked={selected.includes(company.id)}
                            onChange={(event) =>
                                onChange(
                                    event.target.checked
                                        ? [...selected, company.id]
                                        : selected.filter((id) => id !== company.id),
                                )
                            }
                        />
                        {company.name}
                    </label>
                ))}
            </div>
            <FormFieldError id={errorId} message={error} />
        </fieldset>
    );
}
