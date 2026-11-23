@extends('layouts.app', ['title' => $organization->name, 'breadcrumbTrail' => [[$organization->name, null]]])

@section('content')
{{-- Overview (ORG-3b): a SUMMARY of the Organization, never a roster. The People and the portal members each have their own page; this one counts them and links there. --}}
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <x-organization.workspace-header :header="$header">
        <x-slot:actions>
            <x-ui.button :href="route('organizations.edit', $organization)" variant="secondary">Edit</x-ui.button>
        </x-slot:actions>
    </x-organization.workspace-header>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    @error('company')
    <x-ui.alert variant="danger" class="mb-6">{{ $message }}</x-ui.alert>
    @enderror

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Details: the Directory profile. Internal notes are operators' private commentary and appear on operator pages only. --}}
        <section aria-labelledby="overview-details-heading" class="lg:col-span-2 rounded-lg border p-5 space-y-3 bg-surface border-rule">
            <h2 id="overview-details-heading" class="text-xs font-semibold uppercase tracking-wide mb-2 text-text-muted">Details</h2>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-text-muted">Classification</dt>
                    <dd class="text-sm text-text">{{ $organization->classification->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Website</dt>
                    <dd class="text-sm text-text break-words">
                        @if ($organization->website)
                        <x-ui.link :href="$organization->website" target="_blank" rel="noopener">{{ parse_url($organization->website, PHP_URL_HOST) ?: $organization->website }} ↗</x-ui.link>
                        @else
                        —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Phone</dt>
                    <dd class="text-sm text-text">{{ $organization->phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Created</dt>
                    <dd class="text-sm text-text-secondary">{{ $organization->created_at->format('M j, Y') }}@if ($organization->creator) by {{ $organization->creator->name }}@endif</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-text-muted">Address</dt>
                    <dd class="text-sm whitespace-pre-line text-text">{{ $organization->address ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-text-muted">Notes</dt>
                    <dd class="text-sm whitespace-pre-line text-text-secondary">{{ $organization->notes ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <div class="space-y-6">
            {{-- People: a count and a way in. An affiliation is where a person works; it grants no portal access and is not membership. --}}
            <section aria-labelledby="overview-people-heading" class="rounded-lg border p-5 space-y-2 bg-surface border-rule">
                <h2 id="overview-people-heading" class="text-xs font-semibold uppercase tracking-wide text-text-muted">People</h2>
                <p class="text-sm text-text">{{ $peopleCount }} {{ $peopleCount === 1 ? 'person' : 'people' }} affiliated</p>
                <p class="text-xs text-text-muted">Affiliation records where a person works. It grants no portal access.</p>
                <x-ui.link :href="route('organizations.people', $organization)" class="text-sm font-medium" aria-label="View the people of {{ $organization->name }}">View people</x-ui.link>
            </section>

            {{-- Portal access: the state, and one way in. Lifecycle actions live on the Portal access page only, so each act has one place. --}}
            <section aria-labelledby="overview-portal-heading" class="rounded-lg border p-5 space-y-2 bg-surface border-rule">
                <h2 id="overview-portal-heading" class="text-xs font-semibold uppercase tracking-wide text-text-muted">Portal access</h2>
                <p><x-organization.portal-state :state="$portal['state']" /></p>
                @if ($portal['state']->isEnabled())
                <p class="text-xs text-text-muted">Its members can sign in and file requests for it.</p>
                @elseif ($portal['state']->isDisabled())
                <p class="text-xs text-text-muted">Portal access was enabled and has been disabled: no new request can be filed for it.</p>
                @else
                <p class="text-xs text-text-muted">This Organization is in the Directory only. Portal access has never been enabled.</p>
                @endif
                @if ($portal['canManageMembership'] && ! $portal['state']->isNotEnabled())
                <p class="text-sm text-text">{{ $portal['memberCount'] }} {{ $portal['memberCount'] === 1 ? 'member' : 'members' }}@if ($portal['enabledBy']) · enabled by {{ $portal['enabledBy'] }}@endif</p>
                @endif
                <x-ui.link :href="route('organizations.portal', $organization)" class="text-sm font-medium" aria-label="Open portal access for {{ $organization->name }}">{{ $portal['state']->isNotEnabled() ? 'Manage portal access' : 'Open portal access' }}</x-ui.link>
            </section>
        </div>
    </div>
</div>
@endsection
