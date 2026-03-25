<x-layouts.auth title="Invitation Invalid" subtitle="This invitation link is not valid">

    <div class="text-center py-4">
        <div class="mb-4 text-4xl">⚠️</div>
        <p class="text-sm mb-6" style="color: var(--text-secondary);">
            This invitation link has expired, already been used, or does not exist.
            Please contact your administrator for a new invitation.
        </p>
        <a href="{{ route('login') }}"
           class="inline-block rounded-lg px-4 py-2 text-sm font-semibold transition"
           style="background-color: var(--accent); color: var(--accent-text);">
            Back to sign in
        </a>
    </div>

</x-layouts.auth>
