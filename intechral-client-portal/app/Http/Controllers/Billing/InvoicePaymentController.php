<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoicePaymentController extends Controller
{
    public function __construct(private InvoiceService $service) {}

    /**
     * Show the Stripe-hosted payment page for the invoice.
     */
    public function show(Invoice $invoice): View
    {
        $this->authorize('pay', $invoice);

        $clientSecret = $this->service->createPaymentIntent($invoice);

        return view('billing.payment.show', [
            'invoice' => $invoice,
            'clientSecret' => $clientSecret,
            'stripeKey' => config('services.stripe.key'),
        ]);
    }

    /**
     * Return a fresh PaymentIntent client secret (AJAX — e.g. after amount change).
     */
    public function intent(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('pay', $invoice);

        $clientSecret = $this->service->createPaymentIntent($invoice);

        return response()->json(['clientSecret' => $clientSecret]);
    }
}
