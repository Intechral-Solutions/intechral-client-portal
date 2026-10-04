import { asId, idOptions, ORDERS, SEARCH_LIMIT } from '@/components/tasks/task-filter-bar';
import type { ProjectTaskListPatch } from '@/components/tasks/task-list-query';
import { FilterChip } from '@/components/ui/chip';
import {
    FilterBar,
    FilterField,
    FilterSearch,
    FilterToggleGroup,
} from '@/components/ui/filter-bar';
import { NativeSelect } from '@/components/ui/native-select';
import type {
    ProjectTaskFilterOptions,
    ProjectTaskFilters,
    ProjectTaskSort,
    TaskListFilters,
} from '@/types/tasks';

/**
 * EPIC-015 WP3 — the project Tasks tab's composition of the generic `FilterBar` (§13.2), in the
 * Tasks workspace's grammar (`TaskFilterBar`): search, completion, priority, due, then the two
 * project filters, milestone and assignee, then sort and order. There is no project, kind or
 * organization control: the project is fixed and every row is one of its board tasks.
 *
 * Like `TaskFilterBar` it holds no filter state, builds no URL and fetches nothing; it reports one
 * patch per change and renders what the server offered. A milestone is always one the project offers
 * (the server drops any other), so it always has a label. A well-formed assignee id the server did not
 * offer is still applied as a narrowing filter, shown as the type-only "Assignee filter", never an id
 * or a looked-up name (owner decision B, EPIC-014 WP3).
 */
type Props = {
    filters: ProjectTaskFilters;
    filterOptions: ProjectTaskFilterOptions;
    sort: ProjectTaskSort;
    onChange: (patch: ProjectTaskListPatch) => void;
    onClear: () => void;
};

type Named = { id: number; name: string };

export function ProjectTaskFilterBar({ filters, filterOptions, sort, onChange, onClear }: Props) {
    const label = <T extends string>(options: { value: T; label: string }[], value: T) =>
        options.find((option) => option.value === value)?.label ?? value;
    const named = (options: Named[], id: number) =>
        options.find((option) => option.id === id)?.name;

    const milestoneName =
        filters.milestone !== null ? named(filterOptions.milestones, filters.milestone) : undefined;
    const assigneeName =
        typeof filters.assignee === 'number'
            ? named(filterOptions.assignees, filters.assignee)
            : undefined;

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
        filters.milestone !== null && (
            <FilterChip
                key="milestone"
                label={
                    milestoneName !== undefined ? `Milestone: ${milestoneName}` : 'Milestone filter'
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
                        : assigneeName !== undefined
                          ? `Assignee: ${assigneeName}`
                          : 'Assignee filter'
                }
                onRemove={() => onChange({ assignee: null })}
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

            {/* A project with no milestones has nothing to choose between, so no empty control. */}
            {filterOptions.milestones.length > 0 || filters.milestone !== null ? (
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

            <FilterField label="Assignee">
                <NativeSelect
                    className="w-full"
                    value={filters.assignee ?? ''}
                    onChange={(event) =>
                        onChange({
                            assignee:
                                event.target.value === 'none' ? 'none' : asId(event.target.value),
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

            <FilterField label="Sort by">
                <NativeSelect
                    className="w-full"
                    value={sort.by}
                    onChange={(event) =>
                        onChange({ sort: event.target.value as ProjectTaskSort['by'] })
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
                        onChange({ dir: event.target.value as ProjectTaskSort['dir'] })
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
