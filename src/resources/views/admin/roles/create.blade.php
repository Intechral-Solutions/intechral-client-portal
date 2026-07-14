@extends('layouts.app', ['title' => 'Create Role'])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="mb-8">
        <a href="{{ route('roles.index') }}"
           class="mb-4 inline-flex items-center gap-1 text-sm transition-colors hover:underline"
           style="color: var(--text-secondary);">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Back to Roles
        </a>
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Create Role</h1>
    </div>

    <form method="POST" action="{{ route('roles.store') }}" class="space-y-8">
        @csrf

        {{-- Name --}}
        <div>
            <label for="name" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Role name <span style="color: var(--text-danger);">*</span>
            </label>
            <input type="text" id="name" name="name" value="{{ old('name') }}"
                   required maxlength="64" autocomplete="off"
                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--surface-input); border-color: {{ $errors->has('name') ? 'var(--border-danger)' : 'var(--border-base)' }}; color: var(--text-primary);"
                   placeholder="e.g. account-manager">
            @error('name')
            <p class="mt-1.5 text-xs" style="color: var(--text-danger);">{{ $message }}</p>
            @enderror
            <p class="mt-1.5 text-xs" style="color: var(--text-secondary);">
                Use lowercase letters, numbers, and hyphens only.
            </p>
        </div>

        {{-- Permissions --}}
        <div>
            <p class="text-sm font-medium mb-3" style="color: var(--text-primary);">Permissions</p>
            @foreach ($grouped as $module => $permissions)
            <div class="mb-6">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                    {{ $module }}
                </p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($permissions as $permission)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:bg-surface"
                           style="border-color: var(--border-base);">
                        <input type="checkbox" name="permissions[]" value="{{ $permission }}"
                               {{ in_array($permission, old('permissions', [])) ? 'checked' : '' }}
                               class="h-4 w-4 rounded accent-accent">
                        <span style="color: var(--text-primary);">{{ Str::after($permission, '.') }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>

        {{-- Submit --}}
        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-lg px-5 py-2 text-sm font-medium transition-colors"
                    style="background-color: var(--accent); color: #fff;">
                Create Role
            </button>
            <a href="{{ route('roles.index') }}"
               class="rounded-lg px-5 py-2 text-sm font-medium transition-colors hover:bg-surface"
               style="color: var(--text-secondary);">
                Cancel
            </a>
        </div>

    </form>

</div>
@endsection
