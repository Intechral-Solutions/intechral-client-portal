{{-- Shared form fields for create / edit --}}

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">First Name *</label>
        <input type="text" name="first_name" value="{{ old('first_name', $contact->first_name ?? '') }}" required
               class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        @error('first_name')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Last Name *</label>
        <input type="text" name="last_name" value="{{ old('last_name', $contact->last_name ?? '') }}" required
               class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        @error('last_name')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
    </div>
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Company</label>
    <select name="crm_company_id"
            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <option value="">No company</option>
        @foreach ($companies as $company)
        <option value="{{ $company->id }}"
                @selected(old('crm_company_id', $contact->crm_company_id ?? $companyId ?? '') == $company->id)>
            {{ $company->name }}
        </option>
        @endforeach
    </select>
    @error('crm_company_id')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Email</label>
        <input type="email" name="email" value="{{ old('email', $contact->email ?? '') }}"
               class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        @error('email')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Phone</label>
        <input type="text" name="phone" value="{{ old('phone', $contact->phone ?? '') }}"
               class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        @error('phone')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
    </div>
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Job Title</label>
    <input type="text" name="job_title" value="{{ old('job_title', $contact->job_title ?? '') }}"
           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
    @error('job_title')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Notes</label>
    <textarea name="notes" rows="3"
              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('notes', $contact->notes ?? '') }}</textarea>
    @error('notes')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>
