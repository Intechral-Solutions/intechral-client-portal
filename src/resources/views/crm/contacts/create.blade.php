@extends('layouts.app', ['title' => 'New Contact'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('crm.contacts.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Contacts</a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">New Contact</h1>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('crm.contacts.store') }}" class="space-y-4">
            @csrf
            @include('crm.contacts._form', ['contact' => null, 'companyId' => $companyId])
            <div class="flex gap-3 pt-2">
                <button type="submit" class="rounded-lg px-5 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Create Contact</button>
                <a href="{{ route('crm.contacts.index') }}" class="rounded-lg border px-5 py-2 text-sm font-medium"
                   style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
