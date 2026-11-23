{{--
    ORG-3b: the Partner support access section of Portal access (R1-4). Drawn only for an actor who may manage membership and only where the Organization is a PARTNER (or a non-partner whose
    memberships carry designation data, a row the application never writes and the preflight reports, so the operator can reach the Revoke control it points to).
    A designation is an operator act only; a member's role and approval are not inputs to it. $support = rows keyed by member id; $memberships = the member rows;
    $state = PortalState (ORG-3a): granting is new authority and is drawn only for Enabled; revoking takes authority away and is always offered.
--}}
<section id="support" aria-labelledby="support-heading" class="rounded-lg border overflow-hidden bg-surface border-rule">
    <div class="px-5 py-4 border-b border-rule">
        <h2 id="support-heading" class="text-sm font-semibold text-text">Partner support access</h2>
        <p class="mt-1 text-xs text-text-muted">A designated member of a partner can work the service requests of the customers that partner manages. It is an operator decision made per person; a member's role and administrator approval are not inputs to it.</p>
    </div>

    <ul role="list" class="divide-y divide-rule text-sm">
                @forelse ($memberships->filter(fn ($member) => isset($support[$member->id])) as $member)
                @php($grant = $support[$member->id])
                <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 px-5 py-3">
                    <div class="min-w-0 flex-1 basis-56">
                        <p class="font-medium text-text">{{ $member->name }}</p>
                        <p class="text-xs text-text-muted">{{ $member->email }}</p>
                    </div>
                    <div class="min-w-0 flex-1 basis-56">
                        @if ($grant->granted)
                        <p class="text-xs text-text">Support access: {{ $grant->inEffect ? 'in effect' : 'granted, not in effect' }}</p>
                        <p class="max-w-xs text-xs text-text-muted">Granted by {{ $grant->by ?? 'an operator' }}, {{ \Illuminate\Support\Carbon::parse($grant->at)->format('M j, Y') }}.@if ($grant->notInEffectBecause) Not in effect because {{ $grant->notInEffectBecause }}.@endif</p>
                        @elseif ($grant->incomplete)
                        <p class="text-xs text-text">Incomplete support record</p>
                        <p class="max-w-xs text-xs text-text-muted">It grants nothing. Revoke it to clear the record.</p>
                        @else
                        <p class="text-xs text-text-muted">No support access.</p>
                        @endif
                    </div>
                    <div class="ml-auto">
                        <div class="flex flex-col items-end gap-2">
                            @if ($grant->granted || $grant->incomplete)
                            <form method="POST" action="{{ route('organizations.members.support.revoke', [$organization->id, $member->id]) }}">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Revoke support access</x-ui.button>
                            </form>
                            @endif
                            @if ($state->isEnabled() && ! $grant->granted && $member->active && ! $member->operator)
                            <form method="POST" action="{{ route('organizations.members.support.grant', [$organization->id, $member->id]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Grant support access</x-ui.button>
                            </form>
                            @endif
                        </div>
                    </div>
                </li>
                @empty
                <li class="px-5 py-8 text-center text-sm text-text-muted">No member can be designated.</li>
                @endforelse
            </ul>
</section>
