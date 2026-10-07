@extends('layouts.app', ['title' => 'New Contact'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('crm.contacts.index')" class="text-sm">&larr; Contacts</x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold text-text">New Contact</h1>
    </div>

    <div class="rounded-lg border p-6 bg-surface border-rule">
        <form method="POST" action="{{ route('crm.contacts.store') }}" class="space-y-4">
            @csrf
            @include('crm.contacts._form', ['contact' => null, 'companyId' => $companyId])
            <div class="flex gap-3 pt-2">
                <x-ui.button type="submit">Create Contact</x-ui.button>
                <x-ui.button :href="route('crm.contacts.index')" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    </div>
</div>
@endsection
