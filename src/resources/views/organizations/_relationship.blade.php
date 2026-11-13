{{--
    Release 1.0 (MO-1A): the managed-organization relationship of one organization, for operators only (the controller passes
    $relationship only when the actor holds portal.operator AND crm.manage). An administrative link: it grants nobody any access
    to the customer's service requests, projects, time or invoices (R1-4 support access is a separate, per-member operator grant). Every action is enforced on the server whatever this panel shows.
--}}
<div class="rounded-lg border p-5 space-y-4 bg-surface border-rule">
    <div>
        <h2 class="text-xs font-semibold uppercase tracking-wide text-text-muted">Partner relationship</h2>
        <p class="mt-1 text-xs text-text-muted">Administrative only. The relationship alone gives a partner no access to a customer's service requests, projects, time or invoices. Partner support access is granted to named members separately, under Members.</p>
    </div>

    <x-ui.field-error for="relationship" />

    @if ($relationship['isPartner'])
    <div class="space-y-3">
        <p class="text-sm text-text"><x-ui.tag>Partner</x-ui.tag> <span class="ml-1">This organization can manage customer organizations.</span></p>

        <div>
            <p class="text-xs text-text-muted">Managed customers ({{ $relationship['customers']->count() }})</p>
            <ul class="mt-1 divide-y divide-rule">
                @forelse ($relationship['customers'] as $customer)
                <li class="flex items-center justify-between gap-3 py-2">
                    <x-ui.link variant="row" :href="route('organizations.show', $customer->id)" class="text-sm">{{ $customer->name }}</x-ui.link>
                    <form method="POST" action="{{ route('organizations.manager.unlink', $customer->id) }}" onsubmit="return confirm('Unlink {{ addslashes($customer->name) }} from {{ addslashes($organization->name) }}?')">
                        @csrf @method('DELETE')
                        <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Unlink</x-ui.button>
                    </form>
                </li>
                @empty
                <li class="py-2 text-sm text-text-muted">No customers yet.</li>
                @endforelse
            </ul>
        </div>

        @if ($relationship['candidateCustomers']->isNotEmpty())
        <form method="POST" action="{{ route('organizations.customers.link', $organization->id) }}" class="flex gap-2">
            @csrf
            <x-ui.select name="customer_id" id="customer_id" aria-label="Customer organization to link" class="min-w-0 flex-1">
                @foreach ($relationship['candidateCustomers'] as $candidate)
                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit">Link customer</x-ui.button>
        </form>
        <x-ui.field-error for="customer_id" />
        @else
        <p class="text-xs text-text-muted">No organization is available to link: a customer must not be a partner or already managed, and must have exactly one CRM company.</p>
        @endif

        <div class="border-t pt-3 border-rule">
            @if ($relationship['customers']->isEmpty())
            <form method="POST" action="{{ route('organizations.partner.remove', $organization->id) }}" onsubmit="return confirm('Remove the partner designation from {{ addslashes($organization->name) }}?')">
                @csrf @method('DELETE')
                <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Remove partner designation</x-ui.button>
            </form>
            @else
            <x-ui.button variant="secondary" size="sm" disabled>Remove partner designation</x-ui.button>
            <p class="mt-1 text-xs text-text-muted">Unlink its customers first.</p>
            @endif
        </div>
    </div>
    @else
    <div class="space-y-3">
        @if ($relationship['manager'])
        <div>
            <p class="text-xs text-text-muted">Managed by</p>
            <p class="text-sm font-medium text-text">
                <x-ui.link variant="row" :href="route('organizations.show', $relationship['manager']->id)">{{ $relationship['manager']->name }}</x-ui.link>
            </p>
        </div>
        @else
        <p class="text-sm text-text-muted">Not managed by a partner.</p>
        @endif

        @if ($relationship['companies'] !== 1)
        <p class="text-xs text-text-muted">
            This organization is linked to {{ $relationship['companies'] }} CRM {{ \Illuminate\Support\Str::plural('company', $relationship['companies']) }}. A managed relationship needs exactly one, so none can be set until that is resolved in the CRM.
        </p>
        @else
            @if ($relationship['candidatePartners']->isNotEmpty())
            <form method="POST" action="{{ route('organizations.manager.assign', $organization->id) }}" class="flex gap-2">
                @csrf @method('PUT')
                @if ($relationship['manager'])
                <input type="hidden" name="reassign" value="1">
                @endif
                <x-ui.select name="partner_id" id="partner_id" aria-label="Managing partner" class="min-w-0 flex-1">
                    @foreach ($relationship['candidatePartners'] as $candidate)
                    <option value="{{ $candidate->id }}" @selected($relationship['manager'] && $relationship['manager']->id === $candidate->id)>{{ $candidate->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.button type="submit">{{ $relationship['manager'] ? 'Reassign' : 'Link to partner' }}</x-ui.button>
            </form>
            <x-ui.field-error for="partner_id" />
            @elseif (! $relationship['manager'])
            <p class="text-xs text-text-muted">No partner is available yet. Designate an organization as a partner first.</p>
            @endif
        @endif

        {{-- Outside the company branch: unlinking needs no company (the server allows it), so an organization whose company was
             resolved away since it was linked can still be released. Linking and reassigning stay behind the one-company rule. --}}
        @if ($relationship['manager'])
        <form method="POST" action="{{ route('organizations.manager.unlink', $organization->id) }}" onsubmit="return confirm('Unlink {{ addslashes($organization->name) }} from its managing partner?')">
            @csrf @method('DELETE')
            <x-ui.button type="submit" variant="ghost" tone="danger" size="sm">Unlink from partner</x-ui.button>
        </form>
        @endif

        @if ($relationship['canDesignate'])
        <div class="border-t pt-3 border-rule">
            <form method="POST" action="{{ route('organizations.partner.designate', $organization->id) }}">
                @csrf @method('PUT')
                <x-ui.button type="submit" variant="secondary" size="sm">Designate as partner</x-ui.button>
            </form>
        </div>
        @endif
    </div>
    @endif
</div>
