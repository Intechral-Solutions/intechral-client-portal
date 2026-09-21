{{--
    One editable member row. Every value is emitted through Blade's escaping, so a user name
    or email can never break out of the markup (EPIC-011E S1). The same partial renders the
    existing rows and the <template> used to add new ones.

    Props:
        $index       — array index for the members[…] field names (or the __INDEX__ placeholder)
        $candidates  — [{id, name, email}, …] the actor is allowed to see (administrators only)
        $selectedId  — currently selected user id, or null for a blank row
        $role        — 'member' | 'manager'
        $owner       — true for the creator's locked row (no remove button)
--}}
@php $owner = $owner ?? false; @endphp
<div class="flex gap-2 member-row">
    <select name="members[{{ $index }}][user_id]" class="flex-1 rounded-lg border px-3 py-2 text-sm outline-none"
            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        @if ($selectedId === null)
        <option value="">Select user&hellip;</option>
        @endif
        @foreach ($candidates as $candidate)
        <option value="{{ $candidate['id'] }}" @selected((string) $selectedId === (string) $candidate['id'])>{{ $candidate['name'] }} &lt;{{ $candidate['email'] }}&gt;</option>
        @endforeach
    </select>
    <select name="members[{{ $index }}][role]" class="rounded-lg border px-3 py-2 text-sm outline-none"
            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <option value="member" @selected($role === 'member')>Member</option>
        <option value="manager" @selected($role === 'manager')>Manager</option>
    </select>
    @if ($owner)
    <span class="rounded-lg border px-2 py-1 text-xs flex items-center"
          style="border-color: var(--border-base); color: var(--text-muted);">Owner</span>
    @else
    <button type="button" data-remove-member aria-label="Remove member"
            class="rounded-lg border px-2 py-1 text-sm transition-colors hover:opacity-80"
            style="border-color: var(--border-base); color: var(--text-danger);">&times;</button>
    @endif
</div>
