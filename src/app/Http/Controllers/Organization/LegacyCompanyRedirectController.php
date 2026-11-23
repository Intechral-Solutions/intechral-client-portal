<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Permanent redirects from the retired `/crm/companies` URLs to the Organization workspace (Release 1.0, ORG-3b). GET only; the routes are UNNAMED (the names `crm.companies.*`
 * are retired), sit behind the same `auth` and `can:crm.manage` middleware the old pages had, and carry only a numeric id (`whereNumber`), so a redirect target is never built from
 * free text. A redirect decides nothing: the target page asks `manageDirectory` itself, and a customer is answered by the middleware and the surface gate exactly as the old URL
 * answered them. A controller rather than closures so `route:cache` can serialize the routes.
 */
class LegacyCompanyRedirectController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('organizations.index', $request->query(), 301);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('organizations.create', [], 301);
    }

    public function show(string $company): RedirectResponse
    {
        return redirect()->route('organizations.show', ['organization' => $company], 301);
    }

    public function edit(string $company): RedirectResponse
    {
        return redirect()->route('organizations.edit', ['organization' => $company], 301);
    }
}
