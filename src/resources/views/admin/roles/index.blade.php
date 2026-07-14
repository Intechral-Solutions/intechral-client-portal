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
        <a href="{{ route('roles.create') }}"
           class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
           style="background-color: var(--accent); color: #fff;">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            New Role
        </a>
        @endcan
    </div>

    {{-- Flash status --}}
    @if (session('status'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">
        {{ session('status') }}
    </div>
    @endif

    {{-- Errors --}}
    @if ($errors->any())
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-danger); border-color: var(--border-danger); color: var(--text-danger);"
         role="alert">
        {{ $errors->first() }}
    </div>
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
                <tr class="transition-colors hover:bg-surface">
                    <td class="px-6 py-4">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $role->name }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                        {{ $role->users_count }}
                    </td>
                    <td class="px-6 py-4">
                        @if ($builtIn)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                              style="background-color: var(--surface-accent); color: var(--accent);">
                            Built-in
                        </span>
                        @else
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                              style="background-color: var(--surface-elevated); color: var(--text-secondary);">
                            Custom
                        </span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-right text-sm">
                        <div class="flex items-center justify-end gap-3">
                            @can('roles.manage')
                            <a href="{{ route('roles.edit', $role) }}"
                               class="font-medium transition-colors hover:underline"
                               style="color: var(--accent);">
                                Edit
                            </a>
                            @endcan
                            @can('roles.admin')
                            @if (! $builtIn)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}"
                                  onsubmit="return confirm('Delete role \'{{ addslashes($role->name) }}\'?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="font-medium transition-colors hover:underline"
                                        style="color: var(--text-danger);">
                                    Delete
                                </button>
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
