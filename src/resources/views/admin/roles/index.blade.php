@extends('layouts.app', ['title' => 'Roles'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Roles</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Manage roles and their permissions.</p>
        </div>
        @can('roles.manage')
        <x-ui.button :href="route('roles.create')">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            New Role
        </x-ui.button>
        @endcan
    </div>

    {{-- Flash status --}}
    @if (session('status'))
    <x-ui.alert variant="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- Errors --}}
    @if ($errors->any())
    <x-ui.alert variant="danger" class="mb-6">{{ $errors->first() }}</x-ui.alert>
    @endif

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border" style="border-color: var(--border-base); background-color: var(--surface-base);">
        <table class="min-w-full divide-y" style="border-color: var(--border-subtle);">
            <thead>
                <tr style="background-color: var(--surface-elevated);">
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Role</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Users</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Type</th>
                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="border-color: var(--border-subtle);">
                @forelse ($roles as $role)
                @php $builtIn = in_array($role->name, ['operator', 'user']); @endphp
                <tr class="transition-colors hover:legacy-bg-surface">
                    <td class="px-6 py-4">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $role->name }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                        {{ $role->users_count }}
                    </td>
                    <td class="px-6 py-4">
                        <x-ui.tag>{{ $builtIn ? 'Built-in' : 'Custom' }}</x-ui.tag>
                    </td>
                    <td class="px-6 py-4 text-right text-sm">
                        <div class="flex items-center justify-end gap-3">
                            @can('roles.manage')
                            <x-ui.link :href="route('roles.edit', $role)" class="font-medium">Edit</x-ui.link>
                            @endcan
                            @can('roles.admin')
                            @if (! $builtIn)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}"
                                  onsubmit="return confirm('Delete role \'{{ addslashes($role->name) }}\'?')">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Delete</x-ui.button>
                            </form>
                            @endif
                            @endcan
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-6 py-10 text-center text-sm" style="color: var(--text-secondary);">
                        No roles found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
