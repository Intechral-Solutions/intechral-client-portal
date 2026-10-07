@extends('layouts.app', ['title' => 'Users'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Users</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Manage platform users and their roles.</p>
        </div>
        @can('users.invite')
        <x-ui.button type="button"
                     onclick="document.getElementById('invite-modal').removeAttribute('hidden')"
                     class="shrink-0">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            Invite User
        </x-ui.button>
        @endcan
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <x-ui.alert variant="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- Search --}}
    <form method="GET" action="{{ route('users.index') }}" class="mb-6">
        <div class="flex gap-2">
            <x-ui.input type="search" name="search" :value="request('search')"
                        placeholder="Search by name or email&hellip;" class="w-full max-w-sm" />
            <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
            @if (request('search'))
            <x-ui.button :href="route('users.index')" variant="secondary">Clear</x-ui.button>
            @endif
        </div>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border" style="border-color: var(--border-base); background-color: var(--surface-base);">
        <table class="min-w-full divide-y" style="border-color: var(--border-subtle);">
            <thead>
                <tr style="background-color: var(--surface-elevated);">
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Email</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Roles</th>
                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="border-color: var(--border-subtle);">
                @forelse ($users as $user)
                <tr class="transition-colors hover:legacy-bg-surface">
                    <td class="px-6 py-4">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $user->name }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                        {{ $user->email }}
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($user->roles as $role)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                  style="background-color: var(--surface-elevated); color: var(--text-secondary);">
                                {{ $role->name }}
                            </span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-4 text-right text-sm">
                        <x-ui.link :href="route('users.show', $user)" class="font-medium">View</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-6 py-10 text-center text-sm" style="color: var(--text-secondary);">
                        No users found{{ request('search') ? ' matching "' . e(request('search')) . '"' : '' }}.
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

{{-- Invite modal --}}
@can('users.invite')
<div id="invite-modal" hidden
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background-color: rgba(0,0,0,0.5);">
    <div class="w-full max-w-md rounded-xl border p-6 shadow-xl"
         style="background-color: var(--surface-base); border-color: var(--border-base);">
        <h2 class="mb-4 text-lg font-semibold" style="color: var(--text-primary);">Invite User</h2>
        <form method="POST" action="{{ route('invitations.store') }}">
            @csrf
            <div class="mb-4 space-y-2">
                <x-ui.label for="invite-email">Email address</x-ui.label>
                <x-ui.input type="email" name="email" id="invite-email" required autocomplete="off"
                            placeholder="user@example.com" class="w-full" />
                <x-ui.field-error for="invite-email" error-key="email" />
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit">Send Invitation</x-ui.button>
                <x-ui.button type="button" variant="ghost"
                             onclick="document.getElementById('invite-modal').setAttribute('hidden', '')">Cancel</x-ui.button>
            </div>
        </form>
    </div>
</div>
@endcan

@endsection
