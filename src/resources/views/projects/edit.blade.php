@extends('layouts.app', ['title' => 'Edit Project'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6">
        <a href="{{ route('projects.board', $project) }}" class="inline-flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            {{ $project->name }}
        </a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">Edit Project</h1>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Project Details --}}
    <form method="POST" action="{{ route('projects.update', $project) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="rounded-xl border p-6 space-y-5"
             style="background-color: var(--surface-card); border-color: var(--border-base);">

            <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Project Details</h2>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="name">Project Name <span style="color: var(--text-danger);">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @error('name')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="description">Description</label>
                <textarea id="description" name="description" rows="3"
                          class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                          style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('description', $project->description) }}</textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="target_date">Target Date</label>
                    <input type="date" id="target_date" name="target_date" value="{{ old('target_date', $project->target_date?->format('Y-m-d')) }}"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="status">Status</label>
                    <select id="status" name="status"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        @foreach (['active' => 'Active', 'on_hold' => 'On Hold', 'completed' => 'Completed', 'archived' => 'Archived'] as $val => $label)
                        <option value="{{ $val }}" @selected(old('status', $project->status) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="budget">Budget ($)</label>
                    <input type="number" id="budget" name="budget" value="{{ old('budget', $project->budget) }}" min="0" step="0.01"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <button type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                    style="background-color: var(--accent); color: #fff;">Save Changes</button>
        </div>
    </form>

    {{-- Danger zone: a sibling of the details form, never nested inside it (EPIC-011E S2) --}}
    <div class="mt-8 rounded-xl border p-6"
         style="background-color: var(--surface-card); border-color: var(--border-danger);">
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-danger);">Danger Zone</h2>
        <p class="mb-4 text-xs" style="color: var(--text-secondary);">Deleting a project removes its board, tasks and milestones. A project with recorded time cannot be deleted; set its status to Archived instead.</p>
        @error('delete')
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm" role="alert"
             style="background-color: var(--surface-danger); border-color: var(--border-danger); color: var(--text-danger);">{{ $message }}</div>
        @enderror
        <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('Delete this project? This cannot be undone.')">
            @csrf @method('DELETE')
            <button type="submit" class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors"
                    style="border-color: var(--border-danger); color: var(--text-danger);">Delete Project</button>
        </form>
    </div>

    {{-- Company Management --}}
    @if ($companies->isNotEmpty())
    <div class="mt-8 rounded-xl border p-6"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Linked Companies</h2>
        <p class="mb-4 text-xs" style="color: var(--text-secondary);">Linking a company records the client relationship. It does not grant that company's organization members access to the project; only project members (and administrators) can open it.</p>

        <form method="POST" action="{{ route('projects.companies.sync', $project) }}">
            @csrf
            @method('PUT')

            <div class="space-y-2 mb-4">
                @foreach ($companies as $company)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="companies[]" value="{{ $company->id }}"
                           {{ in_array($company->id, $linkedCompanyIds) ? 'checked' : '' }}
                           class="rounded border"
                           style="accent-color: var(--accent);">
                    <span class="text-sm" style="color: var(--text-primary);">{{ $company->name }}</span>
                </label>
                @endforeach
            </div>

            <div class="flex justify-end">
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        style="background-color: var(--accent); color: #fff;">Update Companies</button>
            </div>
        </form>
    </div>
    @endif

    {{-- Members --}}
    <div class="mt-8 rounded-xl border p-6"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Members</h2>

        @isset($memberCandidates)
        <form method="POST" action="{{ route('projects.members.sync', $project) }}" class="space-y-4">
            @csrf
            @method('PUT')

            @include('projects.partials.member-editor', [
                'candidates' => $memberCandidates,
                'rows' => collect($members)->map(fn ($member) => [
                    'user_id' => $member['id'],
                    'role' => $member['role'],
                    'owner' => $member['isOwner'],
                ])->all(),
            ])

            <div class="flex justify-end">
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        style="background-color: var(--accent); color: #fff;">Update Members</button>
            </div>
        </form>
        @else
        <p class="mb-3 text-xs" style="color: var(--text-secondary);">Project membership is managed by an administrator.</p>
        <ul class="space-y-2">
            @foreach ($members as $member)
            <li class="flex items-center justify-between text-sm">
                <span style="color: var(--text-primary);">{{ $member['name'] }}</span>
                <span class="text-xs" style="color: var(--text-muted);">
                    {{ ucfirst($member['role']) }}@if ($member['isOwner']) &middot; Owner @endif
                </span>
            </li>
            @endforeach
        </ul>
        @endisset
    </div>

</div>

@endsection
