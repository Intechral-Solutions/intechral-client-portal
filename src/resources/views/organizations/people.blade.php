@extends('layouts.app', ['title' => $organization->name.' people', 'breadcrumbTrail' => [[$organization->name, null]]])

@section('content')
{{-- People (ORG-3b): the Directory records affiliated with this Organization (CrmContact, visible to operators only). They are NOT portal members: membership,
     invitations and approvals are on Portal access, and nothing on this page grants or reads any of them. --}}
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <x-organization.workspace-header :header="$header" />

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    <section aria-labelledby="org-people-heading" class="rounded-lg border overflow-hidden bg-surface border-rule">
        <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-4 border-b border-rule">
            <div>
                <h2 id="org-people-heading" class="text-sm font-semibold text-text">People</h2>
                <p class="mt-1 text-xs text-text-muted">Affiliation records where a person works. It grants no portal access.</p>
            </div>
            <x-ui.link :href="route('crm.contacts.create', ['company_id' => $organization->id])" class="text-xs font-medium">+ Add Person</x-ui.link>
        </div>
        <div role="region" tabindex="0" aria-label="People list" class="overflow-x-auto focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-rule">
                    @forelse ($people as $person)
                    <tr>
                        <td class="px-5 py-3">
                            <x-ui.link variant="row" :href="route('crm.contacts.show', $person)">{{ $person->fullName() }}</x-ui.link>
                            @if ($person->job_title)
                            <span class="ml-1 text-xs text-text-muted">— {{ $person->job_title }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs text-text-secondary">{{ $person->email ?? '' }}</td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-8 text-center text-sm text-text-muted">No people yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $people->links() }}</div>
</div>
@endsection
