<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\View\View;

class ClientInvoiceController extends Controller
{
    public function index(): View
    {
        $invoices = Invoice::where('client_id', auth()->id())
            ->with('project')
            ->latest()
            ->paginate(20);

        return view('billing.client.index', compact('invoices'));
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['creator', 'project', 'items', 'payments']);

        return view('billing.client.show', compact('invoice'));
    }
}
