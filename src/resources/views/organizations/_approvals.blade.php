{{--
    ORG-3b: the Administrator approval section of Portal access. Drawn only for an actor who may manage membership ($memberships is non-null). An administrator ROLE grants
    nothing by itself: an operator's explicit approval of that membership is a separate act (Approve administrator), and revoking it takes the authority away (Revoke approval).
    $state = PortalState (ORG-3a): approving is new authority and is drawn only for Enabled; revoking takes authority away and is always offered.
    $approvedCount = members whose approval counts; the only such member's revoke needs a separately confirmed acknowledgement.
--}}
@php($approvable = $memberships->filter(fn ($member) => $member->role === 'admin' || $member->hasApprovalData))
<section id="approval" aria-labelledby="approval-heading" class="rounded-lg border overflow-hidden bg-surface border-rule">
    <div class="px-5 py-4 border-b border-rule">
        <h2 id="approval-heading" class="text-sm font-semibold text-text">Administrator approval</h2>
        <p class="mt-1 text-xs text-text-muted">The administrator role grants nothing by itself. An administrator holds delegated authority only after an operator approves that membership, and no delegated authority is active in this release.</p>
    </div>

    <ul role="list" class="divide-y divide-rule text-sm">
                @forelse ($approvable as $member)
                @php($lastApproved = $member->counts && $approvedCount === 1)
                <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 px-5 py-3">
                    <div class="min-w-0 flex-1 basis-56">
                        <p class="font-medium text-text">{{ $member->name }}</p>
                        <p class="text-xs text-text-muted">{{ $member->email }}</p>
                    </div>
                    <div class="min-w-0 flex-1 basis-56">
                        @if ($member->role === 'admin')
                            @if ($member->counts)
                            <p class="text-xs text-text">Approved administrator</p>
                            <p class="text-xs text-text-muted">by {{ $member->approver_name ?? 'an operator' }}, {{ \Illuminate\Support\Carbon::parse($member->admin_approved_at)->format('M j, Y') }}</p>
                            @elseif ($member->approved)
                            <p class="text-xs text-text">Approved, not in effect</p>
                            <p class="text-xs text-text-muted">{{ $member->operator ? 'The account is an operator.' : 'The account is suspended.' }} Approved {{ \Illuminate\Support\Carbon::parse($member->admin_approved_at)->format('M j, Y') }}.</p>
                            @else
                            <p class="text-xs text-text-muted">Not approved: holds no delegated authority.</p>
                            @endif
                        @elseif ($member->hasApprovalData)
                        <p class="text-xs text-text-muted">Has an approval record but is not an administrator; it is ignored. Revoke it to clear the record.</p>
                        @endif
                        @if ($member->incomplete)
                        <p class="mt-2 text-xs text-text">Incomplete approval record</p>
                        <p class="text-xs text-text-muted">{{ $member->admin_approved_at === null ? 'An approver is recorded but no approval time.' : 'An approval time is recorded but no approver.' }} It does not count as an approval. Revoke it, then approve again if the owner has reviewed this administrator.</p>
                        @endif
                    </div>
                    <div class="ml-auto">
                        <div class="flex flex-col items-end gap-2">
                            @if ($state->isEnabled() && $member->role === 'admin' && ! $member->hasApprovalData && $member->refusal === null)
                            <form method="POST" action="{{ route('organizations.members.approve', [$organization, $member->id]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Approve administrator</x-ui.button>
                            </form>
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
                            @if ($member->role === 'admin' && ! $member->hasApprovalData && $member->refusal !== null)
                            <p class="max-w-xs text-xs text-text-muted">Cannot be approved: {{ ['suspended' => 'the account is suspended', 'operator' => 'the account is an operator'][$member->refusal] ?? 'not an administrator' }}.</p>
                            @endif
                        </div>
                    </div>
                </li>
                @empty
                <li class="px-5 py-8 text-center text-sm text-text-muted">No member is an administrator.</li>
                @endforelse
            </ul>
</section>
