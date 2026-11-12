@extends('layouts.app', ['title' => $organization->name])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('organizations.index')" class="text-sm">&larr; Organizations</x-ui.link>
            <h1 class="mt-2 text-2xl font-semibold text-text">{{ $organization->name }}</h1>
            <p class="mt-0.5 text-xs font-mono text-text-muted">{{ $organization->slug }}</p>
        </div>
        @if ($organization->company)
        <x-ui.button :href="route('crm.companies.show', $organization->company)" variant="secondary">View CRM Record</x-ui.button>
        @endif
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($organization->disabled_at !== null)
    <x-ui.alert variant="warning" class="mb-6">This organization is disabled (since {{ \Illuminate\Support\Carbon::parse($organization->disabled_at)->format('M j, Y') }}). No new request can be filed for it and partner support access to its requests is suspended. Nothing else is removed.</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <div class="space-y-6">
        {{-- Info card --}}
        <div class="rounded-lg border p-5 space-y-3 bg-surface border-rule">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-text-muted">Details</h2>
            <div>
                <p class="text-xs text-text-muted">Owner</p>
                <p class="text-sm font-medium text-text">{{ $organization->owner->name }}</p>
            </div>
            <div>
                <p class="text-xs text-text-muted">Members</p>
                <p class="text-sm font-medium text-text">{{ $organization->members->count() }}</p>
            </div>
            <div>
                <p class="text-xs text-text-muted">Created</p>
                <p class="text-sm text-text-secondary">{{ $organization->created_at->format('M j, Y') }}</p>
            </div>
            @if ($canManageRelationships)
            <div class="border-t pt-3 border-rule">
                <p class="text-xs text-text-muted">Status</p>
                <p class="text-sm text-text">{{ $organization->disabled_at === null ? 'Enabled' : 'Disabled' }}</p>
                <x-ui.field-error for="support" class="mt-1" />
                @if ($organization->disabled_at === null)
                <form method="POST" action="{{ route('organizations.disable', $organization->id) }}" class="mt-2" onsubmit="return confirm('Disable {{ addslashes($organization->name) }}? No new request can be filed for it, and partner support access to its requests stops until it is enabled.')">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Disable organization</x-ui.button>
                </form>
                @else
                <form method="POST" action="{{ route('organizations.enable', $organization->id) }}" class="mt-2">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm">Enable organization</x-ui.button>
                </form>
                @endif
            </div>
            @endif
        </div>

        @if ($relationship !== null)
        @include('organizations._relationship')
        @endif
        </div>

        <div class="lg:col-span-2 space-y-6">
        {{-- Members --}}
        <div class="rounded-lg border overflow-hidden bg-surface border-rule">
            <div class="px-5 py-4 border-b border-rule">
                <h2 class="text-sm font-semibold text-text">Members</h2>
                @if ($memberships !== null)
                <p class="mt-1 text-xs text-text-muted">The administrator role grants nothing by itself. An administrator holds delegated authority only after an operator approves that membership, and no delegated authority is active in this release.</p>
                @endif
            </div>

            @if ($memberships !== null)
            <div class="px-5 pt-4"><x-ui.field-error for="membership" /></div>
            @php($approvedCount = $memberships->where('counts', true)->count())
            <table class="w-full text-sm">
                <tbody class="divide-y divide-rule">
                    @forelse ($memberships as $member)
                    @php($lastApproved = $member->counts && $approvedCount === 1)
                    <tr>
                        <td class="px-5 py-3 align-top">
                            <p class="font-medium text-text">{{ $member->name }}</p>
                            <p class="text-xs text-text-muted">{{ $member->email }}</p>
                            @if ($member->operator)
                            <p class="mt-1 text-xs text-text-muted">Operator account: acts with platform authority, not through this membership.</p>
                            @endif
                            @if (! $member->active)
                            <p class="mt-1 text-xs text-text-muted">Account suspended.</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 align-top">
                            <x-ui.tag>{{ ucfirst($member->role) }}</x-ui.tag>
                            @if ($member->role === 'admin')
                                @if ($member->counts)
                                <p class="mt-2 text-xs text-text">Approved administrator</p>
                                <p class="text-xs text-text-muted">by {{ $member->approver_name ?? 'an operator' }}, {{ \Illuminate\Support\Carbon::parse($member->admin_approved_at)->format('M j, Y') }}</p>
                                @elseif ($member->approved)
                                <p class="mt-2 text-xs text-text">Approved, not in effect</p>
                                <p class="text-xs text-text-muted">{{ $member->operator ? 'The account is an operator.' : 'The account is suspended.' }} Approved {{ \Illuminate\Support\Carbon::parse($member->admin_approved_at)->format('M j, Y') }}.</p>
                                @else
                                <p class="mt-2 text-xs text-text-muted">Not approved: holds no delegated authority.</p>
                                @endif
                            @elseif ($member->hasApprovalData)
                            <p class="mt-2 text-xs text-text-muted">Has an approval record but is not an administrator; it is ignored. Revoke it to clear the record.</p>
                            @endif
                            @if ($member->incomplete)
                            <p class="mt-2 text-xs text-text">Incomplete approval record</p>
                            <p class="text-xs text-text-muted">{{ $member->admin_approved_at === null ? 'An approver is recorded but no approval time.' : 'An approval time is recorded but no approver.' }} It does not count as an approval. Revoke it, then approve again if the owner has reviewed this administrator.</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 align-top text-right">
                            <div class="flex flex-col items-end gap-2">
                                <div class="flex items-center justify-end gap-3">
                                    @if ($member->role === 'admin' && ! $member->hasApprovalData && $member->refusal === null)
                                    <form method="POST" action="{{ route('organizations.members.approve', [$organization, $member->id]) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm">Approve administrator</x-ui.button>
                                    </form>
                                    @endif
                                    {{-- Toggle role. Promoting never approves; demoting clears any approval. --}}
                                    @if ($lastApproved)
                                    <x-ui.button variant="ghost" size="sm" disabled>Make Member</x-ui.button>
                                    @else
                                    <form method="POST" action="{{ route('organizations.members.role', [$organization, $member->id]) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="role" value="{{ $member->role === 'admin' ? 'member' : 'admin' }}">
                                        <x-ui.button type="submit" variant="ghost" size="sm">
                                            Make {{ $member->role === 'admin' ? 'Member' : 'Admin' }}
                                        </x-ui.button>
                                    </form>
                                    @endif
                                    {{-- Remove --}}
                                    @if ($lastApproved)
                                    <x-ui.button variant="ghost" tone="danger" size="sm" disabled>Remove</x-ui.button>
                                    @else
                                    <form method="POST" action="{{ route('organizations.members.destroy', [$organization, $member->id]) }}"
                                          onsubmit="return confirm('Remove {{ addslashes($member->name) }}?')">
                                        @csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Remove</x-ui.button>
                                    </form>
                                    @endif
                                </div>
                                @if ($lastApproved)
                                <p class="max-w-xs text-xs text-text-muted">The only approved administrator: approve another before demoting or removing them.</p>
                                @endif
                                @if ($member->hasApprovalData)
                                {{-- Revoking is the exceptional, separately-confirmed way to take authority away, and the only way to leave none. --}}
                                <form method="POST" action="{{ route('organizations.members.approval.revoke', [$organization, $member->id]) }}" class="flex flex-col items-end gap-2">
                                    @csrf @method('DELETE')
                                    @if ($lastApproved)
                                    <x-ui.checkbox name="confirm_no_administrator" value="1" required>I understand this leaves {{ $organization->name }} with no approved administrator.</x-ui.checkbox>
                                    @endif
                                    <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Revoke approval</x-ui.button>
                                </form>
                                @endif
                                @if ($support !== null && isset($support[$member->id]))
                                @php($grant = $support[$member->id])
                                {{-- R1-4: partner support designation. An operator act only; the role and approval above are not inputs. --}}
                                <div class="flex flex-col items-end gap-1 border-t pt-2 border-rule">
                                    @if ($grant->granted)
                                    <p class="text-xs text-text">Support access: {{ $grant->inEffect ? 'in effect' : 'granted, not in effect' }}</p>
                                    <p class="max-w-xs text-xs text-text-muted">Granted by {{ $grant->by ?? 'an operator' }}, {{ \Illuminate\Support\Carbon::parse($grant->at)->format('M j, Y') }}.@if ($grant->notInEffectBecause) Not in effect because {{ $grant->notInEffectBecause }}.@endif</p>
                                    @elseif ($grant->incomplete)
                                    <p class="text-xs text-text">Incomplete support record</p>
                                    <p class="max-w-xs text-xs text-text-muted">It grants nothing. Revoke it to clear the record.</p>
                                    @else
                                    <p class="text-xs text-text-muted">No support access.</p>
                                    @endif
                                    @if ($grant->granted || $grant->incomplete)
                                    <form method="POST" action="{{ route('organizations.members.support.revoke', [$organization->id, $member->id]) }}">
                                        @csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Revoke support access</x-ui.button>
                                    </form>
                                    @endif
                                    @if (! $grant->granted && $member->active && ! $member->operator)
                                    <form method="POST" action="{{ route('organizations.members.support.grant', [$organization->id, $member->id]) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm">Grant support access</x-ui.button>
                                    </form>
                                    @endif
                                </div>
                                @endif
                                @if ($member->role === 'admin' && ! $member->hasApprovalData && $member->refusal !== null)
                                <p class="max-w-xs text-xs text-text-muted">Cannot be approved: {{ ['suspended' => 'the account is suspended', 'operator' => 'the account is an operator'][$member->refusal] ?? 'not an administrator' }}.</p>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-8 text-center text-sm text-text-muted">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @else
            <table class="w-full text-sm">
                <tbody class="divide-y divide-rule">
                    @forelse ($organization->members as $member)
                    <tr>
                        <td class="px-5 py-3">
                            <p class="font-medium text-text">{{ $member->name }}</p>
                            <p class="text-xs text-text-muted">{{ $member->email }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <x-ui.tag>{{ ucfirst($member->pivot->role) }}</x-ui.tag>
                        </td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-8 text-center text-sm text-text-muted">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @endif

            {{-- Add member form --}}
            @if ($memberships !== null && $availableUsers->isNotEmpty())
            <div class="border-t px-5 py-4 border-rule">
                <form method="POST" action="{{ route('organizations.members.store', $organization) }}" class="flex gap-2">
                    @csrf
                    <x-ui.select name="user_id" aria-label="Person to add" class="min-w-0 flex-1">
                        @foreach ($availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="role" aria-label="Organization role">
                        <option value="member">Member</option>
                        <option value="admin">Admin (not approved)</option>
                    </x-ui.select>
                    <x-ui.button type="submit">Add</x-ui.button>
                </form>
                <x-ui.field-error for="user_id" class="mt-2" />
                <x-ui.field-error for="role" class="mt-2" />
            </div>
            @endif
        </div>

        @if ($invitations !== null)
        @include('organizations._invitations')
        @endif
        </div>
    </div>
</div>
@endsection
