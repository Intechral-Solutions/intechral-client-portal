@extends('layouts.app', ['title' => 'Pay ' . $invoice->invoice_number])

@push('head')
<script src="https://js.stripe.com/v3/"></script>
@endpush

@section('content')
<div class="mx-auto max-w-lg px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6">
        <a href="{{ route('billing.client.invoices.show', $invoice) }}" class="inline-flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            {{ $invoice->invoice_number }}
        </a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">Pay Invoice</h1>
    </div>

    {{-- Invoice summary --}}
    <div class="mb-6 rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <div class="flex items-center justify-between mb-3">
            <span class="font-mono font-semibold" style="color: var(--text-primary);">{{ $invoice->invoice_number }}</span>
            <span class="text-xs" style="color: var(--text-muted);">Due {{ $invoice->due_at->format('M j, Y') }}</span>
        </div>
        <div class="flex items-baseline gap-1">
            <span class="text-3xl font-bold" style="color: var(--text-primary);">{{ number_format((float)$invoice->total, 2) }}</span>
            <span class="text-sm font-medium" style="color: var(--text-muted);">{{ $invoice->currency }}</span>
        </div>
    </div>

    {{-- Stripe Elements --}}
    <div class="rounded-xl border p-5 space-y-4"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <div id="payment-element">
            {{-- Stripe.js injects the Payment Element here --}}
        </div>

        <div id="payment-message" class="hidden rounded-lg px-4 py-3 text-sm"
             style="background-color: var(--surface-danger); color: var(--text-danger);"
             role="alert"></div>

        <button id="submit-btn"
                class="w-full rounded-lg py-3 text-sm font-medium transition-opacity disabled:opacity-60"
                style="background-color: var(--accent); color: #fff;">
            <span id="btn-text">Pay {{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}</span>
            <span id="btn-spinner" class="hidden">Processing…</span>
        </button>
    </div>

    <p class="mt-4 text-center text-xs" style="color: var(--text-muted);">
        Payments are securely processed by <a href="https://stripe.com" target="_blank" class="hover:underline" style="color: var(--accent);">Stripe</a>.
    </p>
</div>

@push('scripts')
<script>
(function () {
    const stripe = Stripe('{{ $stripeKey }}');
    const elements = stripe.elements({ clientSecret: '{{ $clientSecret }}' });

    const paymentElement = elements.create('payment');
    paymentElement.mount('#payment-element');

    const form = document.getElementById('submit-btn');
    const msg  = document.getElementById('payment-message');
    const btnText    = document.getElementById('btn-text');
    const btnSpinner = document.getElementById('btn-spinner');

    form.addEventListener('click', async () => {
        form.disabled = true;
        btnText.classList.add('hidden');
        btnSpinner.classList.remove('hidden');
        msg.classList.add('hidden');

        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: '{{ route("billing.client.invoices.show", $invoice) }}',
            },
        });

        if (error) {
            msg.textContent = error.message;
            msg.classList.remove('hidden');
            form.disabled = false;
            btnText.classList.remove('hidden');
            btnSpinner.classList.add('hidden');
        }
        // On success Stripe redirects to return_url
    });
})();
</script>
@endpush
@endsection
