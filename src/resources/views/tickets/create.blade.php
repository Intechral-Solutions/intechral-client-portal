@extends('layouts.app', ['title' => 'New Ticket'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('tickets.index')" class="mb-4 inline-flex items-center gap-1 text-sm">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Back to tickets
        </x-ui.link>
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Submit a Ticket</h1>
    </div>

    <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        {{-- Title --}}
        <div class="space-y-2">
            <x-ui.label for="title" required>Subject</x-ui.label>
            <x-ui.input type="text" name="title" :value="old('title')" required maxlength="255" class="w-full" />
            <x-ui.field-error for="title" />
        </div>

        {{-- Category + Priority row --}}
        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
                <x-ui.label for="category" required>Category</x-ui.label>
                <x-ui.select name="category" required class="w-full">
                    <option value="">Select&hellip;</option>
                    @foreach ($categories as $cat)
                    <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.field-error for="category" />
            </div>
            <div class="space-y-2">
                <x-ui.label for="priority" required>Priority</x-ui.label>
                <x-ui.select name="priority" required class="w-full">
                    @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label)
                    <option value="{{ $val }}" {{ old('priority', 'medium') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.field-error for="priority" />
            </div>
        </div>

        {{-- Description --}}
        <div class="space-y-2">
            <x-ui.label for="description" required>Description</x-ui.label>
            <x-ui.textarea name="description" rows="7" required class="w-full">{{ old('description') }}</x-ui.textarea>
            <x-ui.field-error for="description" />
        </div>

        {{-- Company (shown when user belongs to multiple organizations) --}}
        @if ($companies->isNotEmpty())
        <div class="space-y-2">
            <x-ui.label for="company_id" required>Company</x-ui.label>
            <x-ui.select name="company_id" required class="w-full">
                <option value="">Select company&hellip;</option>
                @foreach ($companies as $company)
                <option value="{{ $company->id }}" {{ old('company_id') == $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.field-error for="company_id" />
        </div>
        @elseif ($autoCompanyId)
        <input type="hidden" name="company_id" value="{{ $autoCompanyId }}">
        @endif

        {{-- Attachments --}}
        <div class="space-y-2">
            <x-ui.label for="attachments">Attachments</x-ui.label>
            <x-ui.input type="file" name="attachments[]" id="attachments" multiple
                        :error-key="['attachments', 'attachments.*']"
                        aria-describedby="attachments-hint" class="w-full" />
            <p id="attachments-hint" class="text-xs" style="color: var(--text-secondary);">Up to 10 files, 20 MB each.</p>
            <x-ui.field-error for="attachments" :error-key="['attachments', 'attachments.*']" />
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3 pt-2">
            <x-ui.button type="submit">Submit Ticket</x-ui.button>
            <x-ui.button :href="route('tickets.index')" variant="ghost">Cancel</x-ui.button>
        </div>

    </form>

</div>
@endsection
