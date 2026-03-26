@extends('layouts.app', ['title' => $organization->name])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <a href="{{ route('organizations.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Organizations</a>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $organization->name }}</h1>
            <p class="mt-0.5 text-xs font-mono" style="color: var(--text-muted);">{{ $organization->slug }}</p>
        </div>
        @if ($organization->company)
        <a href="{{ route('crm.companies.show', $organization->company) }}"
           class="rounded-lg border px-4 py-2 text-sm font-medium"
           style="border-color: var(--border-base); color: var(--text-secondary);">View CRM Record</a>
        @endif
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Info card --}}
        <div class="rounded-xl border p-5 space-y-3" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <h2 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Details</h2>
            <div>
                <p class="text-xs" style="color: var(--text-muted);">Owner</p>
                <p class="text-sm font-medium" style="color: var(--text-primary);">{{ $organization->owner->name }}</p>
            </div>
            <div>
                <p class="text-xs" style="color: var(--text-muted);">Members</p>
                <p class="text-sm font-medium" style="color: var(--text-primary);">{{ $organization->members->count() }}</p>
            </div>
            <div>
                <p class="text-xs" style="color: var(--text-muted);">Created</p>
                <p class="text-sm" style="color: var(--text-secondary);">{{ $organization->created_at->format('M j, Y') }}</p>
            </div>
        </div>

        {{-- Members --}}
        <div class="lg:col-span-2 rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <div class="px-5 py-4 border-b" style="border-color: var(--border-base);">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Members</h2>
            </div>

            <table class="w-full text-sm">
                <tbody class="divide-y" style="divide-color: var(--border-base);">
                    @forelse ($organization->members as $member)
                    <tr>
                        <td class="px-5 py-3">
                            <p class="font-medium" style="color: var(--text-primary);">{{ $member->name }}</p>
                            <p class="text-xs" style="color: var(--text-muted);">{{ $member->email }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                                  style="background-color: {{ $member->pivot->role === 'admin' ? 'var(--surface-warning)' : 'var(--surface-input)' }};
                                         color: {{ $member->pivot->role === 'admin' ? 'var(--text-warning)' : 'var(--text-muted)' }};">
                                {{ ucfirst($member->pivot->role) }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                {{-- Toggle role --}}
                                <form method="POST" action="{{ route('organizations.members.role', [$organization, $member]) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'member' : 'admin' }}">
                                    <button type="submit" class="text-xs hover:underline" style="color: var(--text-secondary);">
                                        Make {{ $member->pivot->role === 'admin' ? 'Member' : 'Admin' }}
                                    </button>
                                </form>
                                {{-- Remove --}}
                                <form method="POST" action="{{ route('organizations.members.destroy', [$organization, $member]) }}"
                                      onsubmit="return confirm('Remove {{ addslashes($member->name) }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs hover:underline" style="color: var(--text-danger);">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>

            {{-- Add member form --}}
            @if ($availableUsers->isNotEmpty())
            <div class="border-t px-5 py-4" style="border-color: var(--border-base);">
                <form method="POST" action="{{ route('organizations.members.store', $organization) }}" class="flex gap-2">
                    @csrf
                    <select name="user_id"
                            class="flex-1 rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                    <select name="role"
                            class="rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium"
                            style="background-color: var(--accent); color: #fff;">Add</button>
                </form>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
