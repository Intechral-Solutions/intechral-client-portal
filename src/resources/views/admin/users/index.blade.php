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
        <button type="button"
                onclick="document.getElementById('invite-modal').removeAttribute('hidden')"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                style="background-color: var(--accent); color: #fff;">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            Invite User
        </button>
        @endcan
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">
        {{ session('status') }}
    </div>
    @endif

    {{-- Search --}}
    <form method="GET" action="{{ route('users.index') }}" class="mb-6">
        <div class="flex gap-2">
            <input type="search" name="search" value="{{ request('search') }}"
                   placeholder="Search by name or email&hellip;"
                   class="block w-full max-w-sm rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <button type="submit"
                    class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:bg-surface"
                    style="border-color: var(--border-base); color: var(--text-secondary);">
                Search
            </button>
            @if (request('search'))
            <a href="{{ route('users.index') }}"
               class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:bg-surface"
               style="border-color: var(--border-base); color: var(--text-secondary);">
                Clear
            </a>
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
                <tr class="transition-colors hover:bg-surface">
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
                        <a href="{{ route('users.show', $user) }}"
                           class="font-medium transition-colors hover:underline"
                           style="color: var(--accent);">
                            View
                        </a>
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
            <label for="invite-email" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Email address
            </label>
            <input type="email" id="invite-email" name="email" required autocomplete="off"
                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2 mb-4"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"
                   placeholder="user@example.com">
            <div class="flex gap-3">
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        style="background-color: var(--accent); color: #fff;">
                    Send Invitation
                </button>
                <button type="button"
                        onclick="document.getElementById('invite-modal').setAttribute('hidden', '')"
                        class="rounded-lg px-4 py-2 text-sm font-medium transition-colors hover:bg-surface"
                        style="color: var(--text-secondary);">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>
@endcan

@endsection
