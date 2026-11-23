{{-- Shared form fields for create / edit. $organization is absent on create. --}}

<div class="space-y-2">
    <x-ui.label for="name" required>Organization name</x-ui.label>
    <x-ui.input type="text" name="name" :value="old('name', $organization->name ?? '')" required class="w-full" />
    <x-ui.field-error for="name" />
</div>

<div class="space-y-2">
    <x-ui.label for="website">Website</x-ui.label>
    <x-ui.input type="url" name="website" :value="old('website', $organization->website ?? '')"
                placeholder="https://example.com" class="w-full" />
    <x-ui.field-error for="website" />
</div>

<div class="space-y-2">
    <x-ui.label for="phone">Phone</x-ui.label>
    <x-ui.input type="text" name="phone" :value="old('phone', $organization->phone ?? '')" class="w-full" />
    <x-ui.field-error for="phone" />
</div>

<div class="space-y-2">
    <x-ui.label for="address">Address</x-ui.label>
    <x-ui.textarea name="address" rows="2" class="w-full">{{ old('address', $organization->address ?? '') }}</x-ui.textarea>
    <x-ui.field-error for="address" />
</div>

<div class="space-y-2">
    <x-ui.label for="notes">Notes</x-ui.label>
    <x-ui.textarea name="notes" rows="3" class="w-full">{{ old('notes', $organization->notes ?? '') }}</x-ui.textarea>
    <x-ui.field-error for="notes" />
</div>
