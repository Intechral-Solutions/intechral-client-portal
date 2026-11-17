@extends('layouts.app', ['title' => 'Users'])

@section('content')
<div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-text">Users</h1>
            <p class="mt-1 text-sm text-text-secondary">Manage platform users and their roles.</p>
        </div>
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <x-ui.alert variant="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- Search and filters. All three are plain GET parameters and travel with the pagination links. --}}
    @php($filtered = $search !== '' || $type !== null || $status !== null)
    <form method="GET" action="{{ route('users.index') }}" class="mb-2">
        <div class="flex flex-wrap gap-2">
            <x-ui.input type="search" name="search" :value="$search"
                        placeholder="Search by name or email&hellip;" class="w-full max-w-sm" />
            <x-ui.select name="type" aria-label="Account type">
                <option value="">All account types</option>
                <option value="operator" @selected($type === 'operator')>Operators</option>
                <option value="customer" @selected($type === 'customer')>Customers</option>
            </x-ui.select>
            <x-ui.select name="status" aria-label="Account status">
                <option value="">Any status</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="suspended" @selected($status === 'suspended')>Suspended</option>
            </x-ui.select>
            <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
            @if ($filtered)
            <x-ui.button :href="route('users.index')" variant="secondary">Clear</x-ui.button>
            @endif
        </div>
    </form>
    <p class="mb-6 text-xs text-text-muted">An operator holds the portal.operator permission; every other account is a customer account.</p>

    {{-- Table --}}
    {{-- A horizontally scrollable region (DIR-1): the table is wider than a phone, so the card scrolls inside itself, never the page.
         It is a named, focusable region so a keyboard user can reach and scroll it, with the shared Direction D focus ring. --}}
    <div role="region" tabindex="0" aria-label="Users list" class="overflow-x-auto rounded-lg border border-rule bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        <table class="min-w-full divide-y divide-rule">
            <thead>
                <tr class="bg-surface-sunken">
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Email</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Roles</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Organizations</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Status</th>
                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($users as $user)
                <tr class="transition-colors hover:bg-surface-hover">
                    <td class="px-6 py-4">
                        <span class="font-medium text-text">{{ $user->name }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-text-secondary">
                        {{ $user->email }}
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($user->roles as $role)
                            <x-ui.tag>{{ $role->name }}</x-ui.tag>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-text-secondary">
                        @php($memberOf = $memberships->get($user->id, collect()))
                        @if ($memberOf->isEmpty())
                        <span class="text-text-muted">&mdash;</span>
                        @else
                        <ul class="space-y-0.5">
                            @foreach ($memberOf->take(5) as $membership)
                            <li>
                                @can('crm.manage')
                                <x-ui.link variant="row" :href="route('organizations.show', $membership->organization_id)">{{ $membership->name }}</x-ui.link>
                                @else
                                {{ $membership->name }}
                                @endcan
                            </li>
                            @endforeach
                            @if ($memberOf->count() > 5)
                            <li class="text-xs text-text-muted">+ {{ $memberOf->count() - 5 }} more</li>
                            @endif
                        </ul>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if ($user->isSuspended())
                        <x-ui.status tone="danger">Suspended</x-ui.status>
                        @else
                        <x-ui.status tone="success">Active</x-ui.status>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-right text-sm">
                        <x-ui.link :href="route('users.show', $user)" class="font-medium">View</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-10 text-center text-sm text-text-secondary">
                        No users found{{ $filtered ? ' matching these filters' : '' }}.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if ($users->hasPages())
    <div class="mt-6">
        {{ $users->links() }}
    </div>
    @endif

</div>

@endsection
