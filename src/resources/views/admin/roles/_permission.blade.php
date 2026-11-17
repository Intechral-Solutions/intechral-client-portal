{{--
    One permission checkbox of the role editor (create and edit). DIR-1: a permission that no code checks in this release is marked
    "Not used in this release" with a one-line reason (App\Shared\Permissions\UnusedPermissions). It stays an ordinary, assignable checkbox:
    the mark is information, never a disabled state, and it changes nothing about what is submitted or saved.

    @include('admin.roles._permission', ['permission' => 'org.admin', 'checked' => false])
--}}
@php
    $unusedReason = \App\Shared\Permissions\UnusedPermissions::reason($permission);
    $unusedId = 'permission-unused-'.\Illuminate\Support\Str::slug(str_replace('.', '-', $permission));
@endphp
<div class="rounded-lg border px-3 py-2 text-sm transition hover:bg-surface-hover border-rule">
    <label class="flex cursor-pointer items-center gap-2">
        <x-ui.checkbox name="permissions[]" value="{{ $permission }}" :checked="$checked"
                       :aria-describedby="$unusedReason !== null ? $unusedId : null" />
        <span class="text-text">{{ Str::after($permission, '.') }}</span>
    </label>
    @if ($unusedReason !== null)
    <p id="{{ $unusedId }}" class="mt-1.5 text-xs text-text-muted">
        <x-ui.tag>{{ \App\Shared\Permissions\UnusedPermissions::LABEL }}</x-ui.tag>
        {{ $unusedReason }}
    </p>
    @endif
</div>
