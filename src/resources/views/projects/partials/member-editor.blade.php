{{--
    Add/remove member rows (administrators only). New rows are cloned from a server-rendered,
    escaped <template>; no markup is ever assembled from user data in the browser.

    Props:
        $candidates — [{id, name, email}, …]
        $rows       — existing rows: [['user_id' => int|string|null, 'role' => string, 'owner' => bool], …]
--}}
<div id="members-container" class="space-y-2">
    @foreach ($rows as $i => $row)
    @include('projects.partials.member-row', [
        'index' => $i,
        'candidates' => $candidates,
        'selectedId' => $row['user_id'] ?? null,
        'role' => $row['role'] ?? 'member',
        'owner' => $row['owner'] ?? false,
    ])
    @endforeach
</div>

<button type="button" id="add-member" class="text-sm font-medium" style="color: var(--accent);">+ Add member</button>

<template id="member-row-template">
    @include('projects.partials.member-row', [
        'index' => '__INDEX__',
        'candidates' => $candidates,
        'selectedId' => null,
        'role' => 'member',
        'owner' => false,
    ])
</template>

@push('scripts')
<script>
(function () {
    const container = document.getElementById('members-container');
    const template = document.getElementById('member-row-template');
    let nextIndex = {{ count($rows) }};

    document.getElementById('add-member').addEventListener('click', function () {
        const row = template.content.cloneNode(true);
        row.querySelectorAll('[name]').forEach(function (field) {
            field.setAttribute('name', field.getAttribute('name').replace('__INDEX__', nextIndex));
        });
        container.appendChild(row);
        nextIndex++;
    });

    container.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-remove-member]');
        if (remove) {
            remove.closest('.member-row').remove();
        }
    });
})();
</script>
@endpush
