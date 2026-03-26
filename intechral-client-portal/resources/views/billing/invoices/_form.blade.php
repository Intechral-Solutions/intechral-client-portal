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
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="client_id">
                Client <span style="color: var(--text-danger);">*</span>
            </label>
            <select id="client_id" name="client_id" required
                    class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                    style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                <option value="">Select client…</option>
                @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $isEdit ? $invoice->client_id : '') == $client->id)>
                    {{ $client->name }} &lt;{{ $client->email }}&gt;
                </option>
                @endforeach
            </select>
            @error('client_id')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="project_id">Project</label>
            <select id="project_id" name="project_id"
                    class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                    style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                <option value="">None</option>
                @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected(old('project_id', $isEdit ? $invoice->project_id : '') == $project->id)>
                    {{ $project->name }}
                </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="issued_at">Issued <span style="color: var(--text-danger);">*</span></label>
            <input type="date" id="issued_at" name="issued_at" required
                   value="{{ old('issued_at', $isEdit ? $invoice->issued_at->format('Y-m-d') : today()->format('Y-m-d')) }}"
                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            @error('issued_at')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="due_at">Due <span style="color: var(--text-danger);">*</span></label>
            <input type="date" id="due_at" name="due_at" required
                   value="{{ old('due_at', $isEdit ? $invoice->due_at->format('Y-m-d') : today()->addDays(30)->format('Y-m-d')) }}"
                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            @error('due_at')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="tax_rate">Tax Rate (%)</label>
            <input type="number" id="tax_rate" name="tax_rate" min="0" max="100" step="0.01"
                   value="{{ old('tax_rate', $isEdit ? $invoice->tax_rate : '0') }}"
                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="currency">Currency</label>
            <select id="currency" name="currency"
                    class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                    style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @foreach (['USD', 'CAD', 'EUR', 'GBP'] as $cur)
                <option value="{{ $cur }}" @selected(old('currency', $isEdit ? $invoice->currency : 'USD') === $cur)>{{ $cur }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="3"
                  class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                  style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('notes', $isEdit ? $invoice->notes : '') }}</textarea>
    </div>
</div>

{{-- Line Items --}}
<div class="rounded-xl border p-6"
     style="background-color: var(--surface-card); border-color: var(--border-base);">
    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Line Items</h2>
    @error('items')<p class="mb-3 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror

    <div id="line-items" class="space-y-2">
        @php $existingItems = old('items', $isEdit ? $invoice->items->toArray() : [['description'=>'','quantity'=>1,'unit_price'=>'']]); @endphp
        @foreach ($existingItems as $i => $item)
        <div class="line-item-row grid grid-cols-12 gap-2 items-start">
            <div class="col-span-6">
                @if ($i === 0)
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                @endif
                <input type="text" name="items[{{ $i }}][description]"
                       value="{{ $item['description'] ?? '' }}" required
                       placeholder="Service or product description"
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @error("items.{$i}.description")<p class="text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>
            <div class="col-span-2">
                @if ($i === 0)
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Qty</label>
                @endif
                <input type="number" name="items[{{ $i }}][quantity]"
                       value="{{ $item['quantity'] ?? 1 }}" min="0.01" step="0.01" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            </div>
            <div class="col-span-3">
                @if ($i === 0)
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Unit Price</label>
                @endif
                <input type="number" name="items[{{ $i }}][unit_price]"
                       value="{{ $item['unit_price'] ?? '' }}" min="0" step="0.01" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            </div>
            <div class="col-span-1 flex items-end pb-0.5">
                @if ($i === 0)<div class="text-xs mb-1 opacity-0">x</div>@endif
                <button type="button"
                        class="remove-item rounded-lg border px-2 py-2 text-sm transition-colors hover:opacity-80"
                        style="border-color: var(--border-base); color: var(--text-danger);">&times;</button>
            </div>
        </div>
        @endforeach
    </div>

    <button type="button" id="add-line-item"
            class="mt-3 text-sm font-medium" style="color: var(--accent);">+ Add line item</button>
</div>

@push('scripts')
<script>
(function () {
    let idx = {{ count($existingItems ?? []) }};

    document.getElementById('add-line-item').addEventListener('click', () => {
        const container = document.getElementById('line-items');
        const row = document.createElement('div');
        row.className = 'line-item-row grid grid-cols-12 gap-2 items-start';
        row.innerHTML = `
            <div class="col-span-6">
                <input type="text" name="items[${idx}][description]" required placeholder="Service or product description"
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color:var(--surface-input);border-color:var(--border-base);color:var(--text-primary);">
            </div>
            <div class="col-span-2">
                <input type="number" name="items[${idx}][quantity]" value="1" min="0.01" step="0.01" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color:var(--surface-input);border-color:var(--border-base);color:var(--text-primary);">
            </div>
            <div class="col-span-3">
                <input type="number" name="items[${idx}][unit_price]" min="0" step="0.01" required placeholder="0.00"
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color:var(--surface-input);border-color:var(--border-base);color:var(--text-primary);">
            </div>
            <div class="col-span-1 flex items-center">
                <button type="button" class="remove-item rounded-lg border px-2 py-2 text-sm"
                        style="border-color:var(--border-base);color:var(--text-danger);">&times;</button>
            </div>`;
        container.appendChild(row);
        idx++;
    });

    document.getElementById('line-items').addEventListener('click', e => {
        if (e.target.classList.contains('remove-item')) {
            e.target.closest('.line-item-row').remove();
        }
    });
})();
</script>
@endpush
