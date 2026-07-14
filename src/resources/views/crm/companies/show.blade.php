@extends('layouts.app', ['title' => $company->name])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <a href="{{ route('crm.companies.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Companies</a>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $company->name }}</h1>
            @if ($company->website)
            <a href="{{ $company->website }}" target="_blank" rel="noopener"
               class="mt-0.5 text-sm hover:underline" style="color: var(--accent);">
                {{ parse_url($company->website, PHP_URL_HOST) }} ↗
            </a>
            @endif
        </div>
        <div class="flex gap-2">
            @if (! $company->isPromoted())
            <form method="POST" action="{{ route('crm.companies.promote', $company) }}">
                @csrf
                <button type="submit"
                        onclick="return confirm('Promote {{ addslashes($company->name) }} to an Organization?')"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        style="border-color: var(--border-base); color: var(--text-secondary);">
                    Promote to Org
                </button>
            </form>
            @else
            <a href="{{ route('organizations.show', $company->organization) }}"
               class="rounded-lg border px-4 py-2 text-sm font-medium"
               style="border-color: var(--border-success); color: var(--text-success);">View Organization ↗</a>
            @endif
            <a href="{{ route('crm.companies.edit', $company) }}"
               class="rounded-lg border px-4 py-2 text-sm font-medium"
               style="border-color: var(--border-base); color: var(--text-secondary);">Edit</a>
        </div>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Details --}}
        <div class="lg:col-span-1 rounded-xl border p-5 space-y-3" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <h2 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Details</h2>
            @if ($company->phone)
            <div><p class="text-xs" style="color: var(--text-muted);">Phone</p><p class="text-sm" style="color: var(--text-primary);">{{ $company->phone }}</p></div>
            @endif
            @if ($company->address)
            <div><p class="text-xs" style="color: var(--text-muted);">Address</p><p class="text-sm whitespace-pre-line" style="color: var(--text-primary);">{{ $company->address }}</p></div>
            @endif
            @if ($company->notes)
            <div><p class="text-xs" style="color: var(--text-muted);">Notes</p><p class="text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ $company->notes }}</p></div>
            @endif
        </div>

        {{-- Contacts --}}
        <div class="lg:col-span-2 rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <div class="flex items-center justify-between px-5 py-4 border-b" style="border-color: var(--border-base);">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Contacts</h2>
                <a href="{{ route('crm.contacts.create', ['company_id' => $company->id]) }}"
                   class="text-xs font-medium hover:underline" style="color: var(--accent);">+ Add Contact</a>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y" style="divide-color: var(--border-base);">
                    @forelse ($company->contacts as $contact)
                    <tr>
                        <td class="px-5 py-3">
                            <a href="{{ route('crm.contacts.show', $contact) }}" class="font-medium hover:underline" style="color: var(--accent);">
                                {{ $contact->fullName() }}
                            </a>
                            @if ($contact->job_title)
                            <span class="text-xs ml-1" style="color: var(--text-muted);">— {{ $contact->job_title }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs" style="color: var(--text-secondary);">{{ $contact->email ?? '' }}</td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">No contacts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
