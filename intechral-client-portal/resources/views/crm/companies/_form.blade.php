{{-- Shared form fields for create / edit --}}

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Company Name *</label>
    <input type="text" name="name" value="{{ old('name', $company->name ?? '') }}" required
           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
    @error('name')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Website</label>
    <input type="url" name="website" value="{{ old('website', $company->website ?? '') }}"
           placeholder="https://example.com"
           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
    @error('website')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Phone</label>
    <input type="text" name="phone" value="{{ old('phone', $company->phone ?? '') }}"
           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
    @error('phone')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Address</label>
    <textarea name="address" rows="2"
              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('address', $company->address ?? '') }}</textarea>
    @error('address')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Notes</label>
    <textarea name="notes" rows="3"
              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('notes', $company->notes ?? '') }}</textarea>
    @error('notes')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>
