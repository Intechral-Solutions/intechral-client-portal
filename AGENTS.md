# Agent guidance

Durable rules for coding agents working in this repository. The design contract is
[`docs/design/direction-d-design-system.md`](docs/design/direction-d-design-system.md); epics and the roadmap are in `docs/`.

## Checks

- After any change under `src/resources/js`, run the **frontend** lint from `src/`: `cd src && npm run lint`
  (or `./dev check --no-php` for the whole frontend gate). The repository-root `npm run lint` is Pint for PHP, not this.
- Blade views are guarded by `tests/Unit/Configuration/BladeThemeGuardTest.php`; run it after any `resources/views` change.
- `./dev check` is the full local gate. `./dev test:e2e` runs the browser suite.

## Terminology

User-facing text says **service request** (full name) or **request** (short form), and **Helpdesk** for the workspace; it never says "ticket". `Ticket` stays the technical name of the model,
tables, routes, permissions and APIs. Glossary and rules: [`docs/product/release-1.0-r1-7-service-request-terminology.md`](docs/product/release-1.0-r1-7-service-request-terminology.md).

Likewise for the **Directory** (DIR-1; glossary in [`docs/product/release-1.0-dir-0-directory-identity-organization.md`](docs/product/release-1.0-dir-0-directory-identity-organization.md#3-terminology)).
Operator-visible text says **Directory** for the workspace, **Person** / **People** for a Directory record of a human, and **Organization**
for the one concept that spans the Directory profile and the portal organization. It says **Enable portal access** (never "Promote"),
**Portal access** for the state and section, **Organization access** for the System index, and **Linked organizations** on projects. It never
says "Company", "CRM", "Org" or "Contact" for a Directory record. Since ORG-2 there is ONE canonical Organization record (`Organization`, the
`organizations` table: Directory profile, portal state and memberships); `CrmContact` / `crm_contacts` (a Person, whose `organization_id` is a Directory
affiliation that decides no access), the `crm.*` routes (transitional names, kept until ORG-3), `crm.manage` and `project_organization` (the Project link)
stay the technical names. `CrmCompany`, `crm_companies` and `project_company` are retired: never reintroduce them, and never describe them as current.
**Classification** is a descriptive label only and is never an input to any authorization decision; its editing selector arrives with ORG-3. **"Contact" keeps its Helpdesk meaning**: the customer contact a partner raises a service request for
is always a User, and that wording is unchanged. A detail page may use the record's own name as its title, but the navigation item and the
section it opens must still agree.

## Design system (React)

- **Shared primitives own their visual styling.** Callers of `components/ui/*` add layout (margin, width, flex/grid placement),
  not colour, typography, spacing, shape, effects, or height/size. Need a different look? Use or add a **variant**.
- **Use the Direction D semantic utilities** (`text-text`, `text-danger`, `bg-surface`, `border-rule`, `bg-ink` …), never raw palette
  colours (`bg-red-500`) or literals.
- **Runtime values** (a measured width, a drag transform) are legitimate: set a CSS custom property inline and consume it from a
  static class (`w-(--progress-value)`), or use the narrow documented exception.
- Keep class names **static**: no `` `bg-${x}` ``. Tailwind only builds classes it can read.

## `shadcn/*` lint errors

`@shadcn/lint` enforces the rules above as ESLint errors (`shadcn/no-restyle`, `no-raw-colors`, `no-arbitrary-values`,
`no-inline-styles`, `no-unknown-classes`, `require-static-classes`). **Treat them as Direction D architecture signals, not noise.**
Read the message: it names the component's variants and the file that owns the look. Do not silence a rule, add a suppression, or
widen a contract without understanding the component or theme contract behind it, and say why in the change.

Existing accepted findings are recorded in `src/eslint-suppressions.json`. It must only shrink: fix a finding, then run
`npx eslint 'resources/js/**/*.{ts,tsx}' vite.config.ts playwright.config.ts --prune-suppressions` from `src/`. Never use broad
`eslint-disable` comments to make a new violation pass. Details: [`docs/testing/ci.md`](docs/testing/ci.md#react-design-system-lint-shadcnlint).
