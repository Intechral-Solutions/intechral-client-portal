{{--
    One invoice line-item row (EPIC-016 §7.5 "Invoice line items"). The single markup source for the
    server-rendered rows AND the `<template>` the "+ Add line item" button clones, so the same
    components build both.

    Variables:
      $index       — the row's array key (an int, or the literal placeholder `__INDEX__` in the template)
      $item        — array with description / quantity / unit_price
      $showLabels  — true: the visible column labels (the first row); false: the same labels as sr-only
      $isTemplate  — true only for the `<template>` row (adds the "0.00" hint the added rows always had)

    Per field: name `items[{i}][field]`, id `items-{i}-field`, error key `items.{i}.field`. The error
    element id is `{id}-error`, so errors line up with the row that caused them.
--}}
@php
    $descriptionId = "items-{$index}-description";
    $quantityId = "items-{$index}-quantity";
    $unitPriceId = "items-{$index}-unit_price";
    $descriptionKey = "items.{$index}.description";
    $quantityKey = "items.{$index}.quantity";
    $unitPriceKey = "items.{$index}.unit_price";
@endphp
<div class="line-item-row grid grid-cols-12 gap-2 items-start">
    <div class="col-span-6 space-y-2">
        @if ($showLabels)
        <x-ui.label :for="$descriptionId">Description</x-ui.label>
        @else
        <x-ui.label :for="$descriptionId" class="sr-only">Description</x-ui.label>
        @endif
        <x-ui.input type="text" name="items[{{ $index }}][description]" :id="$descriptionId"
                    :error-key="$descriptionKey"
                    :value="$item['description'] ?? ''" required
                    placeholder="Service or product description" class="w-full" />
        <x-ui.field-error :for="$descriptionId" :error-key="$descriptionKey" />
    </div>
    <div class="col-span-2 space-y-2">
        @if ($showLabels)
        <x-ui.label :for="$quantityId">Qty</x-ui.label>
        @else
        <x-ui.label :for="$quantityId" class="sr-only">Qty</x-ui.label>
        @endif
        <x-ui.input type="number" name="items[{{ $index }}][quantity]" :id="$quantityId"
                    :error-key="$quantityKey"
                    :value="$item['quantity'] ?? 1" min="0.01" step="0.01" required class="w-full" />
        <x-ui.field-error :for="$quantityId" :error-key="$quantityKey" />
    </div>
    <div class="col-span-3 space-y-2">
        @if ($showLabels)
        <x-ui.label :for="$unitPriceId">Unit Price</x-ui.label>
        @else
        <x-ui.label :for="$unitPriceId" class="sr-only">Unit Price</x-ui.label>
        @endif
        @if ($isTemplate)
        <x-ui.input type="number" name="items[{{ $index }}][unit_price]" :id="$unitPriceId"
                    :error-key="$unitPriceKey"
                    :value="$item['unit_price'] ?? ''" min="0" step="0.01" required placeholder="0.00" class="w-full" />
        @else
        <x-ui.input type="number" name="items[{{ $index }}][unit_price]" :id="$unitPriceId"
                    :error-key="$unitPriceKey"
                    :value="$item['unit_price'] ?? ''" min="0" step="0.01" required class="w-full" />
        @endif
        <x-ui.field-error :for="$unitPriceId" :error-key="$unitPriceKey" />
    </div>
    <div class="col-span-1 space-y-2">
        @if ($showLabels)
        <div class="invisible block text-sm" aria-hidden="true">x</div>
        @endif
        <x-ui.button variant="ghost" tone="danger" size="icon" aria-label="Remove line item" data-remove-line-item>&times;</x-ui.button>
    </div>
</div>
