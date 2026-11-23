{{--
    ORG-3b: the Members section of Portal access (membership only: who belongs, in which role). Administrator approval and partner support designation are their own
    sections (_approvals, _support); the role and the approval are separate facts, and neither is an input to the other.

    $memberships is built only for an actor who may manage membership (null otherwise: the plain member list below, with no control). $state = PortalState (ORG-3a): Add member and
    Make Admin are new authority and are drawn only for Enabled; Make Member and Remove take authority away and are always offered. $approvedCount = members whose approval counts.
--}}
<section id="members" aria-labelledby="members-heading" class="rounded-lg border overflow-hidden bg-surface border-rule">
    <div class="px-5 py-4 border-b border-rule">
        <h2 id="members-heading" class="text-sm font-semibold text-text">Members</h2>
        @if ($memberships !== null)
        <p class="mt-1 text-xs text-text-muted">Members of {{ $organization->name }} in the portal. A person's role here grants nothing by itself: administrator authority needs an operator's approval, below.</p>
        @endif
    </div>

    @if ($memberships !== null)
    <div class="px-5 pt-4"><x-ui.field-error for="membership" /></div>
    <ul role="list" class="divide-y divide-rule text-sm">
                @forelse ($memberships as $member)
                @php($lastApproved = $member->counts && $approvedCount === 1)
                <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 px-5 py-3">
                    <div class="min-w-0 flex-1 basis-56">
                        <p class="font-medium text-text">
                            @can('users.view')
                            <x-ui.link variant="row" :href="route('users.show', $member->id)">{{ $member->name }}</x-ui.link>
                            @else
                            {{ $member->name }}
                            @endcan
                        </p>
                        <p class="text-xs text-text-muted">{{ $member->email }}</p>
                        @if ($member->operator)
                        <p class="mt-1 text-xs text-text-muted">Operator account: acts with platform authority, not through this membership.</p>
                        @endif
                        @if (! $member->active)
                        <p class="mt-1 text-xs text-text-muted">Account suspended.</p>
                        @endif
                    </div>
                    <div class="shrink-0">
                        <x-ui.tag>{{ ucfirst($member->role) }}</x-ui.tag>
                    </div>
                    <div class="ml-auto">
                        <div class="flex flex-col items-end gap-2">
                            <div class="flex items-center justify-end gap-3">
                                {{-- Toggle role. Promoting never approves; demoting clears any approval. --}}
                                @if ($lastApproved)
                                <x-ui.button variant="ghost" size="sm" disabled>Make Member</x-ui.button>
                                @elseif ($member->role !== 'admin' && ! $state->isEnabled())
                                {{-- Promotion is new authority: only an Enabled organization may. Demotion takes authority away and is always offered. --}}
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
                                      onsubmit="return confirm(@js('Remove '.$member->name.'?'))">
                                    @csrf @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Remove</x-ui.button>
                                </form>
                                @endif
                            </div>
                            @if ($lastApproved)
                            {{-- The way out depends on the state: another administrator can be approved only while portal access is enabled. --}}
                            <p class="max-w-xs text-xs text-text-muted">The only approved administrator: {{ $state->isEnabled() ? 'approve another before demoting or removing them.' : 'another can be approved only while portal access is enabled; revoking this approval is the exception, in Administrator approval.' }}</p>
                            @endif
                        </div>
                    </div>
                </li>
                @empty
                <li class="px-5 py-8 text-center text-sm text-text-muted">{{ $state->isNotEnabled() ? 'No portal members: portal access is not enabled.' : 'No members yet.' }}</li>
                @endforelse
            </ul>

    {{-- Add member: only an Enabled organization accepts a new member (ORG-1), so no other state is offered the form. --}}
    @if (! $state->isEnabled() && ! ($state->isNotEnabled() && $memberships->isEmpty()))
    <p class="border-t px-5 py-4 text-xs text-text-muted border-rule">Members can be added only while portal access is enabled.</p>
    @endif
    @if ($availableUsers->isNotEmpty())
    <div class="border-t px-5 py-4 border-rule">
        <form method="POST" action="{{ route('organizations.members.store', $organization) }}" class="flex flex-wrap gap-2 sm:flex-nowrap">
            @csrf
            <x-ui.select name="user_id" aria-label="Account to add" class="min-w-0 basis-full flex-1 sm:basis-0">
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
    @else
    {{-- No membership authority: the plain list, no control. --}}
    <table class="w-full text-sm">
        <tbody class="divide-y divide-rule">
            @forelse ($organization->members as $member)
            <tr>
                <td class="px-5 py-3">
                    <p class="font-medium text-text">
                        @can('users.view')
                        <x-ui.link variant="row" :href="route('users.show', $member->id)">{{ $member->name }}</x-ui.link>
                        @else
                        {{ $member->name }}
                        @endcan
                    </p>
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
</section>
