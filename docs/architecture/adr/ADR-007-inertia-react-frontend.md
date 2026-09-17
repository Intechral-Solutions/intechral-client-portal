# ADR-007: Migrate the Frontend to React via Inertia.js

**Date:** 2026-09-10  
**Revised:** 2026-09-17  
**Status:** Accepted

## Context

The Intechral Client Portal is a Laravel 13 application whose frontend is currently rendered primarily through Blade with Tailwind CSS 4, Vite, and a mixture of bundled JavaScript and inline page behavior.

The current repository contains a broader JavaScript surface than the original planning inventory captured. In addition to `timer-overlay.js` and `allocation-chart.js`, inline behavior exists across the application shell, authentication layouts, time tracking, invoice authoring and payment, project forms and board interactions, milestone editing, task interactions, and operator ticket bulk actions.

The application already has mature Laravel-side concerns that must remain authoritative during the migration:

- Session-based authentication and Laravel Fortify flows
- Invitation-only registration and social-login continuation
- Spatie permission and policy enforcement
- Form Request validation and controller business logic
- Existing tenant and organization scoping
- Existing JSON endpoints used by highly interactive features such as timers and allocation editing
- Server-to-server endpoints such as Stripe webhooks

There is no current requirement for a separate browser API, mobile client, or external consumer that would justify splitting the application into an independent React SPA and Laravel API.

The migration should modernize the frontend while preserving the existing Laravel application boundary and allowing Blade and React pages to coexist safely until conversion is complete.

Document/PDF generation is a separate architectural concern. The repository currently has DOMPDF dependencies installed but no implemented PDF routes, controllers, jobs, templates, or downloads. PDF generation is therefore a new feature rather than a frontend migration task.

## Decision

Adopt **Inertia.js 3 with React 19 and TypeScript** as the application frontend, while Laravel remains authoritative for routing, sessions, validation, authorization, and business logic.

### Core stack

Use:

- Laravel 13
- `inertiajs/inertia-laravel`
- `@inertiajs/react`
- `@inertiajs/vite`
- React 19
- TypeScript
- Tailwind CSS 4
- `shadcn/ui`
- Laravel Wayfinder for typed route/action generation
- Vite for asset compilation
- Pest for backend/integration testing
- Vitest + React Testing Library for frontend unit/component testing
- Playwright for selected critical browser flows

Do **not** enable Inertia SSR. This is an authenticated client portal and SSR would add production runtime complexity without a current SEO or first-render requirement that justifies it.

### Migration model

Migrate incrementally, page by page and module by module.

Blade and Inertia pages may coexist throughout EPIC-011. Converting a page should preserve the existing route, middleware, authorization, validation, and domain behavior unless a separately approved backend change is required.

A converted route should normally replace a Blade response with `Inertia::render()` rather than introducing a duplicate API endpoint solely to support React.

### State and data loading

Use the simplest state mechanism appropriate to the problem:

- Local and ephemeral UI state: React built-in state and reducers.
- Server-owned application state: Inertia props, visits, partial reloads, deferred props, once props, merged props, prefetching, and `WhenVisible` where appropriate.
- Highly interactive widgets with an existing focused JSON contract, such as timers or contextual lookups: retain focused JSON endpoints and use `fetch`/Axios where that is simpler than an Inertia page visit.
- Optimistic interactions such as kanban movement: local optimistic state plus a Laravel mutation request.

**TanStack Query is not part of the foundation stack.** It may be added later only if a concrete feature requires capabilities such as independent background refresh, normalized cross-page caching, polling, or several independently refreshed server resources and Inertia does not solve the problem cleanly.

TanStack Router is not applicable because Laravel/Inertia owns routing. TanStack Table may be evaluated later for a specific high-complexity data-grid use case rather than adopted globally in advance.

### Route generation

Use **Laravel Wayfinder** for typed routes and controller actions in React.

Wayfinder is pre-1.0, so its version must be pinned during the migration and route generation/type checking must be exercised in CI. Generated output should be produced during development/build rather than treated as hand-maintained source.

If Wayfinder creates a material compatibility or maintenance problem during Phase A, Ziggy is the fallback. The application should not carry both systems without a demonstrated need.

### Application shell and navigation

Create a persistent React `AppLayout` for authenticated Inertia pages and a separate React auth layout for guest/authentication flows.

`AppLayout` must preserve the behavioral responsibilities of the current Blade shell, including:

- Permission-aware navigation
- Active route state
- Desktop and mobile navigation
- User menu
- POST logout behavior
- Theme persistence and pre-paint restoration
- Flash/status messaging
- Active timer presentation
- Main page content
- Footer

During Blade/Inertia coexistence, introduce a server-side navigation builder/model that produces serializable navigation groups. Both Blade and React should consume the same navigation model so permission visibility, route destinations, and menu structure cannot drift.

Navigation visibility is presentation only. Authorization continues to be enforced by Laravel middleware, policies, permissions, and domain services.

### Timer migration

Do not create a standalone React timer island on legacy Blade pages.

During coexistence:

- Legacy Blade pages keep the existing Blade/JavaScript timer implementation.
- Inertia pages render a React timer inside `AppLayout`.
- Both implementations consume the same Laravel timer endpoints and server-authoritative timer records.
- Moving between Blade and Inertia causes a normal full-page transition and authoritative rehydration.
- Inertia-to-Inertia navigation preserves the React layout and timer state.

This avoids duplicate React roots/providers while retaining the existing recovery model.

### Authentication and invitations

All user-facing Fortify and invitation views are ultimately in scope for migration to React, including:

- Login
- Forgot password
- Reset password
- Password confirmation
- Two-factor challenge
- Invitation registration
- Invalid/expired invitation states
- Profile, password, two-factor, session, and linked-account interactions

Existing Fortify and invitation business logic should remain in Laravel rather than being replaced by starter-kit auth logic.

The currently identified two-factor recovery-code UI defect caused by Alpine directives without Alpine being installed should be fixed before or at the beginning of the auth migration rather than preserved as migration parity.

### Design migration policy

**Behavioral parity is required; pixel parity is not.**

Business rules, permissions, validation, workflows, data integrity, and accessibility must remain correct. Migrated pages may be visually modernized using the new component and design system instead of reproducing Blade screens pixel-for-pixel.

Phase A must establish shared design conventions for:

- Semantic colors and dark mode
- Typography and spacing
- Buttons and destructive actions
- Forms, validation, help text, and loading/disabled states
- Tables, filters, and pagination
- Empty/loading/deferred/error states
- Dialogs and drawers
- Flash/toast behavior
- Responsive navigation
- Accessibility and keyboard interaction
- Date, time, and currency formatting
- Page headers and breadcrumbs
- Operational screen density

Existing useful CSS tokens should be mapped into semantic shadcn/Tailwind variables rather than discarded automatically.

### Blade end state

The target is to migrate all application pages to Inertia/React, including auth and invitation pages.

Blade may intentionally remain for infrastructure-level views where React adds no value, such as framework/error responses. Intentional Blade exceptions must be documented at decommission time.

### PDF/document generation

PDF generation is explicitly **out of scope for this ADR and [EPIC-011](../../epics/EPIC-011-react-frontend-migration.md)**.

The renderer has not been selected. [EPIC-012](../../epics/EPIC-012-document-generation.md) will compare Laravel-native and Node-based approaches and may prototype representative invoice and Statement of Work documents before a renderer is chosen.

The React browser frontend must not dictate the PDF technology choice.

## Rationale

- Inertia provides a modern React application experience while preserving Laravel's routing, session, validation, and authorization model.
- A separate SPA/API would add authentication, CORS, API versioning, duplicated contracts, and synchronization work without a current non-browser consumer.
- Inertia 3 provides enough server-state and data-loading capability for the current application that a second server-state framework is unnecessary by default.
- Wayfinder aligns route generation with the current Laravel React architecture and provides compile-time value for the application's nested resources and controller actions.
- Incremental conversion allows the portal to remain usable while modules move to React.
- Avoiding SSR preserves a simpler cPanel-compatible deployment model.
- A shared navigation model prevents Blade and React implementations from drifting during coexistence.
- Separate Blade and React timer renderers preserve the existing authoritative backend contract without introducing a temporary multi-root React architecture.
- Separating document generation avoids coupling a new infrastructure decision to an otherwise browser-focused migration.

## Consequences

- Two rendering paths will exist temporarily until EPIC-011 is complete.
- Phase A becomes a substantive architecture phase, not merely dependency installation.
- Shared Inertia props must be intentionally serialized and kept minimal to avoid exposing unnecessary user/domain data.
- Existing HTML-oriented Pest assertions will need to move to Inertia page/component/prop assertions as routes convert.
- The frontend toolchain gains TypeScript, linting, formatting, frontend tests, and production-build checks.
- Wayfinder adds a generated typed-route step and a pre-1.0 dependency that must be pinned and validated.
- Some JavaScript will temporarily have two implementations, especially the timer, until the final Blade page in that area is retired.
- The migration may improve visual structure while preserving behavioral contracts, so screenshots are not the primary parity criterion.

## Alternatives Rejected or Deferred

### Separate React SPA + Sanctum API

Rejected for now. No current external or mobile consumer requires a standalone API boundary. A future API can coexist with Inertia if a real consumer is introduced.

### Livewire

Rejected because the migration goal explicitly includes React, TypeScript, shadcn/ui, and the React ecosystem.

### Inertia SSR

Rejected for now. The authenticated portal does not currently benefit enough from SSR to justify the production Node runtime and operational complexity.

### TanStack Query as a foundation dependency

Deferred. Add only when a concrete feature demonstrates a server-state requirement that Inertia and focused Laravel endpoints do not handle cleanly.

### Standalone React timer island on Blade pages

Rejected. It adds temporary React roots/providers without preserving a React tree across Blade/Inertia full-document transitions.

### Ziggy

Deferred as the route-generation fallback if Wayfinder proves unsuitable during implementation.

### PDF renderer selection

Deferred to [EPIC-012](../../epics/EPIC-012-document-generation.md) and its host/prototype spike.
