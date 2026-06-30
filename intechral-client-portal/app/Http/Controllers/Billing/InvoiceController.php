<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::with(['client', 'project'])
            ->when($request->search, fn ($q, $s) => $q->where('invoice_number', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($u) => $u->where('name', 'like', "%{$s}%"))
            )
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('billing.invoices.index', compact('invoices'));
    }

    public function create(): View
    {
        $this->authorize('create', Invoice::class);

        $clients = User::orderBy('name')->get(['id', 'name', 'email']);
        $projects = Project::whereIn('status', ['active', 'on_hold'])->orderBy('name')->get(['id', 'name']);

        return view('billing.invoices.create', compact('clients', 'projects'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validate([
            'client_id' => 'required|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'issued_at' => 'required|date',
            'due_at' => 'required|date|after_or_equal:issued_at',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'currency' => 'in:USD,CAD,EUR,GBP',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $invoice = $this->service->create(auth()->user(), $data);

        return redirect()->route('billing.invoices.show', $invoice)
            ->with('success', 'Invoice created.');
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['client', 'creator', 'project', 'items', 'payments']);

        return view('billing.invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice): View
    {
        $this->authorize('update', $invoice);

        $clients = User::orderBy('name')->get(['id', 'name', 'email']);
        $projects = Project::whereIn('status', ['active', 'on_hold'])->orderBy('name')->get(['id', 'name']);
        $invoice->load('items');

        return view('billing.invoices.edit', compact('invoice', 'clients', 'projects'));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $data = $request->validate([
            'client_id' => 'required|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'issued_at' => 'required|date',
            'due_at' => 'required|date|after_or_equal:issued_at',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'currency' => 'in:USD,CAD,EUR,GBP',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $this->service->update($invoice, $data);

        return redirect()->route('billing.invoices.show', $invoice)
            ->with('success', 'Invoice updated.');
    }

    public function send(Invoice $invoice): RedirectResponse
    {
        $this->authorize('send', $invoice);

        $this->service->send($invoice);

        return redirect()->route('billing.invoices.show', $invoice)
            ->with('success', 'Invoice marked as sent.');
    }

    public function recordPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('recordPayment', Invoice::class);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->recordManualPayment($invoice, (float) $data['amount'], $data['notes'] ?? '');

        return redirect()->route('billing.invoices.show', $invoice)
            ->with('success', 'Payment recorded.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return redirect()->route('billing.invoices.index')
            ->with('success', 'Invoice deleted.');
    }
}
