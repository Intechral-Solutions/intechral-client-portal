{{--
    ORG-3a: the ONE badge for the portal state of an Organization (Release 1.0, ADR-011 section 2), used by both Organization lists and the access detail.

    <x-organization.portal-state :state="$organization->portalState()" />

    `state` is a {@see \App\Support\Organizations\PortalState}, never a string, so there is no default to fall into: the match below has no fallback arm, and a
    value that is not one of the three states throws instead of being drawn as Enabled. The label is the enum's own (PortalState::label()). Colour is never the
    signal on its own (the glyph shape differs and the label is always visible text), and each state keeps the shape it has always had: Enabled a check, Disabled
    a triangle, Not enabled a dashed circle. It reads nothing from the database or the request.
--}}
@props(['state'])

@php
    if (! $state instanceof \App\Support\Organizations\PortalState) {
        throw new InvalidArgumentException('x-organization.portal-state needs a PortalState.');
    }

    [$tone, $glyph] = match ($state) {
        \App\Support\Organizations\PortalState::Enabled => ['success', 'check'],
        \App\Support\Organizations\PortalState::Disabled => ['warning', 'triangle'],
        \App\Support\Organizations\PortalState::NotEnabled => ['neutral', 'dashed'],
    };
@endphp

<x-ui.status :tone="$tone" :glyph="$glyph" data-portal-state="{{ $state->value }}" {{ $attributes }}>{{ $state->label() }}</x-ui.status>
