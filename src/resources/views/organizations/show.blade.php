@extends('layouts.app', ['title' => $organization->name])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('organizations.index')" class="text-sm">&larr; Organizations</x-ui.link>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $organization->name }}</h1>
            <p class="mt-0.5 text-xs font-mono" style="color: var(--text-muted);">{{ $organization->slug }}</p>
        </div>
        @if ($organization->company)
        <x-ui.button :href="route('crm.companies.show', $organization->company)" variant="secondary">View CRM Record</x-ui.button>
        @endif
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
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
                            <x-ui.tag>{{ ucfirst($member->pivot->role) }}</x-ui.tag>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                {{-- Toggle role --}}
                                <form method="POST" action="{{ route('organizations.members.role', [$organization, $member]) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="role" value="{{ $member->pivot->role === 'admin' ? 'member' : 'admin' }}">
                                    <x-ui.button type="submit" variant="ghost" size="sm">
                                        Make {{ $member->pivot->role === 'admin' ? 'Member' : 'Admin' }}
                                    </x-ui.button>
                                </form>
                                {{-- Remove --}}
                                <form method="POST" action="{{ route('organizations.members.destroy', [$organization, $member]) }}"
                                      onsubmit="return confirm('Remove {{ addslashes($member->name) }}?')">
                                    @csrf @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Remove</x-ui.button>
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
                    <x-ui.select name="user_id" aria-label="Person to add" class="min-w-0 flex-1">
                        @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="role" aria-label="Organization role">
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                    </x-ui.select>
                    <x-ui.button type="submit">Add</x-ui.button>
                </form>
                <x-ui.field-error for="user_id" class="mt-2" />
                <x-ui.field-error for="role" class="mt-2" />
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
