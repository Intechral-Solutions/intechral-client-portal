<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Rules\AccessibleCrmCompany;
use App\Services\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function __construct(private CrmService $service) {}

    public function index(Request $request): View
    {
        $search = $request->get('search');

        $contacts = CrmContact::with('company')
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return view('crm.contacts.index', compact('contacts', 'search'));
    }

    public function create(Request $request): View
    {
        $companies  = CrmCompany::orderBy('name')->get(['id', 'name']);
        $companyId  = $request->get('company_id');

        return view('crm.contacts.create', compact('companies', 'companyId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'crm_company_id' => ['nullable', new AccessibleCrmCompany],
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'job_title'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:5000',
        ]);

        $contact = $this->service->createContact($request->user(), $data);

        return redirect()->route('crm.contacts.show', $contact)
            ->with('success', 'Contact created.');
    }

    public function show(CrmContact $contact): View
    {
        $contact->load('company');

        return view('crm.contacts.show', compact('contact'));
    }

    public function edit(CrmContact $contact): View
    {
        $companies = CrmCompany::orderBy('name')->get(['id', 'name']);

        return view('crm.contacts.edit', compact('contact', 'companies'));
    }

    public function update(Request $request, CrmContact $contact): RedirectResponse
    {
        $data = $request->validate([
            'crm_company_id' => ['nullable', new AccessibleCrmCompany],
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'job_title'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:5000',
        ]);

        $this->service->updateContact($contact, $data);

        return redirect()->route('crm.contacts.show', $contact)
            ->with('success', 'Contact updated.');
    }

    public function destroy(CrmContact $contact): RedirectResponse
    {
        $this->service->deleteContact($contact);

        return redirect()->route('crm.contacts.index')
            ->with('success', 'Contact deleted.');
    }
}
