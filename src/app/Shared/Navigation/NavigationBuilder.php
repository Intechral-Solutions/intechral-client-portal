<?php

namespace App\Shared\Navigation;

use Illuminate\Http\Request;

final class NavigationBuilder
{
    /**
     * @return array<int, array{key: string, label: string|null, items: array<int, array<string, mixed>>}>
     */
    public function build(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        $primary = array_values(array_filter([
            $user->can('tickets.view') ? $this->item($request, 'tickets', 'Tickets', 'tickets.index', ['tickets.*']) : null,
            $user->can('projects.view') ? $this->item($request, 'projects', 'Projects', 'projects.index', ['projects.*'], 'inertia') : null,
            $this->item($request, 'tasks', 'Tasks', 'tasks.index', ['tasks.*']),
            $user->can('time.log') ? $this->item($request, 'time', 'Time', 'time.index', ['time.*'], 'inertia') : null,
            $this->billingItem($request),
            $user->can('crm.manage') ? $this->item($request, 'crm', 'CRM', 'crm.companies.index', ['crm.*']) : null,
            $user->can('cms.view') ? $this->item($request, 'pages', 'Pages', 'cms.index', ['cms.*']) : null,
        ]));

        $management = array_values(array_filter([
            $user->can('tickets.assign') ? $this->item($request, 'ticket-queue', 'Ticket Queue', 'operator.tickets.index', ['operator.tickets.*']) : null,
            $user->can('time.view_all') ? $this->item($request, 'time-reports', 'Time Reports', 'operator.time.index', ['operator.time.*'], 'inertia') : null,
            $user->can('crm.manage') ? $this->item($request, 'organizations', 'Organizations', 'organizations.index', ['organizations.*']) : null,
            $user->can('cms.edit') ? $this->item($request, 'cms-pages', 'CMS Pages', 'operator.cms.index', ['operator.cms.*']) : null,
            $user->can('users.view') ? $this->item($request, 'users', 'Users', 'users.index', ['users.*']) : null,
            $user->can('roles.view') ? $this->item($request, 'roles', 'Roles', 'roles.index', ['roles.*']) : null,
        ]));

        return array_values(array_filter([
            $primary ? ['key' => 'primary', 'label' => null, 'items' => $primary] : null,
            $management ? ['key' => 'management', 'label' => 'Manage', 'items' => $management] : null,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function billingItem(Request $request): ?array
    {
        $user = $request->user();

        if ($user->can('billing.manage')) {
            return $this->item($request, 'billing', 'Billing', 'billing.invoices.index', ['billing.*']);
        }

        if ($user->can('billing.view')) {
            return $this->item($request, 'billing', 'Billing', 'billing.client.invoices.index', ['billing.*']);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $activePatterns
     * @return array<string, mixed>
     */
    private function item(
        Request $request,
        string $key,
        string $label,
        string $routeName,
        array $activePatterns,
        string $visit = 'document',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'href' => route($routeName),
            'method' => 'get',
            'visit' => $visit,
            'activePatterns' => $activePatterns,
            'isActive' => $request->routeIs(...$activePatterns),
            'children' => [],
        ];
    }
}
