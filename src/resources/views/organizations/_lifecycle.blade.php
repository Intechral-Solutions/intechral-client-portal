{{--
    ORG-3b: the Lifecycle section of Portal access. The labels are the approved wording (D4): "Enable portal access" (once, from Not enabled), "Disable portal access" and "Re-enable portal access". $state = PortalState; $canManageRelationships (disable and re-enable, `manageRelationships`) and $canManageMembership
    (initial enablement, `manageMembership`) decide which action is drawn, and the state decides whether the service would accept it: initial enablement needs Not enabled,
    Disable needs Enabled, Enable again needs Disabled. The confirmation texts are JSON strings (@js), so an Organization name holding a quote, a backslash or a newline
    cannot end the string or the attribute early.
--}}
<section id="lifecycle" aria-labelledby="lifecycle-heading" class="rounded-lg border p-5 space-y-3 bg-surface border-rule">
    <h2 id="lifecycle-heading" class="text-sm font-semibold text-text">Lifecycle</h2>

    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
        <div>
            <dt class="text-xs text-text-muted">State</dt>
            <dd class="mt-0.5"><x-organization.portal-state :state="$state" /></dd>
        </div>
        @if (! $state->isNotEnabled())
        <div>
            <dt class="text-xs text-text-muted">Portal access enabled by</dt>
            <dd class="text-sm font-medium text-text">{{ $organization->portalEnabledBy?->name ?? '—' }}@if ($organization->portal_enabled_at) <span class="font-normal text-text-secondary">on {{ \Illuminate\Support\Carbon::parse($organization->portal_enabled_at)->format('M j, Y') }}</span>@endif</dd>
        </div>
        @endif
        @if ($state->isDisabled())
        <div>
            <dt class="text-xs text-text-muted">Disabled</dt>
            <dd class="text-sm text-text-secondary">{{ \Illuminate\Support\Carbon::parse($organization->disabled_at)->format('M j, Y') }}</dd>
        </div>
        @endif
    </dl>

    @if ($state->isNotEnabled())
        @if ($canManageMembership)
        {{-- Initial enablement: once, from Not enabled. It makes the acting operator the first member and approved administrator; it is not re-enablement. --}}
        <form method="POST" action="{{ route('organizations.promote', $organization) }}"
              onsubmit="return confirm(@js('Enable portal access for '.$organization->name.'?'))">
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm">Enable portal access</x-ui.button>
        </form>
        @else
        <p class="text-xs text-text-muted">Portal access has never been enabled.</p>
        @endif
    @elseif ($canManageRelationships)
    <div class="border-t pt-3 border-rule">
        <x-ui.field-error for="support" class="mb-1" />
        @if ($state->isEnabled())
        <form method="POST" action="{{ route('organizations.disable', $organization->id) }}"
              onsubmit="return confirm(@js('Disable portal access for '.$organization->name.'? No new request can be filed for it, and partner support access to its requests stops until it is enabled. Its open invitations are permanently revoked: enabling it again does not restore them, and new invitations must be sent.'))">
            @csrf
            <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Disable portal access</x-ui.button>
        </form>
        @else
        {{-- Re-enabling gives back what disabling suspended and nothing it removed: invitations revoked at disable stay revoked (a new invitation is a new credential). --}}
        <form method="POST" action="{{ route('organizations.enable', $organization->id) }}"
              onsubmit="return confirm(@js('Re-enable portal access for '.$organization->name.'? Requests can be filed for it again and partner support access resumes. Invitations that were revoked when it was disabled do not return: send new ones if they are still needed.'))">
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm">Re-enable portal access</x-ui.button>
        </form>
        @endif
    </div>
    @endif
</section>
