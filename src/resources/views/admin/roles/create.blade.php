@extends('layouts.app', ['title' => 'Create Role'])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('roles.index')" class="mb-4 inline-flex items-center gap-1 text-sm">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Back to Roles
        </x-ui.link>
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Create Role</h1>
    </div>

    <form method="POST" action="{{ route('roles.store') }}" class="space-y-8">
        @csrf

        {{-- Name --}}
        <div class="space-y-2">
            <x-ui.label for="name" required>Role name</x-ui.label>
            <x-ui.input type="text" name="name" :value="old('name')"
                        required maxlength="64" autocomplete="off"
                        placeholder="e.g. account-manager" aria-describedby="name-hint" class="w-full" />
            <x-ui.field-error for="name" />
            <p id="name-hint" class="text-xs" style="color: var(--text-secondary);">
                Use lowercase letters, numbers, and hyphens only.
            </p>
        </div>

        {{-- Permissions --}}
        <div id="permissions-group" role="group" aria-labelledby="permissions-heading"
             @if ($errors->has('permissions') || $errors->has('permissions.*')) aria-describedby="permissions-group-error" @endif>
            <p id="permissions-heading" class="text-sm font-medium mb-3" style="color: var(--text-primary);">Permissions</p>
            @foreach ($grouped as $module => $permissions)
            <div class="mb-6">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                    {{ $module }}
                </p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($permissions as $permission)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:legacy-bg-surface"
                           style="border-color: var(--border-base);">
                        <x-ui.checkbox name="permissions[]" value="{{ $permission }}"
                                       :checked="in_array($permission, old('permissions', []))" />
                        <span style="color: var(--text-primary);">{{ Str::after($permission, '.') }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
        <x-ui.field-error for="permissions-group" :error-key="['permissions', 'permissions.*']" />

        {{-- Submit --}}
        <div class="flex items-center gap-3 pt-2">
            <x-ui.button type="submit">Create Role</x-ui.button>
            <x-ui.button :href="route('roles.index')" variant="ghost">Cancel</x-ui.button>
        </div>

    </form>

</div>
@endsection
