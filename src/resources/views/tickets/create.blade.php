@extends('layouts.app', ['title' => 'New Ticket'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('tickets.index') }}"
           class="mb-4 inline-flex items-center gap-1 text-sm transition-colors hover:underline"
           style="color: var(--text-secondary);">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Back to tickets
        </a>
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Submit a Ticket</h1>
    </div>

    <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        {{-- Title --}}
        <div>
            <label for="title" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Subject <span style="color: var(--text-danger);">*</span>
            </label>
            <input type="text" id="title" name="title" value="{{ old('title') }}"
                   required maxlength="255"
                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--surface-input); border-color: {{ $errors->has('title') ? 'var(--border-danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
            @error('title')<p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>

        {{-- Category + Priority row --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="category" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                    Category <span style="color: var(--text-danger);">*</span>
                </label>
                <select id="category" name="category" required
                        class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                        style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    <option value="">Select&hellip;</option>
                    @foreach ($categories as $cat)
                    <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
                @error('category')<p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="priority" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                    Priority <span style="color: var(--text-danger);">*</span>
                </label>
                <select id="priority" name="priority" required
                        class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                        style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label)
                    <option value="{{ $val }}" {{ old('priority', 'medium') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('priority')<p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Description --}}
        <div>
            <label for="description" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Description <span style="color: var(--text-danger);">*</span>
            </label>
            <textarea id="description" name="description" rows="7" required
                      class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2 resize-y"
                      style="background-color: var(--surface-input); border-color: {{ $errors->has('description') ? 'var(--border-danger)' : 'var(--border-base)' }}; color: var(--text-primary);">{{ old('description') }}</textarea>
            @error('description')<p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>

        {{-- Company (shown when user belongs to multiple organizations) --}}
        @if ($companies->isNotEmpty())
        <div>
            <label for="company_id" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Company <span style="color: var(--text-danger);">*</span>
            </label>
            <select id="company_id" name="company_id" required
                    class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                    style="background-color: var(--surface-input); border-color: {{ $errors->has('company_id') ? 'var(--border-danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                <option value="">Select company&hellip;</option>
                @foreach ($companies as $company)
                <option value="{{ $company->id }}" {{ old('company_id') == $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                @endforeach
            </select>
            @error('company_id')<p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>
        @elseif ($autoCompanyId)
        <input type="hidden" name="company_id" value="{{ $autoCompanyId }}">
        @endif

        {{-- Attachments --}}
        <div>
            <label class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">Attachments</label>
            <input type="file" name="attachments[]" multiple
                   class="block w-full text-sm"
                   style="color: var(--text-secondary);">
            <p class="mt-1.5 text-xs" style="color: var(--text-secondary);">Up to 10 files, 20 MB each.</p>
            @error('attachments.*')<p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-lg px-5 py-2 text-sm font-medium transition-colors"
                    style="background-color: var(--accent); color: #fff;">
                Submit Ticket
            </button>
            <a href="{{ route('tickets.index') }}"
               class="rounded-lg px-5 py-2 text-sm font-medium transition-colors hover:legacy-bg-surface"
               style="color: var(--text-secondary);">Cancel</a>
        </div>

    </form>

</div>
@endsection
