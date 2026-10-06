{{-- Shared form fields for create / edit --}}

<div class="grid grid-cols-2 gap-4">
    <div class="space-y-2">
        <x-ui.label for="first_name" required>First Name</x-ui.label>
        <x-ui.input type="text" name="first_name" :value="old('first_name', $contact->first_name ?? '')" required class="w-full" />
        <x-ui.field-error for="first_name" />
    </div>
    <div class="space-y-2">
        <x-ui.label for="last_name" required>Last Name</x-ui.label>
        <x-ui.input type="text" name="last_name" :value="old('last_name', $contact->last_name ?? '')" required class="w-full" />
        <x-ui.field-error for="last_name" />
    </div>
</div>

<div class="space-y-2">
    <x-ui.label for="crm_company_id">Company</x-ui.label>
    <x-ui.select name="crm_company_id" class="w-full">
        <option value="">No company</option>
        @foreach ($companies as $company)
        <option value="{{ $company->id }}"
                @selected(old('crm_company_id', $contact->crm_company_id ?? $companyId ?? '') == $company->id)>
            {{ $company->name }}
        </option>
        @endforeach
    </x-ui.select>
    <x-ui.field-error for="crm_company_id" />
</div>

<div class="grid grid-cols-2 gap-4">
    <div class="space-y-2">
        <x-ui.label for="email">Email</x-ui.label>
        <x-ui.input type="email" name="email" :value="old('email', $contact->email ?? '')" class="w-full" />
        <x-ui.field-error for="email" />
    </div>
    <div class="space-y-2">
        <x-ui.label for="phone">Phone</x-ui.label>
        <x-ui.input type="text" name="phone" :value="old('phone', $contact->phone ?? '')" class="w-full" />
        <x-ui.field-error for="phone" />
    </div>
</div>

<div class="space-y-2">
    <x-ui.label for="job_title">Job Title</x-ui.label>
    <x-ui.input type="text" name="job_title" :value="old('job_title', $contact->job_title ?? '')" class="w-full" />
    <x-ui.field-error for="job_title" />
</div>

<div class="space-y-2">
    <x-ui.label for="notes">Notes</x-ui.label>
    <x-ui.textarea name="notes" rows="3" class="w-full">{{ old('notes', $contact->notes ?? '') }}</x-ui.textarea>
    <x-ui.field-error for="notes" />
</div>
