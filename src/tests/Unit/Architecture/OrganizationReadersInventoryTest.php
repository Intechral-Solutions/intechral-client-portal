<?php

/*
 * Release 1.0 (ORG-1; ADR-011 section 5): the source-level guard of the portal-state fence. A Directory-only Organization (Not enabled) must never
 * acquire portal capability merely because its row exists, so EVERY place in application code that reads the `organizations` table or the Organization
 * model is accounted for here, in one of two classes:
 *
 *   DECIDES   the file takes or applies a portal decision (invites, adds, grants, files, supports, counts) and must therefore name the PortalState
 *             predicate, METHOD BY METHOD: every method that reads Organizations either names PortalState itself or is listed in orgReadersExemptions()
 *             with the reason it decides nothing (it takes authority away, locks, writes what a caller decided, or only names a row).
 *   DISPLAY   the file only resolves, names or lists Organizations (a label, a filter option, an operator-only lookup by id, a relation declaration, a
 *             fixture, a command) and decides nothing a Directory-only row could widen. Each carries the reason it is safe.
 *
 * WHAT THE SCAN SEES (comments are removed first, so prose never counts): the table by name in raw SQL, with or without backticks (`from organizations`,
 * `join organizations`, `organizations`), as the first argument of table(), from(), join() and its variants, exists() and unique(); EVERY static call on
 * the model (Organization::whereNull, ::select, ::whereHas, ::latest, ...), `new Organization`; relationship declarations (belongsTo(Organization::class),
 * belongsToMany, hasMany, ...); relation reads (`->organizations()`, `->organization?->name`, `->organizations`), eager loading and relation filters
 * (`with('organization')`, `whereHas('organizations', ...)`); and, in Http/, a route-bound `Organization $organization` parameter (implicit binding is
 * a read by the framework).
 *
 * WHAT IT CANNOT SEE, stated so nobody relies on it as proof: a reader that builds the table name at runtime or reaches it through a variable; a read in
 * a Blade view, a route closure, a migration, a seeder or a test (only app/ is scanned, and presentation never decides); the loaded relation of a model
 * that was passed in (the declaration is inventoried, and each call that traverses it is a `->organization` hit, but a renamed alias is not); and, inside
 * a method that already names PortalState, a second read that forgot to ask. That last gap is closed by the behavioural matrix in
 * OrganizationPortalStateFenceTest (route by route) and the real-connection races, never by this file. This is a guard that a NEW reader cannot arrive
 * unclassified and a DECIDING method cannot lose its fence silently; it is not a proof that every read is fenced. "It works today because no
 * Directory-only row exists yet" is not a reason to classify anything: ORG-2 makes them possible.
 */

/** @return array<string, string> family name => pattern, applied to a file with its comments removed */
function orgReadersFamilies(): array
{
    return [
        'raw sql' => '/\b(?:from|join|into|update)\s+`?organizations`?\b/i',
        'backticked table' => '/`organizations`/',
        'table argument' => '/(?:->|::)(?:table|from|join|leftJoin|rightJoin|crossJoin|fromSub|exists|unique)\(\s*[\'"]organizations(?: as \w+)?[\'"]/',
        'model static call' => '/\bOrganization::(?!class\b)\w+/',
        'new model' => '/\bnew Organization\b/',
        'relation declaration' => '/(?:belongsTo|hasMany|hasOne|belongsToMany|hasManyThrough|hasOneThrough|morphedByMany|morphToMany|morphMany|morphOne)\(\s*Organization::class/',
        'relation call' => '/->organizations?\(\)/',
        'relation property' => '/->organizations?\b(?![_(])/',
        'eager load or relation filter' => '/(?:with|withCount|withExists|load|loadMissing|loadCount|whereHas|orWhereHas|whereDoesntHave|has|doesntHave)\(\s*\[?\s*[\'"]organizations?\b/',
        'route-bound parameter' => '/function \w+\([^)]*\bOrganization \$\w+/',
    ];
}

/** The source with every comment and doc block removed (line count kept), so prose that names a table is not a reader. */
function orgReadersStrip(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/** @return list<string> the families a file (path relative to app/) matches */
function orgReadersMatches(string $relativePath, string $source): array
{
    $code = orgReadersStrip($source);
    $matched = [];

    foreach (orgReadersFamilies() as $family => $pattern) {
        // An implicit route binding is a read only where a route reaches it: controllers, requests and middleware.
        if ($family === 'route-bound parameter' && ! str_starts_with($relativePath, 'Http/')) {
            continue;
        }

        if (preg_match($pattern, $code) === 1) {
            $matched[] = $family;
        }
    }

    return $matched;
}

/** @return array<string, list<string>> every app/ file that reads Organizations => the families that matched, relative to app/ */
function orgReadersFound(): array
{
    $found = [];
    $root = app_path();

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $families = orgReadersMatches($relative, file_get_contents($file->getPathname()));

        if ($families !== []) {
            $found[$relative] = $families;
        }
    }

    ksort($found);

    return $found;
}

/** @return array<string, string> file (relative to app/) => the class and reason */
function orgReadersInventory(): array
{
    return [
        // ── DECIDES: names PortalState, method by method ──
        'Services/OrganizationInvitations.php' => 'DECIDES: issue and every acceptance read (findOpen, acceptLocked), the disable-time revocation',
        'Services/OrganizationMembership.php' => 'DECIDES: add, promote, approve, and the acceptance seam addFromInvitation',
        'Services/PartnerSupportGrants.php' => 'DECIDES: a new support grant',
        'Services/PartnerSupportAccess.php' => 'DECIDES: partner support authority, unlocked (grantQuery, supportedOrganizations) and locked (refusal)',
        'Services/ServiceRequestAccess.php' => 'DECIDES: filing a request, its locked recheck and both candidate reads',
        'Services/OrganizationRelationships.php' => 'DECIDES: designate, link, reassign, both candidate lists, disable and re-enable',
        'Services/CrmService.php' => 'DECIDES: initial enablement (promoteToOrganization)',
        'Services/OrganizationListFacts.php' => 'DECIDES: a row existing is not portal access (portalStatus)',
        'Services/OrganizationPreflight.php' => 'DECIDES: the Directory-only invariant, the approval check',
        'Notifications/OrganizationInvitationNotification.php' => 'DECIDES: the send-time eligibility of a queued invitation mail',
        'Http/Controllers/DashboardController.php' => 'DECIDES: the "With portal access" count',

        // ── DISPLAY: decides nothing a Directory-only row could widen ──
        'Http/Controllers/Organization/OrganizationController.php' => 'DISPLAY: the operator-only directory list and detail; shows the row and its state. ORG-3 filters the System list',
        'Http/Controllers/Organization/OrganizationRelationshipController.php' => 'DISPLAY: operator-only lookup by id; every rule is decided by OrganizationRelationships under lock',
        'Http/Controllers/Organization/OrganizationSupportController.php' => 'DISPLAY: operator-only lookup by id; every rule is decided by PartnerSupportGrants and OrganizationRelationships',
        'Http/Controllers/Organization/OrganizationInvitationController.php' => 'DISPLAY: operator-only route binding (crm.manage, tenant-scoped); issue and revoke are decided by OrganizationInvitations under lock',
        'Http/Controllers/Organization/OrganizationMemberController.php' => 'DISPLAY: operator-only route binding (crm.manage, tenant-scoped); every membership rule is decided by OrganizationMembership under lock',
        'Http/Controllers/Operator/TicketController.php' => 'DISPLAY: the operator ticket list filter, only organizations that already have tickets',
        'Http/Controllers/Operator/TicketReportController.php' => 'DISPLAY: the operator CSV export prints the organization name of a ticket the report already selected',
        'Http/Presenters/RequesterTicketPresenter.php' => 'DISPLAY: the organization name on the requester\'s own ticket (TicketPolicy decides who sees it; ruling A keeps it visible while Disabled)',
        'Models/CrmCompany.php' => 'DISPLAY: declares the Directory profile\'s link to its Organization; the link is written only by CrmService and OrganizationRelationships',
        'Models/OrganizationInvitation.php' => 'DISPLAY: declares the relation; acceptance reads the Organization through OrganizationInvitations, never through it',
        'Models/Ticket.php' => 'DISPLAY: declares the relation, unscoped, for names on pages and mails; attribution is decided by ServiceRequestAccess',
        'Models/User.php' => 'DISPLAY: declares the membership relation. Its one reader, orgCompanyIds(), has no caller (see orgReadersDormant())',
        'Notifications/NewTicketAlertNotification.php' => 'DISPLAY: names the ticket\'s organization in an operator mail; the recipients are decided elsewhere',
        'Notifications/PartnerReplyAlertNotification.php' => 'DISPLAY: names the ticket\'s organization in an operator mail; the recipients are decided elsewhere',
        'Notifications/TicketCreatedNotification.php' => 'DISPLAY: names the ticket\'s organization in the confirmation mail; the recipient is the ticket\'s own requester',
        'Notifications/TicketRepliedNotification.php' => 'DISPLAY: names the ticket\'s organization in the reply mail; the recipient is decided by the ticket\'s participants',
        'Queries/PartnerSupportQuery.php' => 'DISPLAY: a name subselect of a list that PartnerSupportAccess already limits to Enabled customers',
        'Queries/RequesterTicketQuery.php' => 'DISPLAY: a name subselect of the requester\'s own tickets (TicketPolicy decides; ruling A keeps them visible while Disabled)',
        'Queries/TaskListState.php' => 'DISPLAY: a filter value object whose `organization` is a Directory profile id, not a read of the organizations table',
        'Queries/TaskQuery.php' => 'DISPLAY: applies that Directory profile filter to project companies; no Organization row is read',
        'Services/UserMembershipList.php' => 'DISPLAY: the memberships a User row already has, for the Users list',
        'Console/Commands/CleanupE2eInvitees.php' => 'DISPLAY: deletes E2E fixtures only; not a production path (ADR-011 bypass sweep)',
        'Support/Rehearsal/IntegritySnapshot.php' => 'DISPLAY: reads the rehearsal snapshot; not a production path',
    ];
}

/**
 * Methods of a DECIDES file that read Organizations and are allowed NOT to name PortalState, each with why. A method added here is a reviewed claim that it
 * takes no portal decision; a new method that reads Organizations and is on neither side fails orgReadersMethodFences().
 *
 * @return array<string, array<string, string>> file => method => reason
 */
function orgReadersExemptions(): array
{
    return [
        'Services/OrganizationInvitations.php' => [
            'subject' => 'resolves the row for an audit entry after the decision was taken under lock',
        ],
        'Services/PartnerSupportGrants.php' => [
            'revoke' => 'takes authority away: revoking a grant stays possible while Disabled or Directory-only (cleanup), so it asks no portal question',
            'rowsFor' => 'the operator page\'s list of grant rows; labels, decides nothing',
            'audit' => 'names the organization in an audit entry after the decision',
        ],
        'Services/OrganizationRelationships.php' => [
            'removePartner' => 'takes authority away: a designation can be removed in any state once the partner has no customer; counts its customers',
            'managedCustomers' => 'the partner page\'s list of managed customers (operator-only display)',
            'managerOf' => 'the customer page\'s current partner (operator-only display)',
            'lock' => 'the lock helper: locks the rows in ascending order; every caller reads the state of the rows it locked through lockRaw() and PortalState',
            'write' => 'writes the change a caller decided under its lock',
            'nameOf' => 'a name for a message or an audit entry',
        ],
        'Services/CrmService.php' => [
            'deleteCompany' => 'takes a link away (a delete of a Directory profile): not a grant of anything; locks the Organization row first for the lock order',
            'uniqueSlug' => 'slug uniqueness for a new row; no portal decision',
        ],
        'Services/OrganizationListFacts.php' => [
            'relationships' => 'operator list facts (partner names, customer counts) shown beside portalStatus(), which decides the label',
        ],
        'Services/OrganizationPreflight.php' => [
            'report' => 'the read-only preflight: assembles the checks below',
            'relationships' => 'a read-only check of the stored relationship invariants',
            'adminApproval' => 'a read-only check, already restricted to rows with portal access (portal_enabled_at is not null)',
            'partnerSupport' => 'a read-only check of the stored grant invariants',
        ],
        'Notifications/OrganizationInvitationNotification.php' => [
            'row' => 'fetches the columns shouldSend() decides on with PortalState',
        ],
    ];
}

/**
 * Methods with a caller-less reader, so that "no caller" is itself checked: the day one gains a caller it must be classified as DECIDES.
 *
 * @return array<string, string> file => method name
 */
function orgReadersDormant(): array
{
    return ['Models/User.php' => 'orgCompanyIds'];
}

/** @return array<string, string> method name => its source (signature and body), for the methods of a class file with comments removed */
function orgReadersMethods(string $source): array
{
    $code = orgReadersStrip($source);
    preg_match_all('/\n    (?:(?:public|protected|private|static|final)\s+)*function\s+(\w+)\s*\(/', $code, $found, PREG_OFFSET_CAPTURE);
    $methods = [];

    foreach ($found[0] as $i => [, $start]) {
        $end = isset($found[0][$i + 1]) ? $found[0][$i + 1][1] : strlen($code);
        $methods[$found[1][$i][0]] = substr($code, $start, $end - $start);
    }

    return $methods;
}

/**
 * The methods of a source that read Organizations and do not name PortalState, less the reviewed exemptions.
 *
 * @param  array<string, string>  $exempt  method => reason
 * @return list<string>
 */
function orgReadersMethodFences(string $relativePath, string $source, array $exempt = []): array
{
    $unfenced = [];

    foreach (orgReadersMethods($source) as $name => $body) {
        if (orgReadersMatches($relativePath, $body) !== [] && ! str_contains($body, 'PortalState') && ! array_key_exists($name, $exempt)) {
            $unfenced[] = $name;
        }
    }

    return $unfenced;
}

/**
 * The text of every `DB::transaction(...)` / `->transaction(...)` call and every `$this->transact(...)` call (the locking helper of OrganizationMembership,
 * whose closures are the transaction body): the balanced argument list, whatever shape the closure takes (a long closure, an arrow function, a `, 3`
 * attempts argument). Counted on tokens, so a parenthesis inside a string does not unbalance it. A method DEFINITION named `transact` is not a call and is skipped.
 *
 * @return list<string>
 */
function orgTransactionBodies(string $source): array
{
    $tokens = token_get_all(orgReadersStrip($source));
    $bodies = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['transaction', 'transact'], true)) {
            continue;
        }

        $before = $i - 1;
        while ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
            $before--;
        }

        if ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) {
            continue;
        }

        $depth = 0;
        $text = '';

        for ($j = $i + 1; $j < count($tokens); $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($piece === '(') {
                $depth++;
            } elseif ($piece === ')') {
                $depth--;
            }

            $text .= $piece;

            if ($depth === 0 && $j > $i + 1) {
                break;
            }
        }

        if ($depth === 0 && str_starts_with(ltrim($text), '(')) {
            $bodies[] = $text;
        }
    }

    return $bodies;
}

const ORG_GATE_ASKS = '/->can\(|->cannot\(|->allows\(|->denies\(|Gate::|->authorize\(|authorizeForUser|hasPermissionTo|hasAnyPermission|hasAllPermissions|->hasRole\(|->hasAnyRole\(|getAllPermissions/';

/**
 * Where a source asks the Gate, a policy or the permission cache INSIDE a transaction: directly in the transaction's argument list, or by calling a
 * method of the same file that itself asks. (One level of calls: the methods of the file; a deeper chain is not followed.)
 *
 * @return list<string> a description of each violation
 */
function orgGateInsideTransactions(string $source): array
{
    $askers = [];
    foreach (orgReadersMethods($source) as $name => $body) {
        if (preg_match(ORG_GATE_ASKS, $body) === 1) {
            $askers[] = $name;
        }
    }

    $violations = [];
    foreach (orgTransactionBodies($source) as $body) {
        if (preg_match(ORG_GATE_ASKS, $body, $match) === 1) {
            $violations[] = 'asks `'.trim($match[0], '(>:- ').'` inside a transaction';
        }

        foreach ($askers as $asker) {
            if (preg_match('/(?:->|::)'.preg_quote($asker, '/').'\(/', $body) === 1) {
                $violations[] = "calls {$asker}(), which asks the Gate, inside a transaction";
            }
        }
    }

    return $violations;
}

/** The services whose transactions hold the Organization, user and invitation locks: nothing in them may ask the Gate or the permission cache. */
function orgLockedServices(): array
{
    return [
        'Services/OrganizationRelationships.php',
        'Services/OrganizationInvitations.php',
        'Services/OrganizationMembership.php',
        'Services/PartnerSupportGrants.php',
        'Services/CrmService.php',
    ];
}

// ── The inventory ────────────────────────────────────────────────────────────

it('accounts for every reader of the organizations table or model in application code', function () {
    $unlisted = array_values(array_diff(array_keys(orgReadersFound()), array_keys(orgReadersInventory())));

    expect($unlisted)->toBe([], 'A file reads the `organizations` table or model and is not in the portal-state inventory. Decide whether it DECIDES a portal act (then it must name App\Support\Organizations\PortalState) or only DISPLAYS, and add it to orgReadersInventory() with the reason: '.implode(', ', $unlisted));
});

it('keeps no stale entry: every inventoried file still reads organizations', function () {
    $stale = array_values(array_diff(array_keys(orgReadersInventory()), array_keys(orgReadersFound())));

    expect($stale)->toBe([], 'These inventory entries no longer match a reader; remove them: '.implode(', ', $stale));
});

it('classifies every entry as DECIDES or DISPLAY, with a reason', function () {
    foreach (orgReadersInventory() as $file => $why) {
        expect($why)->toMatch('/^(DECIDES|DISPLAY): \S.{10,}/', "{$file} has no class and reason");
    }
});

it('requires every file that decides a portal act to name the PortalState predicate', function () {
    $missing = [];

    foreach (orgReadersInventory() as $file => $why) {
        if (str_starts_with($why, 'DECIDES') && ! str_contains(file_get_contents(app_path($file)), 'PortalState')) {
            $missing[] = $file;
        }
    }

    expect($missing)->toBe([], 'These files decide a portal act but no longer name PortalState: '.implode(', ', $missing));
});

it('requires every method of a deciding file that reads Organizations to name PortalState, or to be a reviewed exemption', function () {
    $exemptions = orgReadersExemptions();
    $failures = [];

    foreach (orgReadersInventory() as $file => $why) {
        if (! str_starts_with($why, 'DECIDES')) {
            continue;
        }

        $unfenced = orgReadersMethodFences($file, file_get_contents(app_path($file)), $exemptions[$file] ?? []);

        foreach ($unfenced as $method) {
            $failures[] = "{$file}::{$method}()";
        }
    }

    expect($failures)->toBe([], 'These methods read Organizations without naming PortalState. Fence them (PortalState::of for a locked row, whereEnabled for a query), or, if they decide no portal act, add them to orgReadersExemptions() with the reason: '.implode(', ', $failures));
});

it('keeps no stale exemption: every exempt method exists, still reads Organizations, and still does not name PortalState', function () {
    $stale = [];

    foreach (orgReadersExemptions() as $file => $methods) {
        expect(orgReadersInventory())->toHaveKey($file);
        $found = orgReadersMethods(file_get_contents(app_path($file)));

        foreach ($methods as $method => $reason) {
            $body = $found[$method] ?? null;

            if ($body === null || orgReadersMatches($file, $body) === [] || str_contains($body, 'PortalState') || strlen($reason) < 15) {
                $stale[] = "{$file}::{$method}()";
            }
        }
    }

    expect($stale)->toBe([], 'These exemptions are stale (the method is gone, no longer reads Organizations, or now names PortalState); remove them: '.implode(', ', $stale));
});

it('has no caller for a reader that is classified as dormant', function () {
    foreach (orgReadersDormant() as $file => $method) {
        expect(orgReadersMethods(file_get_contents(app_path($file))))->toHaveKey($method);
        $callers = [];

        foreach ([app_path(), base_path('routes'), resource_path('views')] as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $candidate) {
                if (in_array($candidate->getExtension(), ['php'], true) && preg_match('/\b'.$method.'\b/', file_get_contents($candidate->getPathname())) === 1) {
                    $callers[] = str_replace(base_path().'/', '', $candidate->getPathname());
                }
            }
        }

        expect($callers)->toBe([str_replace('//', '/', 'app/'.$file)], "{$method}() is referenced outside its declaration: it now reads Organizations for a caller, so classify it as DECIDES and fence it");
    }
});

// ── The scan itself: representative forms the first version missed, and look-alikes it must not flag ───────────

it('detects every representative form of an Organization read, with a probe for each', function (string $family, string $relativePath, string $code) {
    expect(orgReadersMatches($relativePath, "<?php\nclass Probe\n{\n    public function probe()\n    {\n        {$code}\n    }\n}\n"))->toContain($family);
})->with([
    // The baseline dashboard read the first version of this guard did not see.
    'dynamic where on the model' => ['model static call', 'Http/Controllers/Probe.php', "return Organization::whereNull('disabled_at')->count();"],
    'whereHas on the model' => ['model static call', 'Services/Probe.php', "return Organization::whereHas('members')->get();"],
    'select on the model' => ['model static call', 'Services/Probe.php', "return Organization::select('id')->get();"],
    'latest on the model' => ['model static call', 'Services/Probe.php', 'return Organization::latest()->first();'],
    'fully qualified model' => ['model static call', 'Services/Probe.php', 'return \\App\\Models\\Organization::oldest()->get();'],
    'instantiation' => ['new model', 'Services/Probe.php', 'return new Organization;'],
    'raw sql, backticked table' => ['backticked table', 'Services/Probe.php', 'return DB::select(\'select id from `organizations` where id = ?\', [1]);'],
    'raw sql, from and join' => ['raw sql', 'Services/Probe.php', 'return DB::select("select o.id from organizations o");'],
    'raw sql, join' => ['raw sql', 'Services/Probe.php', 'return DB::select("select t.id from tickets t join organizations o on o.id = t.organization_id");'],
    'query builder table' => ['table argument', 'Services/Probe.php', "return DB::table('organizations')->get();"],
    'query builder alias' => ['table argument', 'Services/Probe.php', "return DB::table('organizations as o')->get();"],
    'left join' => ['table argument', 'Services/Probe.php', "return \$query->leftJoin('organizations as o', 'o.id', '=', 't.organization_id');"],
    'validation exists rule' => ['table argument', 'Http/Requests/Probe.php', "return ['id' => Rule::exists('organizations', 'id')];"],
    'a relation declaration' => ['relation declaration', 'Models/Probe.php', 'return $this->belongsTo(Organization::class);'],
    'a many-to-many declaration' => ['relation declaration', 'Models/Probe.php', "return \$this->belongsToMany(Organization::class, 'organization_members');"],
    'a has-many declaration' => ['relation declaration', 'Models/Probe.php', 'return $this->hasMany(Organization::class);'],
    'a relation call' => ['relation call', 'Services/Probe.php', 'return $user->organizations()->get();'],
    'a singular relation call' => ['relation call', 'Services/Probe.php', 'return $ticket->organization()->first();'],
    'a relation property chain' => ['relation property', 'Services/Probe.php', 'return $ticket->organization?->name;'],
    'a relation collection' => ['relation property', 'Services/Probe.php', 'return $user->organizations->pluck(\'id\');'],
    'eager load' => ['eager load or relation filter', 'Services/Probe.php', "return Ticket::with('organization')->get();"],
    'eager load in an array' => ['eager load or relation filter', 'Services/Probe.php', "return Ticket::query()->with(['organization', 'user'])->get();"],
    'a relation filter' => ['eager load or relation filter', 'Services/Probe.php', "return User::whereHas('organizations', fn (\$q) => \$q->where('id', 1))->get();"],
    'a relation count' => ['eager load or relation filter', 'Services/Probe.php', "return User::withCount('organizations')->get();"],
    'a route-bound parameter' => ['route-bound parameter', 'Http/Controllers/Probe.php', 'public function show(Organization $organization) {}'],
]);

it('does not flag what only looks like a read', function (string $relativePath, string $code) {
    expect(orgReadersMatches($relativePath, "<?php\nclass Probe\n{\n    public function probe()\n    {\n        {$code}\n    }\n}\n"))->toBe([]);
})->with([
    'the class constant in a gate call' => ['Services/Probe.php', "Gate::authorize('manageMembership', Organization::class);"],
    'a route name' => ['Services/Probe.php', "return route('organizations.show', \$id);"],
    'a route group name' => ['Services/Probe.php', "return redirect()->route('organizations.index');"],
    'an Inertia component' => ['Services/Probe.php', "return Inertia::render('organizations/index');"],
    'a foreign key column' => ['Services/Probe.php', 'return $ticket->organization_id;'],
    'an invitation column' => ['Services/Probe.php', 'return $invitation->organization_name;'],
    'a prose docblock' => ['Services/Probe.php', "/** The `organizations` table, from organizations, and \$x->organizations(). */\n        return 1;"],
    'a line comment' => ['Services/Probe.php', "// join organizations o on o.id = 1\n        return 1;"],
    'a service parameter outside Http' => ['Services/Probe.php', 'return $this->helper($organization);'],
]);

it('does not treat a typed service parameter as a read, but does in Http, where the router binds it', function () {
    $service = "<?php\nclass Probe\n{\n    public function add(Organization \$organization) {}\n}\n";

    expect(orgReadersMatches('Services/Probe.php', $service))->toBe([])
        ->and(orgReadersMatches('Http/Controllers/Probe.php', $service))->toBe(['route-bound parameter']);
});

it('finds, by method, a deciding method that reads Organizations without the fence, and ignores one that has it or is exempt', function () {
    $source = <<<'PHP'
<?php
class Probe
{
    public function fenced(int $id)
    {
        $row = DB::table('organizations')->where('id', $id)->lockForUpdate()->first(['id', ...PortalState::columns()]);
    }

    public function forgotten(int $id)
    {
        return Organization::whereNull('disabled_at')->count();
    }

    public function unfencedRawSql()
    {
        return DB::select('select id from organizations where id > 0');
    }

    public function displayOnly(int $id)
    {
        return DB::table('organizations')->where('id', $id)->value('name');
    }

    public function noRead()
    {
        return 1;
    }
}
PHP;

    expect(orgReadersMethodFences('Services/Probe.php', $source))->toBe(['forgotten', 'unfencedRawSql', 'displayOnly'])
        ->and(orgReadersMethodFences('Services/Probe.php', $source, ['displayOnly' => 'a name for a message']))->toBe(['forgotten', 'unfencedRawSql']);
});

// ── The Gate and the permission cache stay out of the locks ──────────────────

it('keeps the fence out of Eloquent global scopes and out of the Gate', function () {
    $scopes = glob(app_path('Shared/Scopes/*.php'));

    foreach ($scopes as $scope) {
        expect(file_get_contents($scope))->not->toContain('PortalState', basename($scope).' must not carry the portal-state fence: every deciding read bypasses the model, so a scope cannot be the fence');
    }

    foreach (['Policies/OrganizationPolicy.php'] as $file) {
        expect(file_get_contents(app_path($file)))->not->toContain('PortalState', "{$file} must not read the portal state: the Gate is never the fence, and a policy call inside a locked transaction is forbidden");
    }
});

it('never asks the Gate, a policy or the permission cache inside a transaction of the locked services', function () {
    // The Gate is asked BEFORE a transaction opens; the decisions inside the locks read the tables. Every DB::transaction(...) argument list of these files is
    // scanned, whatever shape the closure takes, and so is every call it makes to a method of the same file that asks.
    $violations = [];

    foreach (orgLockedServices() as $file) {
        $source = file_get_contents(app_path($file));
        expect(orgTransactionBodies($source))->not->toBe([], "{$file} has no transaction the scan could read: the extractor and the file have drifted apart");

        foreach (orgGateInsideTransactions($source) as $violation) {
            $violations[] = "{$file}: {$violation}";
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the table-based authority check free of the Gate and the permission cache altogether, since it runs under the locks', function () {
    expect(preg_match(ORG_GATE_ASKS, orgReadersStrip(file_get_contents(app_path('Services/OrganizationAuthority.php')))))->toBe(0, 'OrganizationAuthority runs inside the sensitive locks and must read tables only');
});

it('detects a Gate, a policy or a permission-cache call in a transaction, in every shape of closure, and a call to a method that asks', function (string $transaction) {
    $source = "<?php\nclass Probe\n{\n    public function entry()\n    {\n        {$transaction}\n    }\n\n    private function gated(\$actor)\n    {\n        Gate::forUser(\$actor)->authorize('x', Organization::class);\n    }\n}\n";

    expect(orgGateInsideTransactions($source))->not->toBe([]);
})->with([
    'a long closure with an attempts argument' => ['DB::transaction(function () use ($actor) {  $actor->can("x");  }, 3);'],
    'a closure without attempts' => ['DB::transaction(function () use ($actor) {  Gate::allows("x");  });'],
    'an arrow function' => ['DB::transaction(fn () => $actor->hasPermissionTo("x"));'],
    'a policy call' => ['DB::transaction(function () use ($actor) { $this->authorize("x"); });'],
    'a role check' => ['DB::transaction(function () use ($actor) { return $actor->hasRole("operator"); });'],
    'a call to a method that asks' => ['DB::transaction(function () use ($actor) { $this->gated($actor); });'],
    'a nested call with a parenthesis in a string' => ['DB::transaction(function () use ($actor) { DB::select("select (1)"); $actor->can("x"); });'],
]);

it('does not flag a transaction that reads tables only, nor a Gate call that precedes it', function () {
    $source = "<?php\nclass Probe\n{\n    public function entry(\$actor)\n    {\n        \$this->gated(\$actor);\n        return DB::transaction(function () {\n            return DB::table('x')->lockForUpdate()->first();\n        }, 3);\n    }\n\n    private function gated(\$actor)\n    {\n        Gate::forUser(\$actor)->authorize('x', Organization::class);\n    }\n}\n";

    expect(orgGateInsideTransactions($source))->toBe([])
        ->and(orgTransactionBodies($source))->toHaveCount(1);
});

/**
 * Where the acceptance seam asks the Gate, a policy or the permission cache. OrganizationMembership::addFromInvitation() runs inside a transaction that
 * ANOTHER class (OrganizationInvitations::accept) opened and locked, so the same-file transaction scan cannot see it; this reads that one method by name.
 * It is not a call-graph analysis: it covers the method's own text, and OrganizationAuthority (which it calls) is guarded Gate-free by its own test.
 *
 * @return list<string>
 */
function orgSeamGateAsks(string $source): array
{
    $methods = orgReadersMethods($source);

    if (! array_key_exists('addFromInvitation', $methods)) {
        return ['addFromInvitation() is missing: the guard and the file have drifted apart'];
    }

    return preg_match(ORG_GATE_ASKS, $methods['addFromInvitation'], $match) === 1 || preg_match('/PermissionRegistrar|->permissions\b|->roles\b/', $methods['addFromInvitation'], $match) === 1
        ? ['asks `'.trim($match[0], '(>:- ').'` inside the acceptance transaction']
        : [];
}

it('reads the closures of OrganizationMembership::transact() as transaction bodies, so a Gate call in one is caught (mutation G1)', function () {
    $source = file_get_contents(app_path('Services/OrganizationMembership.php'));

    $code = orgReadersStrip($source);

    // Every call of the helper is a body, and so is the one DB::transaction() the helper itself wraps them in.
    expect(orgTransactionBodies($source))->toHaveCount(substr_count($code, '$this->transact(') + substr_count($code, 'DB::transaction('))
        ->and(substr_count($code, '$this->transact('))->toBeGreaterThanOrEqual(6);
});

it('catches a Gate call inserted into an OrganizationMembership::add() transact callback, using the real file and the real scanners', function () {
    $source = file_get_contents(app_path('Services/OrganizationMembership.php'));
    $methods = orgReadersMethods($source);
    $needle = "            \$this->requireEnabled(\$org);\n";

    // The anchor is the first line of add()'s own callback: mutate that method only, so the probe is the "Gate inside add()" case and nothing else.
    expect($methods)->toHaveKey('add')
        ->and(substr_count($methods['add'], $needle))->toBe(1, 'the mutation anchor drifted: update the probe to a line of add()s callback')
        ->and(orgGateInsideTransactions($source))->toBe([]);

    // Insert at the first anchor AFTER add()'s own signature in the raw file (the methods above are comment-stripped copies, so they cannot be spliced back).
    $at = strpos($source, 'function add(');
    $anchor = strpos($source, $needle, $at);
    expect($at)->not->toBeFalse()->and($anchor)->not->toBeFalse();
    $mutant = substr($source, 0, $anchor)."            \$actor->can('organizations.manage');\n".substr($source, $anchor);

    expect($mutant)->not->toBe($source)
        ->and(orgGateInsideTransactions($mutant))->not->toBe([])
        ->and(implode(' ', orgGateInsideTransactions($mutant)))->toContain('can');
});

it('does not read the definition of transact() as a call, and reads a call in a nested arrow function', function () {
    $definition = "<?php\nclass P\n{\n    private function transact(Organization \$o, Closure \$work): mixed\n    {\n        return 1;\n    }\n}\n";
    $call = "<?php\nclass P\n{\n    public function e()\n    {\n        return \$this->transact(\$o, fn (\$org) => \$actor->can('x'));\n    }\n}\n";

    expect(orgTransactionBodies($definition))->toBe([])
        ->and(orgGateInsideTransactions($call))->not->toBe([]);
});

it('keeps the acceptance seam, which runs in a transaction another class opened, free of the Gate, a policy and the permission cache', function () {
    $source = file_get_contents(app_path('Services/OrganizationMembership.php'));

    expect(orgSeamGateAsks($source))->toBe([]);
});

it('detects a Gate, policy or permission-cache call inserted into addFromInvitation(), and a missing method', function (string $insert) {
    $source = file_get_contents(app_path('Services/OrganizationMembership.php'));
    $needle = "        \$now = now();\n";

    expect(substr_count($source, $needle))->toBe(1, 'the mutation anchor drifted');

    $mutant = str_replace($needle, "        {$insert}\n".$needle, $source);

    expect(orgSeamGateAsks($mutant))->not->toBe([])
        ->and(orgSeamGateAsks(str_replace('function addFromInvitation(', 'function renamed(', $source)))->not->toBe([]);
})->with([
    'can()' => ['$recipient->can("organizations.manage");'],
    'Gate::' => ['Gate::allows("organizations.manage");'],
    'authorize()' => ['$this->authorize($recipient);'],
    'hasPermissionTo()' => ['$recipient->hasPermissionTo("organizations.manage");'],
    'the permission registrar' => ['app(PermissionRegistrar::class)->forgetCachedPermissions();'],
]);
