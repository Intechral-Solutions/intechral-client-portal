<?php

namespace App\Queries;

use App\Models\Task;
use InvalidArgumentException;

/**
 * The Tasks list's URL state after normalization (EPIC-014 §9.4–§9.6): the only form in which
 * request input reaches TaskQuery. Every value is already clamped to a closed vocabulary or to an
 * id the actor was offered, so TaskQuery never sees raw input. An unknown value is dropped (the
 * filter is simply off), never a validation redirect: this is navigational state that a stale or
 * edited URL must not bounce through.
 *
 * Id-valued filters (project, organization, assignee) are kept whenever they are well formed (a
 * positive integer), whether or not the actor was offered them (owner decision, WP3 audit): the
 * authorized set is established first, so an id can only narrow it, and an invisible, nonexistent
 * or zero-row id all yield zero rows with nothing to tell them apart. Labels come only from the
 * filter options, never from fetching the named record. A malformed id is dropped. A milestone is
 * the exception: it is kept only when it is one of the selected project's offered milestones, so
 * it can never be applied apart from, or probed through, its project.
 *
 * Project scope (EPIC-015 §13.2, `fromProjectInput`) uses the same vocabulary and normalizers with a
 * fixed project: `completion`, `priority`, `due`, `q`, `assignee` and `milestone` (one of the project's
 * own, else dropped), plus the project-only `board` sort. `view`, `kind`, `project` and `organization`
 * are not part of it and are dropped whatever they say.
 */
final class TaskListState
{
    public const COMPLETIONS = ['open', 'done', 'any'];

    public const DUE_PRESETS = ['overdue', 'today', 'next7', 'none'];

    public const KINDS = ['project', 'standalone'];

    /** Each sort with the direction a bare `?sort=` implies (§9.6). */
    public const SORTS = ['due' => 'asc', 'priority' => 'desc', 'title' => 'asc', 'updated' => 'desc', 'created' => 'desc'];

    /** Project scope adds the board's own order (EPIC-015 §13.2); nowhere else offers it. */
    public const PROJECT_SORTS = self::SORTS + ['board' => 'asc'];

    /** Search is a short title fragment, not a document (§9.6). */
    public const SEARCH_LIMIT = 100;

    public readonly string $direction;

    /**
     * @param  array<int, string>  $priorities  a subset of Task::PRIORITIES
     * @param  int|'none'|null  $assignee
     */
    public function __construct(
        public readonly string $view,
        public readonly string $completion = 'open',
        public readonly array $priorities = [],
        public readonly ?string $due = null,
        public readonly ?string $kind = null,
        public readonly ?int $project = null,
        public readonly ?int $milestone = null,
        public readonly int|string|null $assignee = null,
        public readonly ?int $organization = null,
        public readonly string $search = '',
        public readonly string $sort = 'due',
        ?string $direction = null,
    ) {
        $projectScope = $view === TaskQuery::VIEW_PROJECT;
        $sorts = $projectScope ? self::PROJECT_SORTS : self::SORTS;

        // Constructed directly (tests, internal callers) the vocabulary is still enforced, so no
        // unchecked string can reach a column or ORDER BY expression, and project scope never
        // carries a global-only filter.
        if (! ($projectScope || in_array($view, TaskQuery::VIEWS, true))
            || ($projectScope && ($kind !== null || $project !== null || $organization !== null))
            || ! in_array($completion, self::COMPLETIONS, true)
            || array_diff($priorities, Task::PRIORITIES) !== []
            || ($due !== null && ! in_array($due, self::DUE_PRESETS, true))
            || ($kind !== null && ! in_array($kind, self::KINDS, true))
            || (is_string($assignee) && $assignee !== 'none')
            || ! array_key_exists($sort, $sorts)
            || ($direction !== null && ! in_array($direction, ['asc', 'desc'], true))) {
            throw new InvalidArgumentException('Task list state outside its vocabulary.');
        }

        $this->direction = $direction ?? $sorts[$sort];
    }

    /**
     * @param  array<string, mixed>  $input  the raw query string
     * @param  array{milestones: array<int, array{id: int}>}  $options  only the milestone options are consulted
     */
    public static function fromInput(array $input, string $view, array $options): self
    {
        $offered = fn (string $key) => array_column($options[$key], 'id');

        $project = self::id($input['project'] ?? null);
        $sort = self::oneOf($input['sort'] ?? null, array_keys(self::SORTS)) ?? 'due';

        return new self(
            view: $view,
            completion: self::oneOf($input['completion'] ?? null, self::COMPLETIONS) ?? 'open',
            priorities: self::priorities($input['priority'] ?? null),
            due: self::oneOf($input['due'] ?? null, self::DUE_PRESETS),
            kind: self::oneOf($input['kind'] ?? null, self::KINDS),
            project: $project,
            // Offered only for the one selected, visible project (§9.5): any other milestone is dropped.
            milestone: $project === null ? null : self::offeredId($input['milestone'] ?? null, $offered('milestones')),
            // All Tasks only: My Tasks is already scoped to the viewer and has no assignee filter.
            assignee: $view !== TaskQuery::VIEW_ALL ? null : (($input['assignee'] ?? null) === 'none'
                ? 'none'
                : self::id($input['assignee'] ?? null)),
            organization: self::id($input['organization'] ?? null),
            search: is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, self::SEARCH_LIMIT) : '',
            sort: $sort,
            direction: self::oneOf($input['dir'] ?? null, ['asc', 'desc']),
        );
    }

    /**
     * The project Tasks tab's state (EPIC-015 §13.2). The project is the route's, so no `project`
     * parameter is read; a milestone is kept only when it is one of that project's offered
     * milestones (a foreign, nonexistent or malformed id is dropped, never applied); an assignee is
     * kept whenever it is well formed or `none`, exactly as in All Tasks, so it can only narrow.
     *
     * @param  array<string, mixed>  $input  the raw query string
     * @param  array{milestones: array<int, array{id: int}>}  $options  the project's filter options
     */
    public static function fromProjectInput(array $input, array $options): self
    {
        return new self(
            view: TaskQuery::VIEW_PROJECT,
            completion: self::oneOf($input['completion'] ?? null, self::COMPLETIONS) ?? 'open',
            priorities: self::priorities($input['priority'] ?? null),
            due: self::oneOf($input['due'] ?? null, self::DUE_PRESETS),
            milestone: self::offeredId($input['milestone'] ?? null, array_column($options['milestones'], 'id')),
            assignee: ($input['assignee'] ?? null) === 'none' ? 'none' : self::id($input['assignee'] ?? null),
            search: is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, self::SEARCH_LIMIT) : '',
            sort: self::oneOf($input['sort'] ?? null, array_keys(self::PROJECT_SORTS)) ?? 'due',
            direction: self::oneOf($input['dir'] ?? null, ['asc', 'desc']),
        );
    }

    /**
     * The project tab's echo of its filters: the project-scope vocabulary only.
     *
     * @return array{completion: string, priority: array<int, string>, due: string|null, milestone: int|null, assignee: int|string|null, q: string}
     */
    public function projectFilters(): array
    {
        return array_intersect_key($this->filters(), array_flip(['completion', 'priority', 'due', 'milestone', 'assignee', 'q']));
    }

    /**
     * The normalized echo of the filters (§13.3), the shape the page's filter controls read.
     *
     * @return array{completion: string, priority: array<int, string>, due: string|null, kind: string|null, project: int|null, milestone: int|null, assignee: int|string|null, organization: int|null, q: string}
     */
    public function filters(): array
    {
        return [
            'completion' => $this->completion,
            'priority' => $this->priorities,
            'due' => $this->due,
            'kind' => $this->kind,
            'project' => $this->project,
            'milestone' => $this->milestone,
            'assignee' => $this->assignee,
            'organization' => $this->organization,
            'q' => $this->search,
        ];
    }

    /**
     * The canonical query string of this state, defaults omitted: what pagination links carry, so
     * they reflect the normalized contract rather than whatever the request happened to contain.
     * The view is not part of it; the URL's own `view` is added by the caller.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        $query = [
            'completion' => $this->completion !== 'open' ? $this->completion : null,
            'priority' => $this->priorities !== [] ? $this->priorities : null,
            'due' => $this->due,
            'kind' => $this->kind,
            'project' => $this->project,
            'milestone' => $this->milestone,
            'assignee' => $this->assignee,
            'organization' => $this->organization,
            'q' => $this->search !== '' ? $this->search : null,
        ];

        if ($this->sort !== 'due' || $this->direction !== self::SORTS['due']) {
            $query['sort'] = $this->sort;
            $query['dir'] = $this->direction;
        }

        return array_filter($query, fn ($value) => $value !== null);
    }

    /** @return array{by: string, dir: string} */
    public function sort(): array
    {
        return ['by' => $this->sort, 'dir' => $this->direction];
    }

    // ── Normalizers ─────────────────────────────────────────────────────────

    /** @param  array<int, string>  $allowed */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** One value or a list; unknown values dropped; returned in vocabulary order, deduplicated. */
    private static function priorities(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_intersect(Task::PRIORITIES, array_filter($values, 'is_string')));
    }

    /** @param  array<int, int>  $offered */
    private static function offeredId(mixed $value, array $offered): ?int
    {
        $id = self::id($value);

        return $id !== null && in_array($id, $offered, true) ? $id : null;
    }

    /** The strict id parse, for callers that must agree with `fromInput` on what is well formed. */
    public static function idFrom(mixed $value): ?int
    {
        return self::id($value);
    }

    /** A well-formed id: a positive integer, as an int or a digit string that fits one. */
    private static function id(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            // Digits only, so a decimal, sign, space or array never parses; refuse integer overflow.
            $trimmed = ltrim($value, '0');
            $value = $trimmed !== '' && (string) (int) $trimmed === $trimmed ? (int) $trimmed : null;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
