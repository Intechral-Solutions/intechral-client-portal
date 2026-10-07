@extends('layouts.app', ['title' => 'Edit Role: ' . $role->name])

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
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold text-text">{{ $role->name }}</h1>
            @if ($isBuiltIn)
            <x-ui.tag>Built-in</x-ui.tag>
            @endif
        </div>
        @if ($isBuiltIn)
        <p class="mt-1.5 text-sm text-text-secondary">
            This is a built-in role. Its name cannot be changed, but permissions can be updated.
        </p>
        @endif
    </div>

    {{-- Flash / error --}}
    @if (session('status'))
    <x-ui.alert variant="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('roles.update', $role) }}" class="space-y-8">
        @csrf
        @method('PUT')

        {{-- Permissions --}}
        <div id="permissions-group" role="group" aria-labelledby="permissions-heading"
             @if ($errors->has('permissions') || $errors->has('permissions.*')) aria-describedby="permissions-group-error" @endif>
            <p id="permissions-heading" class="text-sm font-medium mb-3 text-text">Permissions</p>
            @foreach ($grouped as $module => $permissions)
            <div class="mb-6">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">
                    {{ $module }}
                </p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($permissions as $permission)
                    @php $checked = in_array($permission, old('permissions', $assigned)); @endphp
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:bg-surface-hover border-rule">
                        <x-ui.checkbox name="permissions[]" value="{{ $permission }}" :checked="$checked" />
                        <span class="text-text">{{ Str::after($permission, '.') }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
        <x-ui.field-error for="permissions-group" :error-key="['permissions', 'permissions.*']" />

        {{-- Submit --}}
        <div class="flex items-center gap-3 pt-2">
            <x-ui.button type="submit">Save Changes</x-ui.button>
            <x-ui.button :href="route('roles.index')" variant="ghost">Cancel</x-ui.button>

            {{-- The delete form lives OUTSIDE this form (forms cannot nest: the parser would merge both into
                 this one, and its PUT and DELETE `_method` fields would collide). The button stays in this row
                 and is bound to that form through `form=`. --}}
            @can('roles.admin')
            @if (! $isBuiltIn)
            <x-ui.button type="submit" form="role-delete-form" variant="secondary" tone="danger" class="ml-auto">Delete Role</x-ui.button>
            @endif
            @endcan
        </div>

    </form>

    @can('roles.admin')
    @if (! $isBuiltIn)
    <form id="role-delete-form" method="POST" action="{{ route('roles.destroy', $role) }}"
          onsubmit="return confirm('Delete role \'{{ addslashes($role->name) }}\'?')">
        @csrf
        @method('DELETE')
    </form>
    @endif
    @endcan

</div>
@endsection
