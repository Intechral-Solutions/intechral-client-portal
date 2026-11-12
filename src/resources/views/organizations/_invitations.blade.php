{{--
    Release 1.0 (R1-3C): the operator's Invitations card for one organization. The controller passes $invitations only when the actor
    may manage membership (portal.operator AND crm.manage), so a customer who opens their own organization's page gets no card at all.
    Every action is enforced on the server whatever this card shows (manageMembership, then again under lock in the service).

    The card shows operator-only data: an invitee's address and the names of the inviter and of the person who accepted. It never shows
    a link, a token or a hash: the plaintext exists only in the mailed message, and only the hash is stored.

    $invitations = ['rows' => the newest rows (see OrganizationInvitationList), 'total' => all rows, 'truncated' => bool]
--}}
@php
    $stateTone = ['pending' => ['info', 'hourglass'], 'accepted' => ['success', 'check'], 'revoked' => ['neutral', 'dashed'], 'expired' => ['warning', 'triangle']];
    $stateLabel = ['pending' => 'Pending', 'accepted' => 'Accepted', 'revoked' => 'Revoked', 'expired' => 'Expired'];
@endphp
<section id="invitations" aria-labelledby="invitations-heading" class="rounded-lg border overflow-hidden bg-surface border-rule">
    <div class="px-5 py-4 border-b border-rule">
        <h2 id="invitations-heading" class="text-sm font-semibold text-text">Invitations</h2>
        <p class="mt-1 text-xs text-text-muted">Invite a new person into {{ $organization->name }}. Invited people join as ordinary members; administrators are made separately. An address that already has an account cannot be invited: use Add member instead.</p>
    </div>

    <div class="border-b px-5 py-4 border-rule">
        <form method="POST" action="{{ route('organizations.invitations.store', $organization) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <div class="min-w-0 flex-1">
                <x-ui.label for="invitation-email">Email address to invite</x-ui.label>
                <x-ui.input type="email" name="email" id="invitation-email" error-key="email" :value="old('email')" required maxlength="255" autocomplete="off" class="mt-1 w-full" />
            </div>
            <x-ui.button type="submit">Send invitation</x-ui.button>
        </form>
        <x-ui.field-error for="invitation-email" error-key="email" class="mt-2" />
    </div>

    @if ($invitations['rows']->isEmpty())
    <p class="px-5 py-8 text-center text-sm text-text-muted">No invitations yet.</p>
    @else
    <table class="w-full text-sm">
        <caption class="sr-only">Invitations to {{ $organization->name }}, newest first</caption>
        <thead>
            <tr class="border-b border-rule text-left text-xs uppercase tracking-wide text-text-muted">
                <th scope="col" class="px-5 py-2 font-medium">Invitee</th>
                <th scope="col" class="px-5 py-2 font-medium">Status</th>
                <th scope="col" class="px-5 py-2 font-medium"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-rule">
            @foreach ($invitations['rows'] as $invitation)
            <tr>
                <td class="px-5 py-3 align-top">
                    <p class="font-medium text-text break-all">{{ $invitation->email }}</p>
                    <p class="text-xs text-text-muted">Invited by {{ $invitation->invited_by_name }} on {{ $invitation->sent_at->format('M j, Y') }}</p>
                </td>
                <td class="px-5 py-3 align-top">
                    <x-ui.status :tone="$stateTone[$invitation->state][0]" :glyph="$stateTone[$invitation->state][1]">{{ $stateLabel[$invitation->state] }}</x-ui.status>
                    @if ($invitation->state === 'pending')
                    <p class="mt-1 text-xs text-text-muted">Expires {{ $invitation->expires_at->format('M j, Y') }}</p>
                    @if ($invitation->unusable !== null)
                    <p class="mt-1 max-w-xs text-xs text-text">{{ $invitation->unusable }}</p>
                    @endif
                    @elseif ($invitation->state === 'accepted')
                    <p class="mt-1 text-xs text-text-muted">
                        Accepted {{ $invitation->accepted_at->format('M j, Y') }} by
                        @can('users.view')
                        <x-ui.link variant="row" :href="route('users.show', $invitation->accepted_user_id)">{{ $invitation->accepted_name }}</x-ui.link>
                        @else
                        {{ $invitation->accepted_name }}
                        @endcan
                    </p>
                    @elseif ($invitation->state === 'revoked')
                    <p class="mt-1 text-xs text-text-muted">{{ $invitation->reason ?? 'Revoked' }}</p>
                    @else
                    <p class="mt-1 text-xs text-text-muted">Expired {{ $invitation->expires_at->format('M j, Y') }}</p>
                    @endif
                </td>
                <td class="px-5 py-3 align-top text-right">
                    <div class="flex items-center justify-end gap-3">
                        @if ($invitation->canResend)
                        <form method="POST" action="{{ route('organizations.invitations.store', $organization) }}">
                            @csrf
                            <input type="hidden" name="email" value="{{ $invitation->email }}">
                            <x-ui.button type="submit" variant="secondary" size="sm" :aria-label="'Resend the invitation to '.$invitation->email">Resend</x-ui.button>
                        </form>
                        @endif
                        @if ($invitation->canRevoke)
                        <form method="POST" action="{{ route('organizations.invitations.destroy', [$organization, $invitation->id]) }}">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" tone="danger" size="sm" :aria-label="'Revoke the invitation to '.$invitation->email">Revoke</x-ui.button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if ($invitations['truncated'])
    <p class="border-t px-5 py-3 text-xs text-text-muted border-rule">Showing the {{ $invitations['rows']->count() }} most recent of {{ $invitations['total'] }} invitations.</p>
    @endif
    @endif
</section>
