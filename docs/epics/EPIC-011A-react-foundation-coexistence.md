# EPIC-011A: React Foundation and Coexistence Contract

**Status:** Planned  
**Parent epic:** [EPIC-011: React Frontend Migration](./EPIC-011-react-frontend-migration.md)  
**Decision record:** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)

---

## 1. Goal

Create the Inertia 3, React 19, TypeScript, shadcn/ui, and Wayfinder foundation for the frontend migration without migrating a major production module.

This phase establishes the contracts that every later migration phase will reuse: application bootstrap, shared props, typed routes, navigation, layouts, theme behavior, feedback, design primitives, tests, and Blade/Inertia coexistence. After EPIC-011A, page migrations should be primarily domain and presentation work rather than repeated frontend architecture discovery.

EPIC-011A proves the foundation through a temporary authenticated smoke page. Dashboard, profile, authentication, timer, and other production modules remain assigned to later EPIC-011 phases.

## 2. Current Baseline

The implementation must begin by rechecking these facts because package and application code may change while this epic remains planned.

| Concern | Current repository state |
|---|---|
| Backend | Laravel `13.17.0` on PHP `^8.3` |
| Build | Vite `8.1.0`, `laravel-vite-plugin` `3.1.0` |
| Styling | Tailwind CSS and `@tailwindcss/vite` `4.3.1` |
| Entry points | `resources/css/app.css` and `resources/js/app.js` |
| JavaScript | `app.js`, `bootstrap.js`, `timer-overlay.js`, `allocation-chart.js` |
| Bootstrap | Axios is exposed as `window.axios`; `X-Requested-With` is configured |
| App shell | `layouts/app.blade.php` owns metadata, Vite assets, theme bootstrap, navigation, timer, content, footer, and script stacks |
| Auth shell | Both a Blade component layout and a conventional Blade auth layout exist; their use must be confirmed before later consolidation |
| Theme | App shell restores `localStorage.theme` synchronously before paint, falling back to system preference; auth shells currently use a `theme` cookie and duplicate toggle code |
| Navigation | Blade handles permission visibility, active routes, billing destination selection, desktop/mobile menus, user menu, profile, logout, and theme control |
| Flash | Controllers use both `success` and `status`; pages render feedback inconsistently; no global app-shell contract exists |
| Timer | Blade partial plus bundled vanilla JavaScript appears on authenticated Blade pages and rehydrates from server-owned timer records |
| CSRF/session | Standard Laravel web session and CSRF middleware; a CSRF meta tag supports focused JSON requests |
| Frontend tests | No Vitest, React Testing Library, Playwright, TypeScript, ESLint, or Prettier setup |
| CI | No repository CI workflow is currently configured |

The current Vite server has local Docker/HMR settings that must be retained unless testing proves they are obsolete. Existing Blade and vanilla JavaScript entry points must continue to build during coexistence.

## 3. Package and Tooling Plan

Versions must be resolved and locked during implementation against Laravel 13, Vite 8, Node 22, and the then-current official Laravel React stack. Do not copy floating versions from this document. Use compatible stable releases except for Wayfinder, whose beta status is accepted and must be pinned deliberately.

### PHP runtime packages

- `inertiajs/inertia-laravel` compatible with Inertia 3
- `laravel/wayfinder`, pinned to the selected compatible pre-1.0 release

Do not add a second routing package. If Wayfinder is blocked, stop at the decision gate in section 8.

### JavaScript runtime packages

- `react` and `react-dom` at React 19-compatible versions
- `@inertiajs/react` and `@inertiajs/vite` at matching Inertia 3-compatible versions
- `clsx`, `tailwind-merge`, and `class-variance-authority` for the shadcn component model
- `lucide-react` as the standard interface icon set

shadcn/ui is a copy-in component workflow rather than a runtime component package. Its generated prerequisites and `components.json` must be reviewed before acceptance.

### JavaScript development packages

- `@vitejs/plugin-react` compatible with Vite 8 and React 19
- `typescript`, `@types/react`, `@types/react-dom`, and `@types/node`
- `@laravel/vite-plugin-wayfinder`
- `vitest`, `jsdom`, `@testing-library/react`, `@testing-library/jest-dom`, and `@testing-library/user-event`
- ESLint with the TypeScript, React, React Hooks, and import/configuration dependencies actually required by the chosen flat configuration
- Prettier and the Tailwind class-sorting plugin if compatible with Tailwind 4
- `@playwright/test`

Use the package lock as the authoritative exact-version record. Prefer peer-compatible constraints matching the official Laravel React starter at implementation time. Do not add dependencies merely because the starter kit includes them; React Compiler, Vite Plus, passkeys, WorkOS, SSR helpers, and unrelated starter features are not required.

### Explicit exclusions

EPIC-011A must not install:

- TanStack Query, Router, or Table
- Redux, Zustand, or another global state library
- SSR runtime or server packages
- Sanctum for the browser frontend
- PDF or document-rendering packages
- Feature-specific packages for drag/drop, charts, or Stripe

## 4. Frontend Directory Structure

Use lowercase directory names and kebab-case file names, matching the current official Laravel React convention. React component exports use PascalCase. Page folders follow application domains rather than mirroring every URL segment mechanically.

```text
resources/js/
  app.tsx
  bootstrap.ts
  components/
    ui/                    # copied shadcn primitives
    navigation/            # app header and navigation presentation
    feedback/              # alert/flash/loading/empty-state primitives
  layouts/
    app-layout.tsx
    auth-layout.tsx        # contract/shell only; no auth page migration
  pages/
    foundation/
      smoke.tsx
  hooks/
    use-appearance.ts
  lib/
    utils.ts               # cn() and small framework-neutral helpers
  types/
    index.d.ts
    navigation.ts
    shared.ts
  actions/                 # generated Wayfinder controller actions
  routes/                  # generated Wayfinder named routes
  wayfinder/               # generated Wayfinder runtime/types
```

Folder responsibilities:

- `components/ui`: minimally adapted shadcn primitives with no domain behavior.
- `components/navigation`: rendering of the server navigation DTO; no permission decisions.
- `components/feedback`: reusable feedback semantics and accessibility behavior.
- `layouts`: persistent page chrome and layout contracts.
- `pages`: Inertia page entry components grouped by domain; page component names remain descriptive and routes select them explicitly.
- `hooks`: reusable React behavior, not API/domain services.
- `lib`: small framework utilities; no general dumping ground.
- `types`: global/shared contracts only; feature types stay near their feature.
- `actions`, `routes`, `wayfinder`: generated output, never hand-edited.

Configure the `@/` alias to `resources/js`. Existing vanilla files remain until their owning phases replace them. `bootstrap.js` may become `bootstrap.ts` only after confirming existing imports remain behaviorally equivalent.

Wayfinder currently generates all three output directories by default. They should be gitignored and regenerated by Vite/build/CI, consistent with Wayfinder's documented reproducible-output model. If the production build environment cannot boot Laravel before Vite runs, revisit this generated-file policy explicitly rather than committing partial output ad hoc.

## 5. Inertia Bootstrap

### Root template

Add a minimal Inertia root Blade template, conventionally `resources/views/app.blade.php`, containing:

- document language, charset, viewport, and CSRF metadata
- synchronous shared theme initialization in `<head>`
- Vite CSS and `resources/js/app.tsx`
- `@inertiaHead`
- `@inertia`

It must not duplicate the legacy Blade navigation, timer, footer, or page script stacks. React `AppLayout` owns Inertia page chrome.

### Client entry

`resources/js/app.tsx` uses `createInertiaApp` and:

- resolves pages from `./pages/**/*.tsx` with `import.meta.glob`
- uses a deterministic lower-case page naming convention such as `foundation/smoke`
- mounts with React 19's root API through the Inertia setup callback
- applies a title function such as `<page title> - <application name>` and uses the application name alone when no page title is provided
- enables Inertia's progress indicator using the established accent color, unless accessibility testing identifies a better treatment
- does not create an SSR entry point

Use persistent layouts for authenticated pages so navigation and future timer state survive Inertia-to-Inertia visits.

### Middleware and versioning

Register `HandleInertiaRequests` in Laravel 13's middleware configuration for the web group. Its `version()` should delegate to the adapter's Vite-aware parent behavior so changed assets trigger a full reload. Do not alter unrelated Blade responses.

Laravel's existing web session, cookies, and CSRF middleware remain authoritative. Inertia/Laravel and Axios use the existing CSRF cookie/header behavior; do not globally serialize a CSRF token as a page prop. Keep the CSRF meta tag only where the root or legacy focused requests need it.

### Coexistence

Only routes returning `Inertia::render()` use the Inertia root. Existing routes returning `view()` continue through their current Blade layouts and assets. No catch-all route, separate API, or frontend router is introduced.

## 6. Shared Props Contract

`HandleInertiaRequests` must share a minimal, namespaced contract. Never serialize the authenticated `User` model directly.

```ts
type SharedPageProps = {
    app: {
        name: string;
    };
    auth: {
        user: AuthUser | null;
        permissions: string[];
    };
    navigation: NavigationGroup[];
    flash: FlashProps;
};

type AuthUser = {
    id: number;
    name: string;
    email: string;
};

type FlashProps = {
    success: string | null;
    error: string | null;
    status: string | null;
    warning: string | null;
};
```

Rules:

- `auth.user` is `null` for guests and contains only fields needed globally.
- `auth.permissions` is a sorted string array derived from effective permissions; no permission objects or pivot data.
- Roles are not globally shared. Current navigation and UI decisions can use effective permissions. A later page may receive role data as page-specific props when its domain requires it.
- `navigation` is empty for guests unless a later guest navigation requirement is documented.
- `flash` consumes and clears Laravel session flash values through Inertia's flash mechanism; support both existing `success` and `status` keys.
- Expensive or authenticated values use closures so they are evaluated only for Inertia responses when needed.
- Shared data keys must not collide with page props.
- Password hashes, remember tokens, two-factor secrets/recovery codes, provider tokens, raw roles/permissions relationships, organization membership internals, and unrelated profile fields must never be globally serialized.
- Validation errors remain Inertia-native and are not duplicated into `flash`.

TypeScript declaration merging should make the shared contract available to `usePage` without casting at every use site. PHP tests must verify both expected keys and absence of sensitive fields.

UI permission data controls visibility only. Laravel route middleware, policies, and services remain the security boundary.

## 7. Shared NavigationBuilder

Introduce a small server-side navigation builder and DTO/array contract consumed by both Blade and React during coexistence. Keep it application-specific.

Recommended server representation:

```text
NavigationGroup
  key
  label (nullable for primary group)
  items[]

NavigationItem
  key
  label
  href
  method (get or post where needed)
  activePatterns[]
  isActive
  children[] (only when genuinely required)
```

Permission checks and billing destination selection occur while building the model. Invisible items are omitted rather than emitted with permission names. The DTO must not expose authorization rules to the browser.

Preserve current behavior:

- Primary links: Tickets, Projects, Tasks, Time, Billing, CRM, Pages
- Billing operators go to operator invoices; billing viewers go to client invoices
- Management links: ticket queue, time reports, organizations, CMS pages, users, roles
- Profile is available to authenticated users
- Active state supports current wildcard route behavior
- Desktop and mobile navigation render the same item model

Presentation concerns remain outside the DTO:

- desktop versus mobile markup
- menu disclosure state
- icons and responsive labels
- keyboard/focus behavior
- theme control presentation
- user name/email presentation
- logout form/button styling

Logout is a command, not a normal navigation destination. React uses an Inertia POST action generated by Wayfinder; Blade retains a POST form. The model may expose a logout action only if needed to prevent route duplication, but it must preserve POST semantics.

Adapt `layouts/partials/nav.blade.php` to receive/render the builder output while retaining its existing Blade interactions. This is the only intentional legacy-shell refactor in EPIC-011A. Add focused tests proving that the Blade and Inertia representations receive equivalent destinations for representative permission sets.

## 8. Wayfinder Strategy

Install `laravel/wayfinder` and `@laravel/vite-plugin-wayfinder`, pinning compatible versions because Wayfinder remains beta.

Use the Vite plugin with form variants enabled if the implementation uses Wayfinder form helpers. Default generated directories are `resources/js/actions`, `resources/js/routes`, and `resources/js/wayfinder`.

Usage rules:

- Prefer named-route imports for navigation and when one controller method maps to more than one URI.
- Prefer controller-action imports for direct mutations with an unambiguous action.
- Pass route model keys/parameter objects in the generated supported shape; do not concatenate URLs.
- Pass query parameters through generated helper options rather than hand-building query strings.
- Import named exports where practical for tree shaking.
- Do not hand-edit generated output.

Generation contract:

- Vite development and production builds regenerate output.
- CI explicitly runs generation before type checking as an independently visible gate, even if Vite also generates during build.
- Clear stale route cache before generation in deployment/build environments where route caching may persist.
- Type checking must fail when imported route/action definitions are missing or incompatible.
- Generated directories are ignored, not committed, provided all supported build environments can run PHP/Laravel before the frontend build.

Representative proof must cover:

- a simple named GET route
- a parameterized/model-bound route
- a POST action such as logout
- query parameters
- a controller with multiple methods

Decision gate: if generation cannot run deterministically in local Docker, CI-equivalent commands, or the production asset-build process, document the exact incompatibility and stop EPIC-011A. Do not silently add Ziggy, custom URL helpers, or both route systems. Any fallback requires an ADR/EPIC amendment.

## 9. Application Layout Contract

`AppLayout` is the persistent authenticated layout for Inertia pages.

```text
AppLayout
  AppHeader
    PrimaryNavigation
    ThemeToggle
    UserMenu
    MobileNavigation
  ActiveTimerBar slot (empty in EPIC-011A)
  FlashRegion
  Page content
  AppFooter
```

EPIC-011A implements:

- permission-aware navigation from shared props
- active navigation state
- accessible desktop/mobile menus
- user identity summary and profile link
- POST logout
- theme toggle
- persistent layout behavior
- flash/status region
- page content landmark and footer
- a stable, empty future timer slot/interface

Phase D later implements the React timer and mounts it into the reserved slot. EPIC-011A must not call timer endpoints, duplicate the Blade timer, or mount React into the Blade shell.

The layout must support keyboard navigation, Escape dismissal where relevant, focus visibility, responsive behavior, and no layout overlap. It should not expose raw permission logic beyond rendering the already-filtered navigation model.

## 10. Auth Layout Contract

Create a minimal `AuthLayout` foundation in EPIC-011A because it establishes shared theme, title, logo/brand, feedback, spacing, and focus conventions and prevents later auth pages from creating another parallel shell.

The smoke page does not use it, and no Fortify view changes in this phase. Do not port login, invitation, password reset, or two-factor behavior. Phase C owns those pages and will verify which existing Blade auth layout is actually used before removing either legacy layout.

`AuthLayout` should compose shared primitives rather than copy `AppLayout`; it has no authenticated navigation, timer, or footer requirement unless Phase C later documents one.

## 11. Theme and FOUC Prevention

Unify future React behavior around the current authenticated-shell contract:

- Storage key: `theme`
- Values: `light` or `dark`
- Explicit stored selection wins
- With no stored selection, use `prefers-color-scheme`
- Apply `data-theme` to `<html>` synchronously before CSS paints
- React hook/context reads the applied value, updates the attribute and storage, and exposes the toggle
- Tailwind/shadcn semantic tokens resolve from the same root attribute

The pre-paint initializer must be shared or textually equivalent between the Blade and Inertia root templates during coexistence. It must not wait for React hydration.

Legacy authenticated Blade behavior remains unchanged except where extracting a shared initializer is demonstrably safe. The auth shell's cookie/localStorage inconsistency is documented for Phase C; EPIC-011A must not rewrite Fortify views merely to normalize it.

Test first paint with no preference, stored light, stored dark, and both system preferences. Navigating Blade -> Inertia -> Blade must preserve the selected appearance without visible flash or drift.

## 12. Design-System Foundation

Map existing useful tokens into shadcn/Tailwind semantic tokens instead of discarding them. Establish conventions, examples, and only the primitives needed by the shell and smoke proof.

Foundation decisions:

- Semantic colors: background, foreground, card, popover, primary, secondary, muted, accent, destructive, border, input, ring, plus success/warning/info extensions
- Dark mode driven by the shared `data-theme` contract
- Typography: retain Inter and JetBrains Mono unless a deliberate later brand decision changes them
- Spacing: Tailwind scale; avoid one-off page scales
- Radius: compact application defaults, generally no more than 8px for cards/panels unless a component requires otherwise
- Focus: visible `focus-visible` ring with sufficient contrast
- Buttons: primary, secondary, outline, ghost, and destructive hierarchy
- Forms: label, control, description/help, field error, disabled and submitting states
- Page headers: title, optional description, breadcrumb/action slots
- Panels/cards: reserved for genuine bounded content, not every page section
- Tables: responsive overflow, semantic headers, loading/empty patterns, server-owned pagination
- Badges: semantic status variants, not arbitrary color use
- Dialog/drawer: dialog for focused decisions; drawer/sheet for constrained mobile or secondary workflows
- Flash/toast: see section 13
- Loading: skeleton or explicit progress where content shape is known; avoid layout shifts
- Empty states: concise state and available action
- Destructive actions: explicit confirmation and server validation
- Responsive behavior: mobile navigation and content remain usable without hidden required actions
- Accessibility: semantic landmarks, labels, keyboard access, focus restoration, live-region feedback, contrast, reduced-motion awareness

Install only these initial shadcn primitives if used by the shell/smoke page:

- `button`
- `dropdown-menu`
- `sheet`
- `separator`
- `avatar`
- `tooltip`
- `alert`

Add later form, table, dialog, badge, skeleton, toast, and feature primitives on demand unless a foundation test genuinely requires one. Avoid bulk-installing the catalog.

## 13. Flash and Feedback Architecture

Use a global `FlashRegion` in `AppLayout` and `AuthLayout` with an accessible live region.

Contract:

- `success`: transient positive result
- `status`: neutral/informational result; retained for Fortify and existing controller compatibility
- `warning`: recoverable caution
- `error`: request-level failure not represented by field validation
- validation errors: rendered next to their fields and optionally summarized on long forms; never converted to toast-only feedback

Default Laravel redirect flashes render as dismissible inline alerts near the start of page content. This is reliable, accessible, and survives navigation without adding a notification framework. A lightweight toast may be introduced later for background or optimistic interactions, but EPIC-011A does not add a toast library.

Announcements use `role="status"` for noncritical feedback and `role="alert"` for errors. Feedback should not reappear from browser history; use Inertia 3 flash semantics rather than persistent shared history state.

## 14. Blade/Inertia Coexistence Rules

1. Existing Blade routes and views remain valid until their owning phase migrates them.
2. Inertia routes may navigate to Blade routes, and Blade routes may navigate to Inertia routes.
3. React uses Inertia `Link`/router only when the destination is known to return an Inertia response.
4. Links to legacy Blade routes use normal anchors or force a full document visit. Never let an Inertia visit expect a Blade response.
5. Blade links to Inertia routes may remain normal anchors; the destination boots the Inertia application after the full load.
6. Crossing rendering systems may perform a normal full document load. This is expected, not a defect.
7. Inertia-to-Inertia navigation uses persistent layouts and Inertia navigation.
8. Both shells consume the same server navigation model and permission results.
9. Theme preference remains consistent across full and Inertia visits.
10. Session, auth, CSRF, validation, and authorization remain Laravel web concerns shared by both systems.
11. The timer remains server-authoritative. Blade pages keep the existing timer implementation.
12. No React timer island or duplicate React root is mounted into Blade pages.
13. An individual page needs to know only whether a specific target is an Inertia or full-page link; it must not depend on a global "migration complete" switch.

During coexistence, the navigation DTO should carry an explicit transition hint such as `visit: 'inertia' | 'document'`, or the presentation layer should receive an equivalent server-owned list of migrated route names. Do not infer response type from URL shape. Updating that marker is part of each later route migration.

## 15. Smoke-Test Route and Page

Use option A: a temporary authenticated `GET /inertia-smoke` route named `inertia.smoke` returning `Inertia::render('foundation/smoke')`.

Reasons:

- It does not pull Dashboard/Profile scope into Phase A.
- It can deliberately exercise every foundation contract.
- It has no domain data or authorization ambiguity beyond authentication.
- It can be removed cleanly when Phase B supplies permanent Inertia coverage.

The page proves:

- Laravel -> Inertia response
- React and TypeScript rendering
- persistent `AppLayout`
- authenticated shared props and privacy boundaries
- shared navigation
- representative Wayfinder links/actions/parameters/query handling
- theme toggle and pre-paint behavior
- flash rendering through a smoke-only redirect/action if needed
- production Vite assets
- navigation to and from at least one Blade page

Restrict it to authenticated users and never expose debug/environment data. Mark the route and page as temporary in code comments and tests. Phase B removes them only after a permanent Inertia page covers the same bootstrap, layout, theme, navigation, and flash assertions.

## 16. TypeScript Contracts

Initial contracts:

```ts
type AuthUser = {
    id: number;
    name: string;
    email: string;
};

type AuthProps = {
    user: AuthUser | null;
    permissions: string[];
};

type NavigationItem = {
    key: string;
    label: string;
    href: string;
    method: 'get' | 'post';
    visit: 'inertia' | 'document';
    activePatterns: string[];
    isActive: boolean;
    children: NavigationItem[];
};

type NavigationGroup = {
    key: string;
    label: string | null;
    items: NavigationItem[];
};

type FlashProps = {
    success: string | null;
    error: string | null;
    status: string | null;
    warning: string | null;
};

type SharedPageProps = {
    app: { name: string };
    auth: AuthProps;
    navigation: NavigationGroup[];
    flash: FlashProps;
};
```

Serialization rules:

- Nullable PHP values serialize to `null`, not missing/empty-string variants, unless optionality is semantically meaningful.
- Arrays serialize as arrays even when empty.
- IDs use numbers consistently.
- URLs are strings produced server-side/Wayfinder, not route names requiring client interpretation.
- Global types contain no page-specific domain models.
- Prefer `type`-only imports and strict TypeScript settings.

## 17. Frontend Testing and Tooling

Install and configure Playwright in EPIC-011A. One smoke test is sufficient initially because it proves the mixed-rendering shell and establishes reusable browser infrastructure.

Proposed application-level scripts in `src/package.json`:

```json
{
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "typecheck": "tsc --noEmit",
    "lint": "eslint .",
    "lint:fix": "eslint . --fix",
    "format": "prettier --write resources/js resources/css",
    "format:check": "prettier --check resources/js resources/css",
    "test:unit": "vitest run",
    "test:unit:watch": "vitest",
    "test:e2e": "playwright test",
    "wayfinder:generate": "php artisan wayfinder:generate --with-form",
    "check": "npm run wayfinder:generate && npm run typecheck && npm run lint && npm run format:check && npm run test:unit && npm run build"
  }
}
```

The implementation may adjust `--with-form` or script syntax to match the pinned Wayfinder release, but generation behavior must remain explicit and deterministic. Root Docker wrapper scripts may be considered separately; do not overload the existing root `npm run build`, which currently means rebuilding Docker images.

Tooling expectations:

- Strict TypeScript suitable for React and Vite
- ESLint flat configuration with warnings treated as failures in CI
- Prettier check separate from lint
- Vitest with jsdom, Testing Library setup, and jest-dom matchers
- Tests colocated as `*.test.tsx` or in a consistent adjacent test directory
- Playwright configured for the existing local Docker URL/port through environment configuration
- Production Vite build remains the final frontend compilation proof

## 18. Backend Testing Changes

Add focused Pest coverage for foundation behavior:

- guest access to the smoke route redirects to login
- authenticated access returns the expected Inertia component
- shared app/auth/flash/navigation prop shape
- representative permissions include/exclude the correct navigation items
- billing permission selects the correct billing destination
- active route state is correct
- logout remains POST-only
- sensitive user attributes are absent from serialized props
- Wayfinder representative routes exist and route model binding remains correct
- a representative existing Blade route still returns its Blade view without an Inertia response
- smoke flash values are exposed once and consumed

Use Inertia's Pest/PHP response assertions. Do not rewrite unrelated HTML assertions; they migrate with their pages.

## 19. CI Expectations

No CI workflow currently exists. EPIC-011A defines the blocking contract and makes commands runnable, but does not expand into a broad CI/DevOps project unless the implementation owner separately approves adding a minimal workflow.

Required future blocking checks:

### PHP

- deterministic `composer install`
- Pint check using the repository's existing formatter
- full Pest suite

No PHP static-analysis tool is currently configured; adding one is outside EPIC-011A.

### Frontend

- deterministic `npm ci`
- route cache clear where relevant, then Wayfinder generation
- TypeScript check
- ESLint
- Prettier check
- Vitest
- production Vite build
- selected Playwright smoke test

EPIC-011A must document and locally verify the exact commands. If a CI workflow is approved in scope during implementation, it should orchestrate these commands without changing their local behavior. Otherwise, creating CI infrastructure is a follow-up issue and the commands remain mandatory review gates.

## 20. Acceptance Criteria

- [ ] Inertia 3 server and React adapters are installed and bootstrapped without SSR.
- [ ] React 19 and strict TypeScript render an authenticated Inertia page.
- [ ] Tailwind 4 continues to build Blade and React sources.
- [ ] shadcn is initialized with the agreed aliases and a lean primitive set.
- [ ] Wayfinder deterministically generates typed named routes and controller actions for representative GET, POST, parameterized, and query-string cases.
- [ ] Generated Wayfinder output has a documented ignore/regeneration policy and is not hand-maintained.
- [ ] `HandleInertiaRequests` is registered without changing unrelated Blade responses.
- [ ] Shared props match the minimal typed contract and exclude sensitive attributes.
- [ ] Effective permissions are sufficient globally; roles are not globally serialized.
- [ ] One NavigationBuilder supplies both Blade and React navigation.
- [ ] Current permission visibility, management items, active state, and billing destination behavior are preserved.
- [ ] `AppLayout` provides navigation, mobile/user menus, logout, theme, flash region, content, footer, and a future timer slot.
- [ ] A minimal `AuthLayout` foundation exists without migrating auth pages.
- [ ] Stored/system theme preference is applied before paint with no visible flash across Blade and Inertia transitions.
- [ ] Redirect flash values render accessibly and validation remains Inertia-native.
- [ ] Authenticated `/inertia-smoke` proves the complete foundation under production assets.
- [ ] Inertia links and normal-document links cross rendering systems correctly.
- [ ] The current Blade timer and legacy Blade pages remain functional.
- [ ] Existing backend tests remain green.
- [ ] Typecheck, lint, format check, Vitest, production build, and Playwright smoke checks pass.
- [ ] No TanStack, global state library, SSR, Sanctum, feature-specific migration, or PDF dependency is introduced.
- [ ] Production still serves PHP responses and static Vite assets with no runtime Node process.

## 21. Test Plan

| Contract | Pest | Vitest / RTL | Playwright | Static/build | Manual |
|---|---|---|---|---|---|
| Inertia smoke response/auth | Component, props, redirect | Smoke page render | Authenticated load | Build | - |
| Shared prop privacy | Presence/absence assertions | Typed fixtures | - | Typecheck | Payload inspection once |
| Navigation permissions | Permission datasets | Desktop/mobile rendering | Representative operator/user menus | Typecheck | - |
| Billing destination | Permission datasets | Link target | Navigate target | Wayfinder generation | - |
| Active navigation | Route assertions | Active state/ARIA | Cross-page state | - | - |
| Logout | POST/CSRF behavior | Menu command | Logout flow | Wayfinder generation | - |
| Theme/FOUC | - | Hook/storage/system cases | Blade <-> Inertia transitions | CSS/build | Slow-motion first-paint check |
| Flash feedback | One-time shared flash | Roles/dismissal/live region | Redirect flash | - | Screen-reader spot check |
| Coexistence | Blade response remains non-Inertia | Link type rendering | Blade -> Inertia -> Blade | Build | - |
| Wayfinder | Routes remain registered | Generated helper use compiles | Representative navigation | Generate/typecheck | - |
| Accessibility shell | - | Keyboard/ARIA assertions | Keyboard mobile/user menus | ESLint where applicable | Focus/contrast review |
| Legacy regression | Existing suite | - | One representative Blade page | Full build | Timer spot check |

Manual verification is limited to behavior difficult to prove reliably in automated tests, especially visible first-paint flash, focus feel, and a brief existing timer regression check.

## 22. Implementation Sequence

### Work Package 1: Dependency and tooling lock

Intent: establish compatible package versions and configuration skeletons.

Likely files: `composer.json`, `composer.lock`, `package.json`, lockfile, TypeScript/ESLint/Prettier/Vitest/Playwright configs.

Tests first: define executable empty-suite/type/build commands and expected failure/pass behavior.

Gate: deterministic installs; no excluded dependencies; existing Vite build still works.

### Work Package 2: Inertia server foundation

Intent: install/register Laravel adapter and middleware without affecting Blade routes.

Likely files: `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`, root Inertia Blade template, focused Pest test.

Tests first: Blade route unchanged; guest/auth smoke behavior expected.

Gate: middleware/versioning works and existing Blade response tests pass.

### Work Package 3: React bootstrap and TypeScript

Intent: create `app.tsx`, page resolution, aliases, root mounting, title/progress conventions.

Likely files: `resources/js/app.tsx`, `bootstrap.ts`, `tsconfig.json`, `vite.config.ts`, `resources/css/app.css` source scanning.

Tests first: minimal component/bootstrap test and type contract fixture.

Gate: production build and typecheck pass while legacy entry behavior remains available.

### Work Package 4: Wayfinder contract

Intent: generate and consume typed routes/actions deterministically.

Likely files: Vite config, package scripts, `.gitignore`, generated directories, smoke component/tests.

Tests first: representative route/action compile cases.

Gate: clean generation followed by typecheck/build succeeds twice consecutively; production build process is proven.

### Work Package 5: Shared prop types and middleware

Intent: implement minimal auth/app/flash contracts with privacy boundaries.

Likely files: middleware, `resources/js/types/*`, Pest shared-prop tests.

Tests first: expected shape and sensitive-field absence.

Gate: PHP and TypeScript contracts agree; unauthenticated values are correct.

### Work Package 6: NavigationBuilder and Blade adaptation

Intent: establish one server navigation source before React shell use.

Likely files: navigation builder/DTO, service registration if needed, Blade nav partial, tests.

Tests first: permission matrices, billing destination, active state.

Gate: current Blade navigation remains behaviorally equivalent and React receives the same model.

### Work Package 7: Theme and design tokens

Intent: map semantic tokens and establish no-flash appearance primitives.

Likely files: CSS, pre-paint initializer, appearance hook/context, component configuration.

Tests first: stored/system preference and hook behavior.

Gate: automated theme tests pass and manual first-paint check shows no flash.

### Work Package 8: App/Auth layouts and feedback

Intent: implement persistent app shell, minimal future auth shell, and accessible feedback.

Likely files: layouts and navigation/feedback components; selected shadcn primitives.

Tests first: menu keyboard behavior, logout action, flash semantics, layout composition.

Gate: RTL accessibility behavior passes; no timer endpoint is used by React.

### Work Package 9: Smoke route and coexistence proof

Intent: exercise the complete foundation without claiming a production module migration.

Likely files: temporary route/controller or route closure, smoke page, Pest and Playwright tests.

Tests first: auth, response component, cross-renderer links, flash lifecycle.

Gate: production assets support Blade -> Inertia -> Blade navigation with consistent theme/navigation.

### Work Package 10: Quality gates and handoff

Intent: make every promised command reliable and document Phase B cleanup obligations.

Likely files: scripts/configs and documentation only; CI workflow only if separately approved.

Tests first: run each command independently before aggregate `npm run check`.

Gate: full Pest, typecheck, lint, format check, Vitest, build, and Playwright smoke pass; diff contains no production module migration.

## 23. Rollback and Coexistence Safety

EPIC-011A is safe to merge when:

- Existing Blade pages render through their current layouts and entry points unchanged except for the tested navigation-model adaptation.
- Removing the smoke route/page/tests leaves the foundation intact and does not affect Blade routes.
- Inertia middleware changes only Inertia requests/responses and shared data.
- No authentication, session, CSRF, Fortify, policy, tenant, or domain behavior changes.
- Navigation permission tests protect both Blade and React consumers.
- The Blade timer remains loaded only by the Blade shell and continues to recover from server state.
- The React layout contains no active timer implementation yet.
- Failure of React assets affects only the temporary smoke page, not legacy production pages.
- Rollback can remove the smoke route and Inertia-specific bootstrap while leaving existing Blade assets operational.
- Production requires Node only to build static Vite assets; PHP serves the application at runtime.
- No SSR or persistent Node process is introduced.

## 24. Out of Scope

EPIC-011A does not include:

- Dashboard or profile migration
- Fortify/auth/invitation page migration
- Fixing auth-page behavior except documenting later prerequisites
- Timer or time-tracking migration
- Allocation chart migration
- Projects, board, milestones, or task migration
- Tickets or operator reporting migration
- Billing UI or Stripe React migration
- CRM, organizations, CMS, users, or roles migration
- PDF/document generation or EPIC-012 work
- TanStack packages
- Redux/Zustand/global state
- SSR
- Separate API or Sanctum work
- Broad backend refactoring
- PHP static-analysis adoption
- Broad CI/CD or deployment redesign
- Removal of existing Blade views or legacy feature JavaScript

## 25. Risks and Open Questions

### Known risks with defined handling

- **Wayfinder beta/build determinism:** pin versions, generate explicitly, clear stale route caches, and use the section 8 decision gate.
- **Mixed link behavior:** carry an explicit server-owned visit mode; never infer Blade/Inertia response type from URL shape.
- **Navigation regression:** adapt Blade to the shared builder first and protect it with permission datasets.
- **Shared-prop leakage:** whitelist fields and test forbidden attributes.
- **Theme drift:** preserve the authenticated shell's localStorage/system behavior and test full transitions.
- **Root script coexistence:** keep legacy entry points until owning phases remove them; verify both in production builds.

### Decisions not blocking implementation planning

- Whether to add an actual CI workflow during EPIC-011A requires scope approval because no CI exists today. The quality commands and blocking contract are required regardless.
- The exact compatible dependency versions must be selected and locked at implementation time; the architecture and compatibility boundaries are settled.

No live-repository fact contradicts ADR-007 or the parent EPIC-011. EPIC-011A should stop for user/architecture review only if Wayfinder cannot generate deterministically in the supported build environments or if adapting Blade navigation to a shared model would change current authorization behavior.
