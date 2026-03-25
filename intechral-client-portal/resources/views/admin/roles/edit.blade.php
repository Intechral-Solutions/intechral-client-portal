@extends('layouts.app', ['title' => 'Edit Role: ' . $role->name])

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
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">{{ $role->name }}</h1>
            @if ($isBuiltIn)
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                  style="background-color: var(--surface-accent); color: var(--accent);">
                Built-in
            </span>
            @endif
        </div>
        @if ($isBuiltIn)
        <p class="mt-1.5 text-sm" style="color: var(--text-secondary);">
            This is a built-in role. Its name cannot be changed, but permissions can be updated.
        </p>
        @endif
    </div>

    {{-- Flash / error --}}
    @if (session('status'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">
        {{ session('status') }}
    </div>
    @endif

    <form method="POST" action="{{ route('roles.update', $role) }}" class="space-y-8">
        @csrf
        @method('PUT')

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
                    @php $checked = in_array($permission, old('permissions', $assigned)); @endphp
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:bg-surface"
                           style="border-color: var(--border-base);">
                        <input type="checkbox" name="permissions[]" value="{{ $permission }}"
                               {{ $checked ? 'checked' : '' }}
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
                Save Changes
            </button>
            <a href="{{ route('roles.index') }}"
               class="rounded-lg px-5 py-2 text-sm font-medium transition-colors hover:bg-surface"
               style="color: var(--text-secondary);">
                Cancel
            </a>

            @can('roles.admin')
            @if (! $isBuiltIn)
            <form method="POST" action="{{ route('roles.destroy', $role) }}" class="ml-auto"
                  onsubmit="return confirm('Delete role \'{{ addslashes($role->name) }}\'?')">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-lg px-5 py-2 text-sm font-medium transition-colors hover:bg-surface"
                        style="color: var(--text-danger);">
                    Delete Role
                </button>
            </form>
            @endif
            @endcan
        </div>

    </form>

</div>
@endsection
