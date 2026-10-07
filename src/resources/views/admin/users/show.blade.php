@extends('layouts.app', ['title' => $user->name])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8 space-y-8">

    {{-- Header --}}
    <div>
        <x-ui.link variant="quiet" :href="route('users.index')" class="mb-4 inline-flex items-center gap-1 text-sm">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Back to Users
        </x-ui.link>
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-text">{{ $user->name }}</h1>
                <p class="mt-0.5 text-sm text-text-secondary">{{ $user->email }}</p>
            </div>
        </div>
    </div>

    {{-- Flash / errors --}}
    @if (session('status'))
    <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
    <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid gap-8 lg:grid-cols-3">

        {{-- Left column: details --}}
        <div class="space-y-8 lg:col-span-2">

            {{-- Account info --}}
            <section class="rounded-lg border p-6 border-rule bg-surface">
                <h2 class="mb-4 text-base font-semibold text-text">Account</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-text-secondary">Member since</dt>
                        <dd class="text-text">{{ $user->created_at->format('d M Y') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-text-secondary">2FA</dt>
                        <dd>
                            @if ($user->two_factor_confirmed_at)
                            <x-ui.status tone="success">Enabled</x-ui.status>
                            @else
                            <x-ui.status glyph="dashed">Disabled</x-ui.status>
                            @endif
                        </dd>
                    </div>
                    @if ($user->invitation)
                    <div class="flex justify-between gap-4">
                        <dt class="text-text-secondary">Invited by</dt>
                        <dd class="text-text">
                            {{ $user->invitation->invitedBy?->name ?? '—' }}
                        </dd>
                    </div>
                    @endif
                    @if ($user->socialAccounts->isNotEmpty())
                    <div class="flex justify-between gap-4">
                        <dt class="text-text-secondary">SSO providers</dt>
                        <dd class="flex gap-1 flex-wrap">
                            @foreach ($user->socialAccounts as $account)
                            <x-ui.tag>{{ $account->provider }}</x-ui.tag>
                            @endforeach
                        </dd>
                    </div>
                    @endif
                </dl>
            </section>

            {{-- Activity log --}}
            <section class="rounded-lg border p-6 border-rule bg-surface">
                <h2 class="mb-4 text-base font-semibold text-text">Recent Activity</h2>
                @if ($activity->isEmpty())
                <p class="text-sm text-text-secondary">No activity recorded.</p>
                @else
                <ol class="space-y-3">
                    @foreach ($activity as $log)
                    <li class="flex items-start justify-between gap-4 text-sm">
                        <div>
                            <span class="text-text">{{ $log->description }}</span>
                            @if ($log->properties->isNotEmpty())
                            <p class="text-xs mt-0.5 font-mono text-text-secondary">
                                {{ $log->properties->except('ip')->toJson() }}
                            </p>
                            @endif
                        </div>
                        <time class="shrink-0 text-xs text-text-secondary"
                              datetime="{{ $log->created_at->toIso8601String() }}">
                            {{ $log->created_at->diffForHumans() }}
                        </time>
                    </li>
                    @endforeach
                </ol>
                @endif
            </section>

        </div>

        {{-- Right column: roles --}}
        @can('users.manage')
        <aside>
            <section class="rounded-lg border p-6 border-rule bg-surface">
                <h2 id="user-roles-heading" class="mb-4 text-base font-semibold text-text">Roles</h2>
                <form method="POST" action="{{ route('users.roles.update', $user) }}">
                    @csrf
                    @method('PUT')
                    <div id="user-roles-group" role="group" aria-labelledby="user-roles-heading"
                         @if ($errors->has('roles') || $errors->has('roles.*')) aria-describedby="user-roles-group-error" @endif
                         class="space-y-2 mb-4">
                        @foreach ($allRoles as $role)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition hover:bg-surface-hover border-rule">
                            <x-ui.checkbox name="roles[]" value="{{ $role->name }}"
                                           :checked="$user->hasRole($role->name)" />
                            <span class="text-text">{{ $role->name }}</span>
                        </label>
                        @endforeach
                    </div>
                    <x-ui.field-error for="user-roles-group" :error-key="['roles', 'roles.*']" class="mb-4" />
                    <x-ui.button type="submit" class="w-full">Update Roles</x-ui.button>
                </form>
            </section>
        </aside>
        @endcan

    </div>

</div>
@endsection
