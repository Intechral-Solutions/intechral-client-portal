{{--
  Shared invoice form partial.
  Variables: $clients, $projects, $invoice (optional, for edit mode)
--}}
@php $isEdit = isset($invoice); @endphp

{{-- Details --}}
<div class="rounded-xl border p-6 space-y-5"
     style="background-color: var(--surface-card); border-color: var(--border-base);">
    <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Invoice Details</h2>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="space-y-2">
            <x-ui.label for="client_id" required>Client</x-ui.label>
            <x-ui.select name="client_id" required class="w-full">
                <option value="">Select client…</option>
                @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $isEdit ? $invoice->client_id : '') == $client->id)>
                    {{ $client->name }} &lt;{{ $client->email }}&gt;
                </option>
                @endforeach
            </x-ui.select>
            <x-ui.field-error for="client_id" />
        </div>

        <div class="space-y-2">
            <x-ui.label for="project_id">Project</x-ui.label>
            <x-ui.select name="project_id" class="w-full">
                <option value="">None</option>
                @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected(old('project_id', $isEdit ? $invoice->project_id : '') == $project->id)>
                    {{ $project->name }}
                </option>
                @endforeach
            </x-ui.select>
            <x-ui.field-error for="project_id" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="space-y-2">
            <x-ui.label for="issued_at" required>Issued</x-ui.label>
            <x-ui.input type="date" name="issued_at" required
                        :value="old('issued_at', $isEdit ? $invoice->issued_at->format('Y-m-d') : today()->format('Y-m-d'))"
                        class="w-full" />
            <x-ui.field-error for="issued_at" />
        </div>
        <div class="space-y-2">
            <x-ui.label for="due_at" required>Due</x-ui.label>
            <x-ui.input type="date" name="due_at" required
                        :value="old('due_at', $isEdit ? $invoice->due_at->format('Y-m-d') : today()->addDays(30)->format('Y-m-d'))"
                        class="w-full" />
            <x-ui.field-error for="due_at" />
        </div>
        <div class="space-y-2">
            <x-ui.label for="tax_rate">Tax Rate (%)</x-ui.label>
            <x-ui.input type="number" name="tax_rate" min="0" max="100" step="0.01"
                        :value="old('tax_rate', $isEdit ? $invoice->tax_rate : '0')" class="w-full" />
            <x-ui.field-error for="tax_rate" />
        </div>
        <div class="space-y-2">
            <x-ui.label for="currency">Currency</x-ui.label>
            <x-ui.select name="currency" class="w-full">
                @foreach (['USD', 'CAD', 'EUR', 'GBP'] as $cur)
                <option value="{{ $cur }}" @selected(old('currency', $isEdit ? $invoice->currency : 'USD') === $cur)>{{ $cur }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.field-error for="currency" />
        </div>
    </div>

    <div class="space-y-2">
        <x-ui.label for="notes">Notes</x-ui.label>
        <x-ui.textarea name="notes" rows="3" class="w-full">{{ old('notes', $isEdit ? $invoice->notes : '') }}</x-ui.textarea>
        <x-ui.field-error for="notes" />
    </div>
</div>

{{-- Line Items --}}
@php
    $existingItems = old('items', $isEdit ? $invoice->items->toArray() : [['description'=>'','quantity'=>1,'unit_price'=>'']]);

    // The next index is the highest existing key + 1, never the row count. After a failed submit whose
    // request had a removed middle row (keys 0 and 2), the count (2) would reuse key 2 and an added row
    // would duplicate both the DOM id and the submitted `items[2][...]` name (EPIC-016 §7.5, P8).
    $integerKeys = array_filter(array_keys($existingItems), 'is_int');
    $nextItemIndex = $integerKeys === [] ? 0 : max($integerKeys) + 1;
@endphp
<div class="rounded-xl border p-6"
     style="background-color: var(--surface-card); border-color: var(--border-base);">
    <h2 id="line-items-heading" class="mb-4 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Line Items</h2>
    <x-ui.field-error for="line-items" error-key="items" class="mb-3" />

    <div id="line-items" role="group" aria-labelledby="line-items-heading"
         @if ($errors->has('items')) aria-describedby="line-items-error" @endif
         class="space-y-2">
        @foreach ($existingItems as $i => $item)
        @include('billing.invoices._line_item', ['index' => $i, 'item' => $item, 'showLabels' => $loop->first, 'isTemplate' => false])
        @endforeach
    </div>

    <template id="line-item-template">
        @include('billing.invoices._line_item', ['index' => '__INDEX__', 'item' => ['description' => '', 'quantity' => 1, 'unit_price' => ''], 'showLabels' => false, 'isTemplate' => true])
    </template>

    <x-ui.button id="add-line-item" variant="ghost" size="sm" class="mt-3">+ Add line item</x-ui.button>
</div>

@push('scripts')
<script>
(function () {
    // Highest existing key + 1 (server-computed), never the row count.
    let idx = {{ $nextItemIndex }};

    const container = document.getElementById('line-items');
    const template = document.getElementById('line-item-template');

    document.getElementById('add-line-item').addEventListener('click', () => {
        // The template is the same server-rendered markup as the existing rows, with the literal
        // `__INDEX__` in every name, id and label[for]; substitute it, then append.
        container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(idx)));
        idx++;
    });

    container.addEventListener('click', e => {
        const remove = e.target.closest('[data-remove-line-item]');
        if (remove) {
            remove.closest('.line-item-row').remove();
        }
    });
})();
</script>
@endpush
