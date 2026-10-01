import { FilterChip } from '@/components/ui/chip';
import {
    FilterBar,
    FilterField,
    FilterSearch,
    FilterToggleGroup,
} from '@/components/ui/filter-bar';
import { NativeSelect } from '@/components/ui/native-select';
import type { TaskListPatch } from '@/components/tasks/task-list-query';
import type { TaskFilterOptions, TaskListFilters, TaskListSort, TaskView } from '@/types/tasks';

/**
 * EPIC-014 WP4 — the Tasks composition of the generic `FilterBar` (§14.1).
 *
 * It renders only what the server's contract makes available and reports **one patch per change**; it
 * holds no filter state and builds no URL (the page turns a patch into a `/tasks` visit, and the
 * server's normalized echo comes back as `filters`). Options, labels and the sort vocabulary all come
 * from `filterOptions` (INV-19); the browser fetches nothing and reconstructs no authorization: an
 * assignee control appears only in All Tasks because the server offers assignees only there, and no
 * ticket option exists because the server never offers one (Q6).
 *
 * **An id the server applied but did not label (owner decision B, WP3).** A well-formed project,
 * organization or assignee id stays active even when it is not among the offered options (a project
 * with no row in this view, a company the viewer's CRM scope hides, an id that is simply wrong). It is
 * shown as a type-only chip and select entry — "Project filter", never an id or a name — and is
 * cleared like any other. The browser neither looks the record up nor guesses at it.
 */
type Props = {
    view: TaskView;
    filters: TaskListFilters;
    filterOptions: TaskFilterOptions;
    sort: TaskListSort;
    onChange: (patch: TaskListPatch) => void;
    onClear: () => void;
};

const ORDERS = [
    { value: 'asc', label: 'Ascending' },
    { value: 'desc', label: 'Descending' },
] as const;

const SEARCH_LIMIT = 100;

type Named = { id: number; name: string };

/** The options for an id-valued select, plus a type-only entry when the active id has no label. */
function idOptions(options: Named[], active: number | null, unlabeled: string) {
    const known = active === null || options.some((option) => option.id === active);

    return (
        <>
            {options.map((option) => (
                <option key={option.id} value={option.id}>
                    {option.name}
                </option>
            ))}
            {known ? null : <option value={active}>{unlabeled}</option>}
        </>
    );
}

const asId = (value: string) => (value === '' ? null : Number(value));

export function TaskFilterBar({ view, filters, filterOptions, sort, onChange, onClear }: Props) {
    const label = <T extends string>(options: { value: T; label: string }[], value: T) =>
        options.find((option) => option.value === value)?.label ?? value;
    const named = (options: Named[], id: number) =>
        options.find((option) => option.id === id)?.name;

    const showMilestone =
        filters.project !== null &&
        (filterOptions.milestones.length > 0 || filters.milestone !== null);
    const showOrganization =
        filterOptions.organizations.length > 0 || filters.organization !== null;

    const chips = [
        filters.completion !== 'open' && (
            <FilterChip
                key="completion"
                label={`Completion: ${label(filterOptions.completion, filters.completion)}`}
                onRemove={() => onChange({ completion: 'open' })}
            />
        ),
        ...filters.priority.map((priority) => (
            <FilterChip
                key={`priority-${priority}`}
                label={`Priority: ${label(filterOptions.priorities, priority)}`}
                onRemove={() =>
                    onChange({ priority: filters.priority.filter((value) => value !== priority) })
                }
            />
        )),
        filters.due && (
            <FilterChip
                key="due"
                label={`Due: ${label(filterOptions.due, filters.due)}`}
                onRemove={() => onChange({ due: null })}
            />
        ),
        filters.kind && (
            <FilterChip
                key="kind"
                label={`Kind: ${label(filterOptions.kinds, filters.kind)}`}
                onRemove={() => onChange({ kind: null })}
            />
        ),
        filters.project !== null && (
            <FilterChip
                key="project"
                label={
                    named(filterOptions.projects, filters.project) !== undefined
                        ? `Project: ${named(filterOptions.projects, filters.project)}`
                        : 'Project filter'
                }
                onRemove={() => onChange({ project: null })}
            />
        ),
        filters.milestone !== null && filters.project !== null && (
            <FilterChip
                key="milestone"
                label={
                    named(filterOptions.milestones, filters.milestone) !== undefined
                        ? `Milestone: ${named(filterOptions.milestones, filters.milestone)}`
                        : 'Milestone filter'
                }
                onRemove={() => onChange({ milestone: null })}
            />
        ),
        filters.assignee !== null && (
            <FilterChip
                key="assignee"
                label={
                    filters.assignee === 'none'
                        ? 'Assignee: Unassigned'
                        : named(filterOptions.assignees, filters.assignee) !== undefined
                          ? `Assignee: ${named(filterOptions.assignees, filters.assignee)}`
                          : 'Assignee filter'
                }
                onRemove={() => onChange({ assignee: null })}
            />
        ),
        filters.organization !== null && (
            <FilterChip
                key="organization"
                label={
                    named(filterOptions.organizations, filters.organization) !== undefined
                        ? `Organization: ${named(filterOptions.organizations, filters.organization)}`
                        : 'Organization filter'
                }
                onRemove={() => onChange({ organization: null })}
            />
        ),
    ].filter(Boolean);

    return (
        <FilterBar
            label="Filter tasks"
            chips={chips}
            onClear={onClear}
            clearable={filters.q !== ''}
        >
            <FilterSearch
                label="Search tasks"
                value={filters.q}
                maxLength={SEARCH_LIMIT}
                onSearch={(q) => onChange({ q })}
            />

            <FilterField label="Completion">
                <NativeSelect
                    className="w-full"
                    value={filters.completion}
                    onChange={(event) =>
                        onChange({
                            completion: event.target.value as TaskListFilters['completion'],
                        })
                    }
                >
                    {filterOptions.completion.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
            </FilterField>

            <FilterToggleGroup
                label="Priority"
                options={filterOptions.priorities}
                selected={filters.priority}
                onChange={(priority) => onChange({ priority })}
            />

            <FilterField label="Due">
                <NativeSelect
                    className="w-full"
                    value={filters.due ?? ''}
                    onChange={(event) =>
                        onChange({ due: (event.target.value || null) as TaskListFilters['due'] })
                    }
                >
                    <option value="">Any due date</option>
                    {filterOptions.due.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
            </FilterField>

            <FilterField label="Kind">
                <NativeSelect
                    className="w-full"
                    value={filters.kind ?? ''}
                    onChange={(event) =>
                        onChange({ kind: (event.target.value || null) as TaskListFilters['kind'] })
                    }
                >
                    <option value="">All kinds</option>
                    {filterOptions.kinds.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
            </FilterField>

            <FilterField label="Project">
                <NativeSelect
                    className="w-full"
                    value={filters.project ?? ''}
                    onChange={(event) => onChange({ project: asId(event.target.value) })}
                >
                    <option value="">All projects</option>
                    {idOptions(filterOptions.projects, filters.project, 'Project filter')}
                </NativeSelect>
            </FilterField>

            {showMilestone ? (
                <FilterField label="Milestone">
                    <NativeSelect
                        className="w-full"
                        value={filters.milestone ?? ''}
                        onChange={(event) => onChange({ milestone: asId(event.target.value) })}
                    >
                        <option value="">All milestones</option>
                        {idOptions(filterOptions.milestones, filters.milestone, 'Milestone filter')}
                    </NativeSelect>
                </FilterField>
            ) : null}

            {view === 'all' ? (
                <FilterField label="Assignee">
                    <NativeSelect
                        className="w-full"
                        value={filters.assignee ?? ''}
                        onChange={(event) =>
                            onChange({
                                assignee:
                                    event.target.value === 'none'
                                        ? 'none'
                                        : asId(event.target.value),
                            })
                        }
                    >
                        <option value="">All assignees</option>
                        <option value="none">Unassigned</option>
                        {idOptions(
                            filterOptions.assignees,
                            typeof filters.assignee === 'number' ? filters.assignee : null,
                            'Assignee filter',
                        )}
                    </NativeSelect>
                </FilterField>
            ) : null}

            {showOrganization ? (
                <FilterField label="Organization">
                    <NativeSelect
                        className="w-full"
                        value={filters.organization ?? ''}
                        onChange={(event) => onChange({ organization: asId(event.target.value) })}
                    >
                        <option value="">All organizations</option>
                        {idOptions(
                            filterOptions.organizations,
                            filters.organization,
                            'Organization filter',
                        )}
                    </NativeSelect>
                </FilterField>
            ) : null}

            <FilterField label="Sort by">
                <NativeSelect
                    className="w-full"
                    value={sort.by}
                    onChange={(event) =>
                        onChange({ sort: event.target.value as TaskListSort['by'] })
                    }
                >
                    {filterOptions.sorts.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
            </FilterField>

            <FilterField label="Order">
                <NativeSelect
                    className="w-full"
                    value={sort.dir}
                    onChange={(event) =>
                        onChange({ dir: event.target.value as TaskListSort['dir'] })
                    }
                >
                    {ORDERS.map((order) => (
                        <option key={order.value} value={order.value}>
                            {order.label}
                        </option>
                    ))}
                </NativeSelect>
            </FilterField>
        </FilterBar>
    );
}
