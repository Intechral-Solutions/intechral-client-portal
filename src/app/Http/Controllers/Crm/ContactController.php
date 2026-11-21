<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Rules\AccessibleOrganization;
use App\Services\CrmService;
use App\Shared\Scopes\DirectoryOperatorScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The Directory's People (Release 1.0). A Person is visible only to authorized operators (owner ruling D7, {@see DirectoryOperatorScope});
 * every action asks `manageDirectory` first, and the Person's one optional affiliation is an Organization.
 */
class ContactController extends Controller
{
    public function __construct(private CrmService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('manageDirectory', Organization::class);

        // An array-shaped parameter (`?search[]=x`) is no search, never an "Array to string" error.
        $search = is_string($request->query('search')) ? $request->query('search') : null;

        $contacts = CrmContact::with('organization')
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
        $this->authorize('manageDirectory', Organization::class);

        $companies = Organization::orderBy('name')->get(['id', 'name']);
        $companyId = $request->get('company_id');

        return view('crm.contacts.create', compact('companies', 'companyId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $contact = $this->service->createContact($request->user(), $this->validated($request));

        return redirect()->route('crm.contacts.show', $contact)
            ->with('success', 'Person created.');
    }

    public function show(CrmContact $contact): View
    {
        $this->authorize('manageDirectory', Organization::class);

        $contact->load('organization');

        return view('crm.contacts.show', compact('contact'));
    }

    public function edit(CrmContact $contact): View
    {
        $this->authorize('manageDirectory', Organization::class);

        $companies = Organization::orderBy('name')->get(['id', 'name']);

        return view('crm.contacts.edit', compact('contact', 'companies'));
    }

    public function update(Request $request, CrmContact $contact): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $data = $this->validated($request);

        try {
            $this->service->updateContact($contact, $data);
        } catch (ConflictHttpException) {
            throw $this->conflict();
        }

        return redirect()->route('crm.contacts.show', $contact)
            ->with('success', 'Person updated.');
    }

    public function destroy(CrmContact $contact): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        try {
            $this->service->deleteContact($contact);
        } catch (ConflictHttpException) {
            throw $this->conflict();
        }

        return redirect()->route('crm.contacts.index')
            ->with('success', 'Person deleted.');
    }

    /**
     * The Person's restart bound ran out (its organization kept changing) and NOTHING was written. The Blade pages answer with the redirect back, the typed input
     * and a message under the `person` key, which the edit page renders as an alert: never a raw 409 page, and never a success flash.
     */
    private function conflict(): ValidationException
    {
        return ValidationException::withMessages([
            'person' => 'This person was changed by someone else while the request was being processed. Nothing was changed; please review the details and try again.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'organization_id' => ['nullable', new AccessibleOrganization],
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'job_title' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
        ], [], ['organization_id' => 'organization']);
    }
}
