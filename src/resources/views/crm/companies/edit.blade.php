@extends('layouts.app', ['title' => 'Edit Company'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('crm.companies.show', $company) }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; {{ $company->name }}</a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">Edit Company</h1>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('crm.companies.update', $company) }}" class="space-y-4">
            @csrf @method('PUT')
            @include('crm.companies._form')
            <div class="flex gap-3 pt-2">
                <button type="submit" class="rounded-lg px-5 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Save Changes</button>
                <a href="{{ route('crm.companies.show', $company) }}" class="rounded-lg border px-5 py-2 text-sm font-medium"
                   style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            </div>
        </form>
    </div>

    {{-- Danger zone --}}
    <div class="mt-8 rounded-xl border p-5" style="border-color: var(--border-danger); background-color: var(--surface-card);">
        <h2 class="mb-2 text-sm font-semibold" style="color: var(--text-danger);">Delete Company</h2>
        <p class="mb-4 text-xs" style="color: var(--text-muted);">This will permanently remove the company and all its contacts.</p>
        <form method="POST" action="{{ route('crm.companies.destroy', $company) }}"
              onsubmit="return confirm('Delete {{ addslashes($company->name) }}? This cannot be undone.')">
            @csrf @method('DELETE')
            <button type="submit" class="rounded-lg px-4 py-2 text-xs font-medium"
                    style="background-color: var(--surface-danger); color: var(--text-danger);">Delete Company</button>
        </form>
    </div>
</div>
@endsection
