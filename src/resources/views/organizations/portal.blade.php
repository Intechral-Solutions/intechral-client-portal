@extends('layouts.app', ['title' => $organization->name.' portal access', 'breadcrumbTrail' => [[$organization->name, null]]])

@section('content')
{{-- Portal access (ORG-3b): the state and its lifecycle, members, administrator approval, partner support, invitations and the managing relationship, one section each.
     Everything here is built by OrganizationWorkspaceController::portal() for the ability that shows it (manageMembership, manageRelationships), and every control is drawn only
     where the service would accept it in the current state (ORG-3a). Each act keeps its own endpoint and returns to this page, with its error beside the form. --}}
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <x-organization.workspace-header :header="$header" />

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    @error('company')
    <x-ui.alert variant="danger" class="mb-6">{{ $message }}</x-ui.alert>
    @enderror

    {{-- What the state means for this page; the section controls follow it exactly. The sections below say only what is unavailable, never the whole of this again. --}}
    @if ($state->isDisabled())
    <x-ui.alert variant="warning" class="mb-6">This organization is disabled (since {{ \Illuminate\Support\Carbon::parse($organization->disabled_at)->format('M j, Y') }}). No new request can be filed for it and partner support access to its requests is suspended. Its open invitations were permanently revoked when it was disabled and do not return if portal access is enabled again; its members, approvals and relationships are not removed. While it is disabled, members can still be removed or demoted and approvals revoked, but no member can be added, promoted or approved, no invitation sent and no support access or partner link granted.</x-ui.alert>
    @elseif ($state->isNotEnabled())
    <x-ui.alert variant="neutral" class="mb-6">This organization has never had portal access: it exists in the Directory only. Nothing here can be administered until portal access is enabled.</x-ui.alert>
    @endif

    {{-- Members whose administrator approval counts (null memberships: no membership authority, so no count is read). --}}
    @php($approvedCount = $memberships?->where('counts', true)->count() ?? 0)

    <div class="space-y-6">
        @include('organizations._lifecycle')

        {{-- A never-enabled Organization with no member has nothing to list or approve (the application cannot create such a row; the preflight reports one), so the sections are absent rather than empty boxes. --}}
        @if (! ($state->isNotEnabled() && ($memberships?->isEmpty() ?? $organization->members->isEmpty())))
        @include('organizations._members')

        @if ($memberships !== null)
        @include('organizations._approvals')
        @endif
        @endif

        @if ($support !== null)
        @include('organizations._support')
        @endif

        {{-- A never-enabled Organization with no invitation: no card. (Rows that exist stay, so an open one can still be revoked.) --}}
        @if ($invitations !== null && ! ($state->isNotEnabled() && $invitations['rows']->isEmpty()))
        @include('organizations._invitations')
        @endif

        {{-- Likewise a never-enabled Organization with no relationship has nothing to show here, so the panel is absent rather than an empty box of refusals. --}}
        @if ($relationship !== null && ! ($state->isNotEnabled() && ! $relationship['isPartner'] && ! $relationship['manager']))
        @include('organizations._relationship')
        @endif
    </div>
</div>
@endsection
