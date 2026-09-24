# EPIC-011: React Frontend Migration

**Status:** In Progress
**Decision record:** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)

> **Strategic note (2026-09-24): product priority has been rebased.**
>
> - The React/Inertia migration remains valid. Its architecture, migration principles, and completed phases A–E continue to inform all implementation.
> - This epic is no longer the source of product priority. The governing strategic sequence is now the [Product Roadmap](../product/product-roadmap.md), with direction in [Platform Product & UX Direction](../product/platform-product-ux-direction.md) and [Information Architecture](../product/information-architecture.md).
> - Remaining phases (F–K) are not executed in letter order. Renderer migration may be combined with redesign and product work instead of faithfully recreating legacy screens; the roadmap maps each remaining phase to the product work that now delivers it ([mapping](../product/product-roadmap.md#relationship-to-epic-011-and-its-remaining-phases)).
> - Phase F (Tickets) has not begun and has no implementation document. Critical Ticket authorization/integrity fixes are sequenced first as a separate hardening package; Helpdesk implementation sequencing is revisited under the roadmap and new design system.
>
> The phase sequence below is preserved unchanged as the historical plan and implementation record.

---

## Goal

Move the Intechral Client Portal frontend from Blade and page-specific JavaScript to an incremental Inertia 3 + React 19 + TypeScript architecture while preserving Laravel as the authority for routing, sessions, validation, authorization, tenant scoping, and business logic.

The migration should modernize the interface rather than reproduce every Blade screen pixel-for-pixel. Behavioral correctness is mandatory; visual modernization is allowed and encouraged within the shared design system established in Phase A.

This epic is the roadmap. Each phase should become its own `EPIC-011<letter>` implementation document with detailed stories, acceptance criteria, migration inventory, and tests before implementation begins.

## Scope

In scope:

- Every currently user-facing Blade-rendered application page unless explicitly documented as an intentional Blade exception
- Authenticated application shell and navigation
- Fortify authentication views
- Invitation registration and invalid/expired invitation views
- Dashboard and profile
- Time tracking, allocation, timer UI, and operator time reports
- Projects, kanban, milestones, and tasks
- User and operator tickets
- Billing/invoice UI and Stripe payment UI
- CRM and organizations
- CMS viewer/operator pages
- Administration pages
- Removal of superseded Blade page views and page-specific JavaScript after migration
- Frontend quality tooling, component tests, and critical browser tests

## Explicit Non-Goals

- Separate SPA/API architecture or Laravel Sanctum solely for the browser frontend
- Inertia SSR
- PDF/document generation or renderer selection
- Statement of Work feature implementation
- TanStack Query as a default server-state layer
- TanStack Router
- Global adoption of TanStack Table without a demonstrated screen-specific need
- Replacing existing Laravel/Fortify/Spatie domain and authorization logic with starter-kit backend logic

PDF/document work is tracked separately in [EPIC-012](./EPIC-012-document-generation.md).

## Architecture Baseline

### Frontend stack

| Concern | Choice | Notes |
|---|---|---|
| Page protocol | Inertia.js 3 | Client-side rendering only |
| Laravel adapter | `inertiajs/inertia-laravel` | Keep Laravel routes/controllers |
| React adapter | `@inertiajs/react` | React 19 |
| Vite integration | `@inertiajs/vite` + React Vite plugin | No SSR server |
| UI | React 19 + TypeScript | New React code in TS/TSX |
| Styling | Tailwind CSS 4 | Preserve and map useful existing tokens |
| Components | `shadcn/ui` | Own/adapt components locally |
| Typed routes/actions | Laravel Wayfinder | Pin version; Ziggy fallback only if needed |
| Local UI state | React state/reducers/context where appropriate | Timers, dialogs, forms, drag state |
| Server application state | Inertia 3 first | Partial/deferred/once/merged props, prefetch, `WhenVisible` |
| Focused interactive endpoints | Existing/new narrow JSON endpoints | Use `fetch`/Axios when simpler than page visits |
| Drag and drop | `@dnd-kit/core` | Project board |
| Charting | Existing Chart.js approach first | React wrapper only if it behaves cleanly |
| Stripe | `@stripe/react-stripe-js` + `@stripe/stripe-js` | Replace inline Stripe.js wiring |
| Backend tests | Pest | Existing suite remains authoritative |
| Component tests | Vitest + React Testing Library | New |
| Critical browser tests | Playwright | Selected workflows only |

### TanStack policy

Do not install TanStack Query during the foundation phase.

Reconsider it only if a later feature demonstrates a concrete requirement such as background refresh, polling, normalized cross-page caching, or several independently refreshed server resources that Inertia 3 and focused Laravel endpoints do not handle cleanly.

## Migration Principles

1. **Laravel remains authoritative.** React replaces presentation, not backend authorization or business rules.
2. **Behavioral parity, not pixel parity.** Existing behavior must remain correct; visuals may be improved.
3. **Incremental coexistence.** Blade and Inertia may coexist safely until migration completes.
4. **One shared navigation model.** Permission visibility and destinations must not drift between Blade and React.
5. **Minimal shared props.** Serialize only the user, permissions, navigation, flash/status, and app metadata actually required by the UI.
6. **Inertia-first data flow.** Do not recreate an API/client cache layer without demonstrated need.
7. **Focused JSON endpoints are allowed.** Highly interactive features can keep narrow JSON contracts where they are the simplest design.
8. **No temporary React architecture that outlives its value.** Avoid standalone React islands where a simpler coexistence approach works.
9. **Every migrated phase removes its obsolete JavaScript.** Do not leave dead inline scripts or globals behind.
10. **No PDF work in this epic.** Document rendering is a separate architectural decision.

## Phase A: Foundation and Coexistence Contract

Detailed implementation plan: [EPIC-011A](./EPIC-011A-react-foundation-coexistence.md)

### Scope

- Install/configure Inertia 3, React 19, TypeScript, shadcn/ui, and Wayfinder.
- Add `@inertiajs/vite` and React Vite integration.
- Create the minimal Inertia root Blade template.
- Create `resources/js/app.tsx` and the standard React directory structure:
  - `Pages/`
  - `Components/`
  - `Layouts/`
  - `hooks/`
  - `lib/`
  - `types/`
- Add typed shared page props.
- Add/configure `HandleInertiaRequests`.
- Create authenticated `AppLayout` and guest/auth layout foundations.
- Implement the shared server-side `NavigationBuilder`/navigation model and adapt the existing Blade navigation to consume it.
- Establish theme persistence and no-flash pre-paint behavior for Blade and React coexistence.
- Establish centralized flash/status presentation.
- Define design-system conventions.
- Configure TypeScript, ESLint, Prettier, Vitest, React Testing Library, and Playwright.
- Configure Wayfinder generation and type checking.
- Add a dedicated authenticated Inertia smoke route or select a small real page suitable for validating the pipeline. Do not use the unreachable `welcome.blade.php` as the smoke target.
- Inventory shared props for privacy and payload size.

### Design-system acceptance area

Establish conventions for:

- Semantic colors and dark mode
- Typography and spacing
- Buttons/destructive actions
- Forms, labels, validation, help text, loading and disabled states
- Tables, filtering, sorting, pagination
- Empty/loading/deferred/error states
- Dialogs and drawers
- Flash/toast messages
- Responsive navigation
- Focus and keyboard interaction
- Date/time/currency formatting
- Page headers and breadcrumbs
- Operational screen density

### Risks

- Shared props expose too much domain/user data.
- Navigation behavior diverges between Blade and React.
- Wayfinder pre-1.0 behavior conflicts with unusual routes.
- Theme handling causes flashes during mixed rendering.

### Exit criteria

- A production-mode build renders at least one authenticated Inertia page end-to-end.
- Existing Blade pages still function.
- Blade and React use the same server navigation model.
- Theme behavior is correct before first paint.
- Wayfinder generation and TypeScript checks pass.
- `npm run check` and relevant Pest tests pass.
- No SSR process is required.

## Phase B: Dashboard and Profile

Detailed implementation plan: [EPIC-011B](./EPIC-011B-dashboard-profile.md)

### Scope

- Dashboard
- Profile page
- Fortify-backed profile information update
- Password changes
- Two-factor configuration areas on the profile page
- Session revocation
- Social-account unlinking where applicable

### Why first

These are real application pages but comparatively bounded. They validate forms, Fortify integration, shared layout behavior, flash messages, authenticated props, and page-level patterns before converting the more interactive modules.

### Risks

- Fortify error bags and redirect/flash semantics
- Two-factor interactions
- Shared profile data serialization

### Exit criteria

- Dashboard and profile are Inertia pages.
- Existing permissions and profile workflows behave identically or better.
- Backend assertions use `assertInertia` where appropriate.
- Component and critical browser tests cover the converted profile flows.

## Phase C: Authentication and Invitations

Detailed implementation plan: [EPIC-011C](./EPIC-011C-authentication-invitations.md)

### Scope

Migrate all guest/auth presentation:

- Login
- Forgot password
- Reset password
- Password confirmation
- Two-factor challenge
- Invitation registration
- Invalid/expired invitation view
- Invitation continuation through Google/Microsoft where a page response is involved

### Prerequisite remediation

Fix the currently identified two-factor recovery-code toggle that uses Alpine directives without Alpine being installed. Do not preserve a known broken interaction as migration parity.

### Rules

- Keep invitation-only registration semantics.
- Keep existing Fortify backend behavior.
- Do not import starter-kit registration/auth backend logic wholesale.

### Risks

- Invitation SSO continuation
- Two-factor recovery mode
- Redirect semantics after authentication
- Duplicate/unused auth layouts in the current Blade tree

### Exit criteria

- All user-facing auth/invitation pages are React/Inertia.
- Login, reset, invitation, password confirmation, 2FA, and supported SSO entry/continuation flows are verified.
- Unused legacy auth layouts/scripts are removed once no longer referenced.

## Phase D: Time Tracking, Timer, Allocation, and Operator Reports

Detailed implementation plan: [EPIC-011D](./EPIC-011D-time-tracking-timer.md)

### Scope

- Personal time index
- Time allocation page
- Operator time reports
- React active timer bar inside `AppLayout`
- React time-entry interactions
- Allocation editing/drag behavior

### Timer coexistence strategy

- Blade pages keep the existing Blade/JS timer until those pages are retired.
- Inertia pages use the React timer in `AppLayout`.
- Both consume the same server-authoritative endpoints and timer records.
- Blade-to-Inertia and Inertia-to-Blade transitions perform normal reload/rehydration.
- Inertia-to-Inertia navigation preserves the React layout and timer state.

Do not introduce TanStack solely for timer state.

### Allocation chart strategy

Do not pre-commit to `react-chartjs-2`. First determine whether a direct Chart.js instance inside a React component (`useRef`/`useEffect`) is simpler and safer with `chartjs-plugin-dragdata`. Use the React wrapper only if it adds value without fighting direct canvas/plugin behavior.

### Behavior that must be preserved

- Multiple concurrent timers if currently supported
- Reload recovery
- Client-side elapsed display backed by server timestamps
- Inline description editing where retained
- Stop/finalize behavior
- Current timer rounding rules
- Billed/invoiced locks
- 15-minute allocation behavior
- Operator reporting/export behavior

### Exit criteria

- Time pages and operator reports are Inertia pages.
- Timer start/edit/stop/reload behavior is covered.
- Cross-renderer transition behavior is tested during coexistence.
- Allocation persistence, rollback/reload on failure, and billing locks are covered.
- Legacy time scripts are removed when no longer needed by Blade pages.

## Phase E: Projects and Tasks

Detailed implementation plan: [EPIC-011E](./EPIC-011E-projects-kanban.md)

**Status: Verified (2026-09-23).** WP0–WP10 are complete (see EPIC-011E for work-package detail and Amendment 11 for the verification record). Project index/create/edit, the board, milestones, project task detail, and the unified `/tasks` list are all Inertia/React; no Blade view remains under `resources/views/projects` or `resources/views/tasks`. Device-matrix and exhaustive assistive-technology testing for this phase are deliberately deferred to final platform-level QA.

### Scope

- Project index/create/show/edit
- Project board
- Project tasks
- Project milestones
- Checklist interactions
- Comments/forms associated with project tasks/milestones
- Unified task list

### Implementation direction

- Replace native HTML5 kanban drag/drop with `@dnd-kit/core`.
- Use local optimistic state for drag movement and Laravel for authoritative mutation.
- Revert or refresh cleanly on failed movement.
- Convert inline form/modals/disclosure JavaScript to React components.

The live assessment in [EPIC-011E](./EPIC-011E-projects-kanban.md) refines this direction: optimistic movement is planned around Inertia 3 `router.optimistic()` (provisional until the EPIC-011E WP0 spike proves it, with a no-state-library fallback) rather than a separate local copy of the board, `@dnd-kit` is narrowed to `core` + `sortable` as a pointer/touch enhancement over a library-independent keyboard Move menu, and a backend-hardening package precedes the page conversions.

### Risks

- Nested authorization
- Drag rollback
- Project/company membership rules
- Existing inline script behavior hidden in multiple views

### Exit criteria

- Project/task views are Inertia pages.
- Kanban movement passes critical Playwright coverage.
- Policies and nested authorization remain unchanged.
- Superseded project/task inline scripts are removed.

## Phase F: Tickets

### Scope

- User ticket list/create/show
- Operator ticket queue/show
- Replies and internal notes
- Attachments
- Bulk actions
- Ticket reports

### Data-table policy

Use standard Inertia query parameters, pagination, partial/deferred props, and normal React table components first.

Evaluate TanStack Table only if the operator queue/reporting screens demonstrate enough client-side table complexity to justify it. Do not introduce TanStack Query as a side effect of selecting TanStack Table.

### Risks

- Tenant visibility
- Private/internal note visibility
- Attachments
- Query/filter state preservation
- Bulk-selection behavior

### Exit criteria

- User/operator ticket views are Inertia pages.
- Existing HTML-oriented Pest tests for these pages are converted to Inertia assertions.
- Queue, reply/note, attachment, bulk, and reporting flows are verified.

## Phase G: Billing and Stripe

### Scope

- Operator invoice list/create/show/edit
- Dynamic line-item authoring
- Send/status actions
- Manual payment recording UI
- Client invoice list/show
- Stripe payment page

### Implementation direction

- Replace dynamic inline invoice form logic with React components.
- Replace hand-written Stripe.js/Payment Element wiring with `@stripe/react-stripe-js` and `@stripe/stripe-js`.
- Keep Stripe webhook processing server-side and outside the page migration.
- Keep financial calculations authoritative on the server even when React provides immediate display totals.

### Explicit exclusion

PDF generation/download is not part of this phase. It belongs to [EPIC-012](./EPIC-012-document-generation.md).

### Exit criteria

- Billing pages are Inertia pages.
- Server-calculated totals and invoice state rules are unchanged.
- Stripe test-mode critical flow passes browser coverage where credentials/environment permit.
- Stripe webhook behavior remains unaffected.
- Superseded invoice/payment inline scripts are removed.

## Phase H: CRM and Organizations

### Scope

- Company index/create/show/edit
- Contact index/create/show/edit
- Organization index/show and relevant membership/role interactions

### Risks

- Tenant scoping
- Organization membership authorization

### Exit criteria

- CRM/organization pages are Inertia pages.
- Tenant isolation and membership authorization suites pass.
- Legacy views/scripts are removed where no longer referenced.

## Phase I: CMS

### Scope

- User/public-facing CMS index/show pages that are currently part of the authenticated portal surface
- Operator CMS index/create/edit/publishing flows

### Risks

- Safe rendered content
- Published/draft visibility
- Operator-only editing

### Exit criteria

- CMS page responses in scope are Inertia-based.
- Draft/published visibility and permissions pass tests.
- Content rendering remains safe.

## Phase J: Administration

### Scope

- Role index/create/edit
- User index/show
- Relevant permission-management interactions

### Risks

- Permission editing can affect current-user capabilities.
- Navigation may change immediately after permission mutations.

### Exit criteria

- Administration pages are Inertia pages.
- Role/permission behavior remains server-authoritative.
- Navigation reflects changed permissions safely after the next authoritative response.

## Phase K: Decommission and Hardening

### Scope

- Remove replaced Blade page views and partials.
- Remove superseded inline scripts and standalone JavaScript.
- Audit all remaining `view()` responses.
- Document intentional Blade exceptions such as framework/error pages.
- Remove globals such as `window.AllocationData` when no longer needed.
- Audit frontend bundle size and duplicate dependencies.
- Accessibility pass across converted application shell and critical workflows.
- Cross-browser validation.
- Remove migration-only compatibility code.
- **Assistive-technology and device matrix, deferred here from the individual phases.** Per-phase verification covers keyboard operation and accessibility-tree semantics in a real browser, plus a screen-reader smoke test where one was available (EPIC-011E's WP10 had an owner-run NVDA smoke walkthrough on Windows, 2026-09-23). Exhaustive screen-reader certification across NVDA, JAWS and VoiceOver, and validation on real iOS Safari and Android Chrome devices, are deliberately held until this phase so they run once against the finished surface rather than being repeated per phase. Phases carrying this deferral: **E** (board drag/touch and the Move menu, dialogs, checklist).

### Exit criteria

- All intended application pages use Inertia/React.
- No obsolete page scripts or dead Blade pages remain.
- Any remaining Blade views are intentional and documented.
- Full backend/frontend/critical-browser CI gates pass.
- The deferred assistive-technology and real-device matrix above has been executed, and every phase that deferred into it is cleared.

## Blade / Inline JavaScript Migration Inventory

The implementation phases must account for the current inline behavior identified in the live repository:

| Current Blade location | Behavior | Owning phase |
|---|---|---|
| `layouts/app.blade.php` | Pre-paint theme restoration | A |
| `layouts/partials/nav.blade.php` | Theme toggle, user menu, mobile nav, dismissal | A |
| `components/layouts/auth.blade.php` | Auth theme initialization/toggle | A/C |
| `layouts/auth.blade.php` | Duplicate auth theme behavior | A/C |
| `components/time-tracker.blade.php` | Context lookup and timer start | D |
| `time/index.blade.php` | Form disclosure, context selectors, timer events | D |
| `time/allocation.blade.php` | Chart data/routes globals | D |
| `billing/invoices/_form.blade.php` | Dynamic line items and totals | G |
| `billing/payment/show.blade.php` | Stripe Payment Element | G |
| `operator/tickets/index.blade.php` | Bulk selection | F |
| `projects/board.blade.php` | Native drag/drop | E — migrated (deleted; EPIC-011E WP5) |
| `projects/create.blade.php` | Conditional form behavior | E — migrated (deleted; EPIC-011E WP3) |
| `projects/edit.blade.php` | Conditional form behavior | E — migrated (deleted; EPIC-011E WP3) |
| `projects/milestones/index.blade.php` | Editing modal | E — migrated (deleted; EPIC-011E WP4) |
| `projects/tasks/show.blade.php` | Checklist mutation | E — migrated (deleted; EPIC-011E WP7) |
| `tasks/index.blade.php` | New-task disclosure | E — migrated (deleted; EPIC-011E WP8) |

Each phase must re-inventory its own views before implementation because this table is a planning baseline, not permission to ignore code added later.

## Route/View Coverage Matrix

| Phase | Blade-rendered application areas |
|---|---|
| A | Inertia root, shared authenticated shell, shared auth shell foundation, navigation/theme/flash infrastructure |
| B | Dashboard, profile |
| C | Login, forgot/reset password, confirm password, 2FA challenge, invitation registration, invalid invitation |
| D | Personal time, allocation, operator time reports, timer UI |
| E | *(none — migrated to Inertia, EPIC-011E WP3–WP8)* Projects, board, milestones, project tasks, and unified tasks are no longer Blade-rendered |
| F | User tickets, operator queue/show/reports/bulk/replies |
| G | Operator invoices, client invoices, Stripe payment |
| H | Companies, contacts, organizations/memberships |
| I | CMS viewer and operator CMS |
| J | Roles and users |
| K | Intentional Blade exception audit and decommission |

Non-page endpoints such as downloads/exports, Stripe webhooks, SSO redirects/callbacks, focused JSON endpoints, mutation routes, health routes, and storage responses are not converted merely for the sake of React.

Framework/error pages may remain Blade intentionally.

## Testing Strategy

### Backend/integration

Pest remains authoritative for:

- Authentication and authorization
- Tenant isolation
- Validation
- Business/domain behavior
- Controller responses
- Inertia component/prop contracts after conversion

As a route migrates, replace brittle HTML-fragment assertions with `assertInertia` component/prop assertions plus direct authorization/validation/domain assertions.

### Frontend unit/component

Use Vitest + React Testing Library for behavior that benefits from isolated UI tests, especially:

- Forms and validation rendering
- Dialog/disclosure behavior
- Navigation interactions
- Timer rendering/state transitions
- Invoice-line-item interactions
- Bulk selection
- Components with nontrivial keyboard/accessibility behavior

Do not chase 100 percent component coverage.

### Critical Playwright flows

Cover selected workflows where browser integration matters:

- Authentication/invitation critical path
- Profile/2FA critical interactions
- Timer start/stop/reload recovery
- Allocation drag/persistence
- Kanban task movement
- Ticket operator bulk/reply path as justified
- Stripe payment test flow when environment credentials permit

PDF browser tests belong to [EPIC-012](./EPIC-012-document-generation.md), not this epic.

## Frontend Package Scripts

Use the following baseline, adjusted only if the final ESLint/formatter configuration requires a small syntax change:

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
    "check": "npm run typecheck && npm run lint && npm run format:check && npm run test:unit && npm run build"
  }
}
```

Wayfinder generation must also be part of the deterministic build/typecheck path, either through the Vite integration or an explicit script defined during Phase A.

## Quality Gates

### Local during implementation

At minimum for the affected area:

- Targeted Pest tests
- `npm run typecheck`
- Targeted Vitest tests
- ESLint on touched React/TypeScript files
- Production build before completing each phase

### CI blocking

- Deterministic Composer install
- Deterministic npm install
- PHP formatting/static checks already used by the repository
- Full Pest suite
- Wayfinder generation
- `npm run typecheck`
- `npm run lint`
- `npm run format:check`
- `npm run test:unit`
- `npm run build`
- Selected Playwright critical flows

Stripe browser tests may use a protected/separate CI job if credentials are intentionally unavailable in general CI.

## Known Prerequisite / Migration Defects

1. Two-factor recovery-code switch appears to depend on Alpine while Alpine is not installed. Fix before or at the start of Phase C.
2. Confirm whether both current auth layout implementations are still referenced before porting both.
3. Re-inventory inline JavaScript at the beginning of each phase because application code may continue changing while EPIC-011 is planned.

## Follow-Ups Explicitly Deferred

- Document/PDF generation: [EPIC-012](./EPIC-012-document-generation.md)
- Statement of Work domain/workflow implementation beyond a renderer prototype
- External/mobile JSON API and Sanctum if a real consumer is introduced
- Inertia SSR if an actual requirement emerges
- TanStack Query if a concrete data-refresh/caching requirement emerges
- TanStack Table if a specific operator grid justifies it
