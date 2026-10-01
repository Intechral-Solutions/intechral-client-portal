<?php

namespace App\Shared\Navigation;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use LogicException;

/**
 * The single source of navigation truth (EPIC-013 L14, §12).
 *
 * `build()` returns one presentation-neutral, capability-filtered model consumed by both renderers:
 * React through `HandleInertiaRequests::share`, Blade through `App\View\Composers\ShellComposer`.
 * Navigation visibility is presentation, never authorization — every route keeps its own middleware
 * and policy, and this class only decides what an actor is offered.
 *
 * The model is split in two (§12.1, L17):
 *
 *   `context`      — CONTENT. The authorized contextual navigation inside a workspace, as ordered
 *                    sections of items with a semantic `kind`. Every presentation reads it; the
 *                    Operational shell projects it into the drawer, a later Focused shell projects
 *                    the same data into menus, tabs and view selectors. It never names a drawer,
 *                    rail or panel.
 *   `presentation` — HINTS. Family-namespaced defaults only, never destinations. A shell reads
 *                    `presentation[<its family>]` and ignores every other key. Stripping the whole
 *                    key must leave the authorized model byte-identical.
 *
 * Shape, per workspace:
 *
 *   key, label, icon, href, visit, isActive,
 *   context      => [ { key, label, kind, items: [ { key, label, href, visit, isActive, count } ] } ]
 *   presentation => { operational: { panel: 'open'|'collapsed'|null } }
 *
 * Contract notes that are easy to break:
 *
 * - Route-name patterns are never serialized. They are matching input, not client data (A1.9).
 * - A workspace's `href` is always its first surviving contextual destination, or its own single
 *   surface when it has none. A default destination therefore cannot point at a filtered-out item.
 * - Workspace patterns may be broad; contextual items use explicit route names, with
 *   most-specific-wins resolution, so the three A1.10 collisions cannot recur.
 * - `count` is reserved and always null (§12.3 rule 8). Operational and status metadata stay out of
 *   the navigation item contract.
 */
final class NavigationBuilder
{
    /**
     * @return array{currentWorkspace: string|null, workspaces: array<int, array<string, mixed>>}
     */
    public function build(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return ['currentWorkspace' => null, 'workspaces' => []];
        }

        // Declared order is the rendered order: stable, deterministic, and independent of which
        // entries a given actor may see.
        $specs = array_values(array_filter([
            $this->home(),
            $this->projects($user),
            $this->tasks($user),
            $this->helpdesk($user),
            $this->time($user),
            $this->directory($user),
            $this->finance($user),
            $this->system($user),
            $this->resources($user),
        ]));

        $activeKey = $this->resolveActiveWorkspace($request, $specs);

        return [
            'currentWorkspace' => $activeKey,
            'workspaces' => array_map(
                fn (array $spec) => $this->serialize($request, $spec, $spec['key'] === $activeKey),
                $specs,
            ),
        ];
    }

    // ── Workspaces ──────────────────────────────────────────────────────────────

    /**
     * Home is one surface with no views, so it carries no contextual navigation for ANY
     * presentation (§12.3 rule 6). Direction D §5.3's "Home: open" default describes queues,
     * decisions and watch lists that do not exist yet; emitting a panel hint for them would imply
     * an unbuilt feature.
     *
     * @return array<string, mixed>
     */
    private function home(): array
    {
        return $this->workspace(
            key: 'home',
            label: 'Home',
            icon: 'house',
            patterns: ['dashboard'],
            surface: ['href' => route('dashboard'), 'visit' => 'inertia'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function projects(User $user): ?array
    {
        if (! $user->can('projects.view')) {
            return null;
        }

        $sections = [
            $this->section('views', 'Views', ContextKind::Views, [
                $this->item('projects.all', 'All projects', route('projects.index'), 'inertia', [
                    'projects.index',
                    'projects.show',
                    'projects.board',
                    'projects.create',
                    'projects.edit',
                    'projects.milestones.index',
                    'projects.tasks.show',
                ]),
            ]),
        ];

        // Row-level access stays with Project::visibleTo / ProjectPolicy; this gate only decides
        // whether the actor is offered the create surface at all, exactly as the route does.
        if ($user->can('projects.manage')) {
            $sections[] = $this->section('actions', null, ContextKind::Actions, [
                $this->item('projects.create', 'New project', route('projects.create'), 'inertia'),
            ]);
        }

        return $this->workspace(
            key: 'projects',
            label: 'Projects',
            icon: 'folder-kanban',
            patterns: ['projects.*'],
            panel: PanelDefault::Open,
            sections: $sections,
        );
    }

    /**
     * My tasks and All tasks (EPIC-014 §9.8) are one route differentiated by a query parameter,
     * so they are matched on that parameter as well as the route name. `TaskQuery::resolveView`
     * clamps anything that is not an authorized `all` to `mine`, and "My tasks" mirrors that clamp
     * by declaring no query constraint: an actor without `tasks.view_all` asking for `view=all`
     * sees My tasks active, because My tasks is what the server served.
     *
     * "My organization" (`tasks.org`) is retired (Q5): organization is a filter inside the list.
     * Its permission, `tasks.view_org`, stays in the catalogue unused (§22 P1). Gating here is
     * permission-only, so building navigation still issues no query.
     *
     * The standalone detail route (`tasks.show`, EPIC-014 WP5) is an active route of both items, so
     * the page keeps the Tasks workspace active. It carries no `view`, so it lands on My tasks.
     *
     * @return array<string, mixed>
     */
    private function tasks(User $user): array
    {
        $views = [
            $this->item('tasks.mine', 'My tasks', route('tasks.index'), 'inertia', ['tasks.index', 'tasks.show']),
        ];

        if ($user->can('viewAll', Task::class)) {
            $views[] = $this->item(
                'tasks.all',
                'All tasks',
                route('tasks.index', ['view' => 'all']),
                'inertia',
                ['tasks.index', 'tasks.show'],
                ['view' => 'all'],
            );
        }

        return $this->workspace(
            key: 'tasks',
            label: 'Tasks',
            icon: 'list-checks',
            patterns: ['tasks.*'],
            panel: PanelDefault::Collapsed,
            sections: [$this->section('views', 'Views', ContextKind::Views, $views)],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function helpdesk(User $user): ?array
    {
        $views = [];

        if ($user->can('tickets.view')) {
            $views[] = $this->item('helpdesk.requests', 'My requests', route('tickets.index'), 'document', [
                'tickets.index',
                'tickets.show',
                'tickets.create',
            ]);
        }

        if ($user->can('tickets.assign')) {
            $views[] = $this->item('helpdesk.queue', 'Queue', route('operator.tickets.index'), 'document', [
                'operator.tickets.index',
                'operator.tickets.show',
            ]);
            $views[] = $this->item('helpdesk.reports', 'Reports', route('operator.tickets.reports'), 'document', [
                'operator.tickets.reports',
            ]);
        }

        if ($views === []) {
            return null;
        }

        return $this->workspace(
            key: 'helpdesk',
            label: 'Helpdesk',
            icon: 'life-buoy',
            patterns: ['tickets.*', 'operator.tickets.*'],
            panel: PanelDefault::Open,
            sections: [$this->section('views', 'Views', ContextKind::Views, $views)],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function time(User $user): ?array
    {
        $views = [];

        if ($user->can('time.log')) {
            $views[] = $this->item('time.mine', 'My time', route('time.index'), 'inertia', ['time.index']);
            $views[] = $this->item('time.allocation', 'Allocation', route('time.allocation'), 'inertia', ['time.allocation']);
        }

        if ($user->can('time.view_all')) {
            $views[] = $this->item('time.reports', 'Reports', route('operator.time.index'), 'inertia', ['operator.time.index']);
        }

        if ($views === []) {
            return null;
        }

        return $this->workspace(
            key: 'time',
            label: 'Time',
            icon: 'clock',
            patterns: ['time.*', 'operator.time.*'],
            panel: PanelDefault::Collapsed,
            sections: [$this->section('views', 'Views', ContextKind::Views, $views)],
        );
    }

    /**
     * `crm_companies` (a directory record) and `organizations` (a tenancy record) are two different
     * tables with two different meanings, so Directory shows both as distinct entries. Collapsing
     * them is a data migration owned by the Directory epic.
     *
     * @return array<string, mixed>|null
     */
    private function directory(User $user): ?array
    {
        if (! $user->can('crm.manage')) {
            return null;
        }

        return $this->workspace(
            key: 'directory',
            label: 'Directory',
            icon: 'contact',
            patterns: ['crm.*', 'organizations.*'],
            panel: PanelDefault::Open,
            sections: [$this->section('views', 'Views', ContextKind::Views, [
                $this->item('directory.people', 'People', route('crm.contacts.index'), 'document', [
                    'crm.contacts.index',
                    'crm.contacts.show',
                    'crm.contacts.create',
                    'crm.contacts.edit',
                ]),
                $this->item('directory.organizations', 'Organizations', route('crm.companies.index'), 'document', [
                    'crm.companies.index',
                    'crm.companies.show',
                    'crm.companies.create',
                    'crm.companies.edit',
                ]),
                $this->item('directory.portal-access', 'Portal access', route('organizations.index'), 'document', [
                    'organizations.index',
                    'organizations.show',
                ]),
            ])],
        );
    }

    /**
     * The capability branch is preserved verbatim from the pre-WP3 builder: `billing.manage` reaches
     * the operator invoice surface, and `billing.view` alone reaches the actor's own invoices. The
     * two destinations carry distinct keys so the branch is explicit rather than inferred.
     *
     * @return array<string, mixed>|null
     */
    private function finance(User $user): ?array
    {
        if ($user->can('billing.manage')) {
            $view = $this->item('finance.invoices', 'Invoices', route('billing.invoices.index'), 'document', [
                'billing.invoices.index',
                'billing.invoices.show',
                'billing.invoices.create',
                'billing.invoices.edit',
            ]);
        } elseif ($user->can('billing.view')) {
            $view = $this->item('finance.my-invoices', 'My invoices', route('billing.client.invoices.index'), 'document', [
                'billing.client.invoices.index',
                'billing.client.invoices.show',
            ]);
        } else {
            return null;
        }

        return $this->workspace(
            key: 'finance',
            label: 'Finance',
            icon: 'receipt',
            patterns: ['billing.*'],
            panel: PanelDefault::Open,
            sections: [$this->section('views', 'Views', ContextKind::Views, [$view])],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function system(User $user): ?array
    {
        $views = [];

        if ($user->can('users.view')) {
            $views[] = $this->item('system.users', 'Users', route('users.index'), 'document', [
                'users.index',
                'users.show',
            ]);
        }

        if ($user->can('roles.view')) {
            $views[] = $this->item('system.roles', 'Roles', route('roles.index'), 'document', [
                'roles.index',
                'roles.create',
                'roles.edit',
            ]);
        }

        if ($user->can('cms.edit')) {
            $views[] = $this->item('system.pages', 'Pages', route('operator.cms.index'), 'document', [
                'operator.cms.index',
                'operator.cms.create',
                'operator.cms.edit',
            ]);
        }

        if ($views === []) {
            return null;
        }

        return $this->workspace(
            key: 'system',
            label: 'System',
            icon: 'settings',
            patterns: ['users.*', 'roles.*', 'operator.cms.*'],
            panel: PanelDefault::Open,
            sections: [$this->section('views', 'Views', ContextKind::Views, $views)],
        );
    }

    /**
     * Gate G3, transitional (§11.3, confirmed in A1.6).
     *
     * `/pages` is a read-only viewer of published content, and the built-in `user` role holds
     * `cms.view` by default, so dropping it would remove every customer's access to published
     * pages. It is therefore kept as a transitional workspace gated on `cms.view AND NOT cms.edit`:
     * an actor who can edit reaches the same content through System → Pages, whose index links the
     * published view of every row, so no destination is lost. Labelled "Resources" to match the
     * surface's own heading (A1.6). The Knowledge/CMS epic retires it.
     *
     * @return array<string, mixed>|null
     */
    private function resources(User $user): ?array
    {
        if (! $user->can('cms.view') || $user->can('cms.edit')) {
            return null;
        }

        return $this->workspace(
            key: 'resources',
            label: 'Resources',
            icon: 'library',
            patterns: ['cms.*'],
            surface: ['href' => route('cms.index'), 'visit' => 'document'],
        );
    }

    // ── Assembly ────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $patterns  Workspace-level route patterns; wildcards allowed.
     * @param  array{href: string, visit: string}|null  $surface  A workspace with no contextual
     *                                                            navigation supplies its own single
     *                                                            destination here instead.
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function workspace(
        string $key,
        string $label,
        string $icon,
        array $patterns,
        ?array $surface = null,
        ?PanelDefault $panel = null,
        array $sections = [],
    ): array {
        $sections = array_values(array_filter($sections, fn (array $section) => $section['items'] !== []));

        // The destination is the first surviving contextual entry that is a destination rather than
        // an action, so a default can never resolve to a filtered-out or non-navigational item.
        $default = null;

        foreach ($sections as $section) {
            if ($section['kind'] !== ContextKind::Actions) {
                $default = $section['items'][0];

                break;
            }
        }

        // A workspace with neither a surviving contextual destination nor its own surface has no
        // permitted entry, and must have been filtered out before reaching here (§12.3 rule 7).
        $destination = $default ?? $surface ?? throw new LogicException("Workspace [{$key}] has no permitted destination.");

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'patterns' => $patterns,
            'href' => $destination['href'],
            'visit' => $destination['visit'],
            'panel' => $sections === [] ? null : $panel,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function section(string $key, ?string $label, ContextKind $kind, array $items): array
    {
        return ['key' => $key, 'label' => $label, 'kind' => $kind, 'items' => $items];
    }

    /**
     * @param  array<int, string>  $routes  Explicit route names only — no wildcards at this level
     *                                      (A1.10). An empty list can never become active, which is
     *                                      how `Actions` items stay unselected by construction.
     * @param  array<string, string>  $query  Additional exact query-parameter requirements.
     * @return array<string, mixed>
     */
    private function item(
        string $key,
        string $label,
        string $href,
        string $visit,
        array $routes = [],
        array $query = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'href' => $href,
            'visit' => $visit,
            'routes' => $routes,
            'query' => $query,
        ];
    }

    // ── Active state ────────────────────────────────────────────────────────────

    /**
     * Exactly one workspace may be active. Workspace patterns are allowed to be broad, so when more
     * than one matches, the most specific pattern wins and declaration order breaks a tie.
     *
     * @param  array<int, array<string, mixed>>  $specs
     */
    private function resolveActiveWorkspace(Request $request, array $specs): ?string
    {
        $winner = null;
        $best = -1;

        foreach ($specs as $spec) {
            foreach ($spec['patterns'] as $pattern) {
                if (! $request->routeIs($pattern)) {
                    continue;
                }

                $score = count(array_filter(explode('.', $pattern), fn (string $part) => $part !== '*'));

                if ($score > $best) {
                    $best = $score;
                    $winner = $spec['key'];
                }
            }
        }

        return $winner;
    }

    /**
     * At most one contextual item may be active, and only inside the active workspace — a route
     * that belongs to another workspace can never select an item here.
     *
     * Items declare explicit route names, so the A1.10 collisions (`/operator/tickets/reports`,
     * `/time/allocation`, `/projects/create`) cannot arise from pattern overlap. Where two items
     * legitimately share a route name and differ only by query parameter, the one with more
     * satisfied constraints wins; ties fall to declaration order.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function resolveActiveItem(Request $request, array $sections): ?string
    {
        $winner = null;
        $best = -1;

        foreach ($sections as $section) {
            if ($section['kind'] === ContextKind::Actions) {
                continue;
            }

            foreach ($section['items'] as $item) {
                foreach ($item['routes'] as $route) {
                    if (! $request->routeIs($route)) {
                        continue;
                    }

                    foreach ($item['query'] as $parameter => $expected) {
                        if ($request->query($parameter) !== $expected) {
                            continue 2;
                        }
                    }

                    $score = count(explode('.', $route)) * 10 + count($item['query']);

                    if ($score > $best) {
                        $best = $score;
                        $winner = $item['key'];
                    }
                }
            }
        }

        return $winner;
    }

    // ── Serialization ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function serialize(Request $request, array $spec, bool $isActive): array
    {
        $activeItem = $isActive ? $this->resolveActiveItem($request, $spec['sections']) : null;

        return [
            'key' => $spec['key'],
            'label' => $spec['label'],
            'icon' => $spec['icon'],
            'href' => $spec['href'],
            'visit' => $spec['visit'],
            'isActive' => $isActive,
            'context' => array_map(fn (array $section) => [
                'key' => $section['key'],
                'label' => $section['label'],
                'kind' => $section['kind']->value,
                'items' => array_map(fn (array $item) => [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'href' => $item['href'],
                    'visit' => $item['visit'],
                    'isActive' => $item['key'] === $activeItem,
                    // Reserved (§12.3 rule 8). Operational and status metadata do not belong to
                    // the navigation item contract; this slot exists so a later epic can add
                    // counts without changing the shape.
                    'count' => null,
                ], $section['items']),
            ], $spec['sections']),
            'presentation' => [
                'operational' => ['panel' => $spec['panel']?->value],
            ],
        ];
    }
}
