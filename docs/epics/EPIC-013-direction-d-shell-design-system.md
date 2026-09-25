# EPIC-013: Direction D Application Shell and Design System Foundation

**Status:** Planned
**Class:** UX foundation (Product Roadmap [NEXT — Product/UX foundation](../product/product-roadmap.md#next--productux-foundation): *New application shell* + *Design system*)
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md) (canonical, approved 2026-09-25, revision 2)
**Product direction:** [Platform Product & UX Direction](../product/platform-product-ux-direction.md) · [Information Architecture](../product/information-architecture.md) · [Product Roadmap](../product/product-roadmap.md)
**Decision record:** [ADR-007: Inertia/React Frontend](../architecture/adr/ADR-007-inertia-react-frontend.md) · [ADR-002: Tailwind v4](../architecture/adr/ADR-002-tailwind-v4.md) · [ADR-003: Spatie Permission](../architecture/adr/ADR-003-spatie-permission.md)
**Prerequisites:** [EPIC-011A](./EPIC-011A-react-foundation-coexistence.md) (Implemented), [EPIC-011B](./EPIC-011B-dashboard-profile.md) (Implemented), [EPIC-011C](./EPIC-011C-authentication-invitations.md) (Verified), [EPIC-011D](./EPIC-011D-time-tracking-timer.md) (Verified), [EPIC-011E](./EPIC-011E-projects-kanban.md) (Verified), [EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md) (Verified)
**Brand prerequisite:** Satisfied — canonical owner-supplied SVGs are committed at `src/resources/images/brand/` (path reconciled in WP0, gate G2)
**Planning baseline:** `main` @ `6ea4135`, working tree clean, verified 2026-09-25
**Amendments:** [Amendment 1 (2026-09-25)](#amendment-1-wp0-results-2026-09-25): WP0 results — G1 font delivery locked with measured payloads, G2 brand assets moved and consumption proven, G3 confirmed across seven actor profiles, S1 confirmed, S2 overturned in part, token and navigation baselines, fourteen plan corrections, WP1a handoff · [Amendment 2 (2026-09-25)](#amendment-2-wp1a-results-2026-09-25): WP1a results — legacy-namespace rename landed (13 utilities, 59+ call sites), the F1 census corrected again (a live `hover:bg-surface` collision-in-waiting found and neutralized, undercounted by the Amendment 1 methodology), all ten React `text-primary` sites individually and deliberately resolved, `./dev check` green · [Amendment 3 (2026-09-25)](#amendment-3-wp1b-results-2026-09-25): WP1b results — F2 census reproduced exactly (50+1 sites), six compatibility variables defined, `--surface-elevated → var(--bg-surface)` correction verified in Chromium, `--accent-success → var(--success)` verified, a second (previously undocumented) contrast debt found on the `--surface-accent`/`--accent` dark-mode pairing alongside the known `--surface-muted`/`--text-muted` one, both handed forward undisguised, `./dev check` green · [Amendment 4 (2026-09-25)](#amendment-4-wp1c-results-2026-09-25): WP1c results — the Direction D semantic layer lands (35 colour + 2 shadow + 6 motion tokens, light and dark, `--ds-*` custom properties exposed through `@theme inline`); a custom-property collision (F3) and a Tailwind-token collision (F4) found and resolved inside the slice; `ring → focus` and `destructive → danger` remapped on measured evidence, every other alias held, `primary` held; the ten-screen matrix plus CMS run in both themes on seeded fixtures with no regression; full gate green on the WP1c tree (the literal `./dev check` blocked only by two pre-existing environment conditions, A4.17)

---

## Contents

1. [Goal](#1-goal)
2. [Relationship to the Product Roadmap](#2-relationship-to-the-product-roadmap)
3. [Direction D source-of-truth references](#3-direction-d-source-of-truth-references)
4. [Current implementation inventory](#4-current-implementation-inventory)
5. [Non-goals](#5-non-goals)
6. [Locked design decisions](#6-locked-design-decisions)
7. [Current → target shell architecture](#7-current--target-shell-architecture)
8. [Theme and token migration strategy](#8-theme-and-token-migration-strategy)
9. [Typography and font delivery](#9-typography-and-font-delivery)
10. [Brand asset consumption](#10-brand-asset-consumption)
11. [Navigation information architecture](#11-navigation-information-architecture)
12. [Navigation server contract](#12-navigation-server-contract)
13. [React operator shell](#13-react-operator-shell)
14. [Blade parity](#14-blade-parity)
15. [Drawer state and persistence](#15-drawer-state-and-persistence)
16. [Responsive behaviour](#16-responsive-behaviour)
17. [Account menu redesign](#17-account-menu-redesign)
18. [Global timer foundation scope](#18-global-timer-foundation-scope)
19. [Page frames and content widths](#19-page-frames-and-content-widths)
20. [Shared primitive strategy](#20-shared-primitive-strategy)
21. [Dashboard → Home adoption](#21-dashboard--home-adoption)
22. [Accessibility and focus behaviour](#22-accessibility-and-focus-behaviour)
23. [Audience, presentation, and the shell seam](#23-audience-presentation-and-the-shell-seam)
24. [Migration compatibility strategy](#24-migration-compatibility-strategy)
25. [Test strategy](#25-test-strategy)
26. [Performance and bundle considerations](#26-performance-and-bundle-considerations)
27. [Security and privacy review](#27-security-and-privacy-review)
28. [Work packages](#28-work-packages)
29. [Exit criteria](#29-exit-criteria)
30. [CI handoff](#30-ci-handoff)
31. [Deferred follow-on work](#31-deferred-follow-on-work)
32. [Risks and rollback](#32-risks-and-rollback)
33. [Open questions and decision gates](#33-open-questions-and-decision-gates)
34. [Implementation model guidance](#34-implementation-model-guidance)

- [Amendment 1: WP0 Results (2026-09-25)](#amendment-1-wp0-results-2026-09-25)
- [Amendment 2: WP1a Results (2026-09-25)](#amendment-2-wp1a-results-2026-09-25)
- [Amendment 3: WP1b Results (2026-09-25)](#amendment-3-wp1b-results-2026-09-25)
- [Amendment 4: WP1c Results (2026-09-25)](#amendment-4-wp1c-results-2026-09-25)

---

## 1. Goal

Turn the approved [Direction D design contract](../design/direction-d-design-system.md) into a working application shell and design-system foundation, **without destabilising the running platform**.

At the end of this epic:

- A Direction D **semantic token layer** exists in `src/resources/css/app.css`, with light and dark themes on the existing `data-theme` no-flash contract, and with compatibility aliases that keep every unmigrated React and Blade screen readable and usable.
- The **operator shell** — the **Operational presentation** (64px rail, optional 248px contextual drawer, 48px utility bar, full-viewport canvas) — is the React shell for every authenticated Inertia page. It is the **only** presentation built here.
- **Blade renders the same rail, utility bar and token system** from the same server navigation data, so the product does not look like two unrelated applications during coexistence.
- `NavigationBuilder` produces a **workspace-shaped, capability-filtered, presentation-neutral navigation contract** consumed by both renderers, with presentation-specific hints kept separate from navigation content (**L17**, [§12](#12-navigation-server-contract)).
- `AppShell` is the **single shell-resolution boundary**, so a second presentation family can be added later without touching pages ([§23](#23-audience-presentation-and-the-shell-seam)). No second shell and no shell preference is built (**L18**).
- The global timer is a **TimerPill + TimerTray** in the utility bar (Foundation scope only), preserving the EPIC-011D `TimerProvider` reconciliation model unchanged.
- **Page-frame primitives** exist, and **Dashboard → Home** is the first surface intentionally adopting Direction D.

The broader product work — Tasks overhaul, Projects expansion, Timer UX, Helpdesk, Directory, Finance, customer product — follows in its own epics.

## 2. Relationship to the Product Roadmap

This epic delivers two adjacent [Product Roadmap](../product/product-roadmap.md) NEXT items as one foundation: **New application shell** and **Design system**. They are combined because the shell cannot be built without the token and primitive layer, and the token layer has no honest consumer without the shell.

**Sequence position.**

| Bucket | Item | Status |
|---|---|---|
| NOW | Critical Helpdesk hardening ([EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md)) | Complete (Verified) |
| NOW | Product/UX rebase; Claude Design brief and exploration | Complete (Direction D approved) |
| **NEXT** | **Direction D shell + design system (this epic)** | **Planned** |
| NEXT | Lightweight CI baseline | Follows this epic ([§30](#30-ci-handoff)) |
| NEXT | Tasks overhaul · Timer UX · Projects UX expansion | After CI |
| LATER | Helpdesk MVP · Directory · Finance · Advanced Projects · Knowledge/CMS | Unchanged |

**One documented supersession.** The roadmap's *New application shell* entry carries a **provisional** coexistence note: "remaining Blade pages keep the legacy Blade layout … and do not receive new Blade shell work." The [Direction D contract](../design/direction-d-design-system.md) is newer (2026-09-25) and **requires Blade parity** (§19 step 5: "so the two renderers don't diverge"). Direction D governs. The roadmap note is superseded for shell chrome only; it still holds for Blade *page bodies*, which are not redesigned here. See [§14](#14-blade-parity).

**One ADR clarification, not a change.** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md) says "Do not create a standalone React timer island on legacy Blade pages." That still stands. Direction D §19 step 6 replacing "the Blade tracker's global strip" is implemented as a **restyled vanilla-JS pill and tray in `timer-overlay.js`**, not as a React island. See [§18](#18-global-timer-foundation-scope).

## 3. Direction D source-of-truth references

| Topic | Canonical source |
|---|---|
| Everything | [`docs/design/direction-d-design-system.md`](../design/direction-d-design-system.md) |
| Colour tokens and shadows | §2.2 |
| Semantic usage rules (accent vs live, cyan discipline, live-text, warning pair) | §2.3 |
| Migration compatibility (hard constraint) | §2.4 |
| Typography families, scale, rules | §3 |
| Space, radius, rules, elevation | §4 |
| Shell dimensions, width classes, drawer defaults/persistence/states | §5 |
| Operator shell anatomy | §6 |
| Customer shell anatomy (later epic) | §7 |
| Navigation and selection rules | §8 |
| Content widths and inspectors | §9 |
| Status and priority semantics | §10 |
| Staged vs continuous progress | §11 |
| Global timer, including the Foundation/NEXT scope table | §12, §12.0 |
| Account menu and avatars | §13, §13.1 |
| Focus, keyboard, primary-navigation semantics | §14 |
| Loading/empty/error/disabled/optimistic | §15 |
| Motion | §16 |
| Brand motifs and canonical assets | §17, §17.1 |
| Component inventory | §18 |
| Implementation order | §19 |
| Approved open questions | §20 |

`docs/design/direction-d-tokens.css` is a **reference artefact from the Claude Design mockups**. Its class names (`.rail`, `.st`, `.bar`) are mockup scaffolding and must not become production architecture. It may be read for exact hex values and geometry; production tokens are implemented from the semantic names in §2.2.

Mockup rows D1–D9 illustrate the direction. They contain `NEXT` and dashed `FUTURE` markers; those features must not ship, and neither must the markers themselves.

## 4. Current implementation inventory

Verified by reading the live repository at `6ea4135` on 2026-09-25.

### 4.1 Theme and CSS

`src/resources/css/app.css` (227 lines) — Tailwind v4, `@import 'tailwindcss'` with explicit `@source` globs covering Blade, JS, TS and TSX.

Four layers exist today:

1. **`@theme`** — `--font-sans` (Inter), `--font-mono` (JetBrains Mono), an indigo `--color-brand-50…950` scale.
2. **`@theme inline`** — shadcn aliases mapped to bare custom properties (`--color-background: var(--background)`, and 19 more).
3. **Theme blocks** — `:root, [data-theme="light"]` and `[data-theme="dark"]` define the shadcn aliases *plus* a raw family: `--bg-*`, `--surface-*`, `--border-*`, `--text-*`, `--accent*`, `--danger/success/warning/info`, `--shadow-*`.
4. **`@layer utilities`** — hand-written semantic utilities: `.bg-base`, `.bg-surface`, `.bg-elevated`, `.border-subtle`, `.border-base`, `.text-primary`, `.text-secondary`, `.text-muted`, `.text-inverse`, `.btn-accent`, `.shadow-theme-{sm,md,lg}`.

**Consumer census** (live counts, `src/resources/`):

| Consumer family | Blade uses | React uses |
|---|---|---|
| `.text-primary` / `.text-secondary` / `.text-muted` utilities | 203 / 200 / 127 | 0 (React uses shadcn `text-foreground` / `text-muted-foreground`) |
| `.border-base` / `.border-subtle` | 182 / 18 | 0 |
| `.bg-surface` / `.bg-base` / `.bg-elevated` | 37 / 3 / 1 | 0 |
| `var(--text-primary)` / `var(--text-secondary)` inline | 189 / 191 | 2 / 2 |
| `var(--border-base)` inline | 181 | 1 |
| `var(--text-muted)` inline | 126 | 1 |
| Status raws (`--surface-*`, `--border-*`, `--text-*` success/danger/warning/info) | ~130 | ~50 |
| shadcn aliases (`bg-background`, `text-foreground`, `border-border`, `ring-ring`, …) | 0 | pervasive |

**Two findings that shape the whole token strategy.**

- **F1 — utility-name collision.** Direction D §2.1 wants Tailwind utilities that read `bg-surface`, `text-muted`, `border-rule`. **`bg-surface`, `text-muted`, `text-primary`, `text-secondary` already exist** as hand-written utilities bound to legacy greys, with 567 Blade call sites between them. Registering Direction D tokens named `surface` and `muted` through `@theme inline` would generate utilities with the same class names, and the winner would be decided by CSS source order rather than intent. This is a naming conflict, not a value conflict, and it must be resolved before any token lands.
- **F2 — five undefined variables already in use.** Blade references variables that `app.css` never defines:

  | Variable | Definitions | Uses |
  |---|---|---|
  | `--surface-base` | 0 | 24 |
  | `--surface-muted` | 0 | 10 |
  | `--surface-elevated` | 0 | 9 |
  | `--border-muted` | 0 | 4 |
  | `--surface-accent` | 0 | 3 |

  Today each produces an invalid declaration that the browser drops, so the element inherits its parent's background or border and happens to look acceptable. **If the token layer changes the ancestor backgrounds, these 50 sites can become unreadable or lose their boundaries.** Direction D §2.4.6 anticipates exactly this.

**No-flash theme contract.** An identical inline IIFE is duplicated in **two** root views — `resources/views/app.blade.php` (Inertia root, `$rootView = 'app'`) and `resources/views/layouts/app.blade.php` (Blade root). It reads `localStorage['theme']`, falls back to `prefers-color-scheme`, and sets `data-theme` on `<html>` before paint. There is **no cookie and no server involvement** in the theme. `useAppearance` (React) and an inline script in `partials/nav.blade.php` (Blade) both write `localStorage['theme']` and flip the attribute.

### 4.2 Fonts

Both root views `preconnect` to `https://fonts.bunny.net` and load `inter:300,400,500,600,700` and `jetbrains-mono:400,500` from that external host. No font files are self-hosted, and there is no font package in `package.json`.

### 4.3 React shell

`src/resources/js/layouts/app-layout.tsx` — a 200-line sticky-header layout:

- `TimerProvider` wraps everything, `enabled` on `time.log`.
- Sticky `<header>`, `max-w-7xl` inner container, brand text link ("Intechral Portal") to `dashboard`.
- Desktop `<nav aria-label="Primary navigation">` rendering the `primary` group.
- Theme toggle `<Button>` (sun/moon) calling `useAppearance().toggleAppearance`.
- Radix `DropdownMenu` account menu: name + email header, **Profile**, then a **"Manage"** section listing the whole `management` navigation group, then **Sign out** (`router.post(logout)`).
- Radix `Dialog` mobile nav sheet, `<nav aria-label="Mobile navigation">`.
- `<RunningTimerBar />`, `<FlashRegion />`, `<main id="main-content">`, `<footer>`.

**Root setup.** `resources/js/app.tsx`: `createInertiaApp` with `resolvePageComponent` + `import.meta.glob` (per-page code splitting), `progress.color: '#4f46e5'` (a hard-coded legacy indigo), **`inertia({ ssr: false })`** in `vite.config.ts`.

**Persistent layout.** All 12 authenticated pages use the Inertia static-property form: `Page.layout = (page) => <AppLayout>{page}</AppLayout>`. So `AppLayout` (and `TimerProvider`) persists across Inertia visits and remounts only on full-document transitions. The 12 assignment lines are the entire coupling between pages and the shell — which is why the shell seam ([§23](#23-audience-presentation-and-the-shell-seam)) is cheap.

**Gaps found.**

- **No skip link exists anywhere.** Both shells render `<main id="main-content">` but nothing targets it. Direction D §14.1 requires one in both shells.
- `NavigationLink` sets no `aria-current`. Active state is a class only. Direction D §8 requires `aria-current="page"`.
- No focus management on Inertia visits (§14.2).
- No breadcrumb, no landmarks beyond `nav`/`main`, no `<header>` landmark on the Blade side.

### 4.4 Navigation backend

`app/Shared/Navigation/NavigationBuilder.php` — one `build(Request): array` returning **two flat groups**:

- `primary`: Tickets (`tickets.view`, document) · Projects (`projects.view`, inertia) · Tasks (ungated, inertia) · Time (`time.log`, inertia) · Billing (branching: `billing.manage` → operator invoices, else `billing.view` → client invoices; document) · CRM (`crm.manage`, document) · Pages (`cms.view`, document).
- `management` (label "Manage"): Ticket Queue (`tickets.assign`) · Time Reports (`time.view_all`, inertia) · Organizations (`crm.manage`) · CMS Pages (`cms.edit`) · Users (`users.view`) · Roles (`roles.view`).

Item shape: `{key, label, href, method, visit, activePatterns, isActive, children}`. `isActive` is computed server-side via `$request->routeIs(...$activePatterns)`. `children` is always `[]` — the field exists but is unused.

**Consumers.** React via the shared Inertia prop (`HandleInertiaRequests::share`, lazily evaluated closure). Blade via a direct `app(NavigationBuilder::class)->build(request())` call inside `layouts/partials/nav.blade.php` — the view instantiates the builder itself.

**Tests.** `tests/Feature/NavigationBuilderTest.php` — three cases: `user`-role primary membership and visit modes, operator management group and billing destination branch, and empty output for guests.

### 4.5 Blade shell

`resources/views/layouts/app.blade.php` — `<html data-theme="light">`, bunny.net fonts, `@vite(['resources/css/app.css', 'resources/js/app.js'])`, the no-flash IIFE, then `@include('layouts.partials.nav')`, `@include('layouts.partials.timer-overlay')`, `<main id="main-content">@yield('content')</main>`, `@include('layouts.partials.footer')`.

`partials/nav.blade.php` (229 lines) — sticky bar, `max-w-7xl`, brand text link, desktop primary links, theme toggle with two inline SVGs, a hand-rolled user dropdown (`role="menu"`, click-outside and Escape handlers), the **"Manage"** section, mobile hamburger + panel, and a ~90-line vanilla-JS IIFE at the bottom. No Alpine. Styling is a mix of the legacy utilities and inline `style="…var(--…)"`.

**Remaining Blade modules** (33 controller `view()` calls, 45 `.blade.php` files):

| Module | Views | Gate |
|---|---|---|
| Tickets (customer) | `tickets.index/show/create` + `_status_badge`, `_priority_badge` | `tickets.view` / `tickets.create` |
| Tickets (operator) | `operator.tickets.index/show/reports` | `tickets.assign` |
| Billing (operator) | `billing.invoices.index/show/create/edit` + `_form` | `billing.manage` |
| Billing (client) | `billing.client.index/show`, `billing.payment.show` | auth |
| CRM | `crm.companies.*` (5), `crm.contacts.*` (5) | `crm.manage` |
| Organizations | `organizations.index/show` | `crm.manage` |
| CMS (operator) | `operator.cms.index/create/edit` + `_form` | `cms.edit` |
| CMS (viewer) | `cms.index/show` | `cms.view` |
| Admin | `admin.users.index/show`, `admin.roles.index/create/edit` | `users.view` / `roles.view` |
| Infrastructure | `welcome`, `errors/403`, `components/time-tracker` | — |

### 4.6 Timer

`components/time/timer-provider.tsx` (~290 lines) — the hardened EPIC-011D model. `useReducer` state `{timers, status, error, clockOffsetMs, starting, stopping[], updating[]}`; two sequence refs (`refreshSeq` for "only the newest read publishes", `mutationSeq` for "a read that began before a mutation is re-read, not applied"); a server/client clock-offset estimate from `server_now`; `401/419` triggers a reload. Exposes `refreshTimers`, `startTimer`, `stopTimer`, `updateDescription`. Every failure path re-reads canonical state.

`components/time/running-timer-bar.tsx` — full-width strip under the header. One `setInterval(1000)` lives **inside this component** and updates only its own subtree (the EPIC-011D localisation lesson). Per-timer row: clock glyph, mono elapsed, `TimerContextLink`, inline-editable description, Stop, inline error. Plus a separate error-with-Retry banner when `status === 'error'` and no timers.

**Endpoints** (`routes/web.php`, all under `can:time.log`): `GET /time/timers/active`, `POST /time/timer/start`, `POST /time/timer/{entry}/stop`, `PATCH /time/timer/{entry}/description`, `GET /time/context-options`. **No pause, no stop-all.** Multiple concurrent timers are supported.

**Blade side.** `resources/js/timer-overlay.js` (273 lines), imported by `resources/js/app.js` on `DOMContentLoaded`. It fetches the same `/time/timers/active`, renders tiles into `partials/timer-overlay.blade.php`, ticks every second client-side, and dispatches/listens for `timerStarted` / `timerStopped` custom events. It carries an eight-entry decorative colour `PALETTE` including three hard-coded hexes (`#8b5cf6`, `#ec4899`, `#14b8a6`, `#f97316`) — non-tokenised colour that Direction D does not sanction. `resources/views/components/time-tracker.blade.php` (193 lines) is the embedded Blade tracker used on ticket pages.

### 4.7 Shared UI primitives

`resources/js/components/ui/`: `button` (cva; variants default/secondary/outline/ghost/destructive; sizes default/icon/sm), `input`, `textarea`, `label`, `native-select`, `dropdown-menu` (Radix), `dialog-shell` (Radix), `confirmation-dialog`, `form-dialog`, `alert`, `badge` (cva; neutral/success/warning/danger/info, status variants bound to raw `var(--*)` values), `progress` (`role="progressbar"`, `aria-valuetext`).

Outside `ui/`: `page-header.tsx`, `section-panel.tsx`, `pagination.tsx`, `navigation/navigation-link.tsx`, `feedback/flash-region.tsx`, `forms/form-field-error.tsx`, plus domain folders `projects/` (30 files), `tasks/` (7), `time/` (13), `auth/` (1).

**Installed Radix packages:** `react-dialog`, `react-dropdown-menu`, `react-slot` only. Direction D needs **Popover** (timer tray), **Tabs** (page tabs), **Tooltip** (disabled reasons) — three new dependencies.

### 4.8 Dashboard

`DashboardController` (single `__invoke`) → `Inertia::render('dashboard/index')` with exactly four props:

- `metrics[]` — up to four `{key,label,value,supportingText,href,visit}`: open tickets (all vs own by `tickets.assign`), active projects (`Project::visibleTo($user)`), time this month + unbilled hours, outstanding invoices (all vs own by `billing.manage`).
- `recentTickets[]` — up to 6 `{id,subject,status,statusLabel,requesterName,createdAtHuman,href,visit}`.
- `quickActions[]` — New Ticket, Log Time, My Projects, My Profile.
- `crmSummary` — `{companies,contacts,orgs,href,visit}` or `null`, gated on `crm.manage`.

The page renders a `max-w-7xl` container, a `PageHeader`, a four-up metric card grid, a recent-tickets list, and an aside with quick actions and a CRM `<dl>`.

### 4.9 Build and test infrastructure

- `vite.config.ts` — laravel plugin with **three inputs** (`app.css`, `app.js` for Blade, `app.tsx` for Inertia), `inertia({ssr:false})`, react, tailwindcss, wayfinder (`formVariants`). **No `build.rollupOptions.manualChunks`** — chunking is Rollup's default plus the per-page dynamic glob.
- Heavy deps: `chart.js` + `chartjs-plugin-dragdata` (allocation chart only), `@dnd-kit/*` three packages (board only).
- `npm run check` = wayfinder:generate → typecheck → lint (`--max-warnings=0`) → format:check → vitest → build.
- `./dev check` = CLI self-tests → `git diff --check` → Pint `--test` → `npm run check` → Pest (MariaDB).
- Pest: `tests/Feature/` (Admin, Auth, Billing, Cms, Crm, Projects, Tickets, Time + `NavigationBuilderTest`, `InertiaFoundationTest`, `DashboardInertiaTest`, `ProfileInertiaTest`, `ApplicationTest`), `tests/Unit/` (Configuration, Permissions, Support).
- Vitest: colocated `*.test.tsx`, jsdom, `resources/js/test/setup.ts`.
- Playwright: `tests/Browser/`, **chromium only**, `fullyParallel: false`, `baseURL` default `http://localhost:4242`. 9 specs, including `inertia-coexistence.spec.ts` which asserts theme persistence across a React→Blade→React walk, mobile nav at 390px, and the account-menu → Time Reports path.

## 5. Non-goals

This epic does **not** do any of the following. Each belongs to a later roadmap item.

| Not in scope | Belongs to |
|---|---|
| Tasks product overhaul (My/All Tasks, Complete/Reopen, filters, bulk) | Tasks overhaul |
| Projects product expansion (Planning/Execution/Monitoring, list view, health) | Projects UX expansion |
| Helpdesk redesign or Ticket renderer migration | Helpdesk MVP |
| Directory domain migration (Person entity, Organization consolidation, Relationships) | Directory |
| Finance features (Retainers, Rates, Budgets, Billing Account) | Finance |
| Knowledge, Incidents, approvals, notifications, global search | LATER / FUTURE |
| **Focused presentation / customer shell implementation** (`CustomerShell`, top bar, customer pages) | Customer product work. This epic delivers the *seam* only ([§23](#23-audience-presentation-and-the-shell-seam)) |
| **User-selectable shell presentation** — any persisted preference, profile setting, admin shell assignment, role or organization shell policy, or switching control (L18) | Shell presentation parity / unification (Future) ([§31](#31-deferred-follow-on-work)) |
| CI pipeline files or GitHub Actions | Lightweight CI baseline ([§30](#30-ci-handoff)) |
| Deployment, release engineering, staging | FUTURE — Deployment/release engineering |
| Full Timer product redesign; Stop All; long-running warning; global quick-start search | Timer UX improvement ([§18](#18-global-timer-foundation-scope)) |
| Permissions model rewrite, permission renames, retiring `time.view_own` | Directory / future permission work |
| Rewriting every React page onto new widths or primitives | Each product epic, as it adopts |
| Inspectors, command palette, `J/K/X/E/T` shortcuts, density preference | NEXT / Future per Direction D |
| Pixel-perfect reproduction of unmigrated screens | Explicitly accepted as transitional (§2.4.4) |

**Also not in scope:** proposing a Direction E. Direction D is approved and settled.

## 6. Locked design decisions

Carried from the design contract. Reopen only if live implementation evidence exposes a genuine technical contradiction, and then by amendment.

| # | Decision |
|---|---|
| L1 | Operator shell = 64px rail + optional 248px contextual drawer + 48px utility bar + full-viewport canvas |
| L2 | The drawer is **not** universally pinned. Defaults are per workspace; user choice is remembered per `(user, workspace)` where applicable |
| L3 | Customer shell = 60px top bar, no rail, no drawer, shared tokens/components — **later epic**; the foundation only prepares for it |
| **L17** | **Authorization/audience and shell presentation are separate concerns.** See [§23.1](#231-l17--audience-and-presentation-are-separate-concerns). Capabilities and relationships decide *which workspaces exist, which routes/actions/data are reachable, and which contextual entries appear*. Shell **presentation** decides only *how that already-authorized model is projected*. The two presentation families are **Operational** (rail + contextual panel, for frequent/complex work) and **Focused** (top navigation with context projected through menus, tabs and view selectors, for lighter participation). The initial pairing — internal/operator → Operational, customer → Focused — is a **default, not authorization semantics**. Rail ≠ operator permission; top bar ≠ customer permission; shell choice is never an authorization decision |
| **L18** | **No persisted shell preference in this epic.** No profile setting, admin assignment, role configuration, organization policy or switching control. The foundation implements the **Operational presentation only** and preserves the seam. Speculative preference infrastructure is not built ([§31](#31-deferred-follow-on-work)) |
| L4 | Type families are IBM Plex Sans (UI), IBM Plex Mono (IDs/time/money/counts), Newsreader (entity names and customer display headings only). Hosting is open ([§9](#9-typography-and-font-delivery)) |
| L5 | Light = warm paper `#F6F5F1`, white surfaces, ink `#1A1B1E`, deep-teal accent `#0B6A73`. Dark = teal-black `#0D1416` canvas / `#142023` surfaces, softened cyan `#7ADDE4` links. Both required |
| L6 | A third "System" theme is NEXT and is not pulled into this foundation |
| L7 | Brand cyan `#19E7F2` is reserved for live/current state (mainly dark). **Primary buttons are ink**, never a cyan fill |
| L8 | Strata motifs are rare and structural (entity headers only). Stepped geometry is for real staged lifecycle progress; arbitrary quantitative progress stays continuous |
| L9 | Identity avatars are circular at every size, in both shells. The operator rail account **control** is a 40×40 rounded-square tile (radius 6) containing a 28px circular avatar. The customer top-bar avatar is itself the trigger. Avatar shape never encodes role |
| L10 | Structure over boxes. Cards only for bounded objects, decisions, self-contained summaries, and interactions. No KPI-tile cards, no cards wrapping lists/tables/sections |
| L11 | Primary navigation (rail, drawer, breadcrumb, customer top nav) uses `<nav>` landmarks with ordinary `<a href>` links in normal Tab order. **No roving tabindex, no mandatory arrow-key navigation.** Keep skip link, `:focus-visible`, landmarks, `aria-current`, overlay focus return, and `Esc` |
| L12 | Laravel stays authoritative. Optimistic *presentation* is allowed only under all six §15.5 conditions. The hardened board, checklist and timer-sequencing patterns are preserved as-is |
| L13 | Token migration is **add, don't swap**. Legacy aliases stay defined until their last consumer migrates. No intentional regressions to existing screens (§2.4) |
| L14 | Navigation truth stays server-side in `NavigationBuilder`. Navigation visibility is presentation, never authorization |
| L15 | Account menu is personal-only. Administration lives under System |
| L16 | Canonical brand SVGs are authoritative source artwork. Never redraw, trace, approximate or regenerate the mark |

## 7. Current → target shell architecture

### 7.1 React

```
CURRENT                                  TARGET
┌───────────────────────────────────┐    ┌────┬───────┬──────────────────────────┐
│ sticky header (h-16, max-w-7xl)   │    │Rail│Drawer │ Utility bar 48           │
│  brand · primary nav · theme ·    │    │ 64 │ 248   ├──────────────────────────┤
│  account ▾ (incl. "Manage") · ☰   │    │    │(opt.) │ Page header              │
├───────────────────────────────────┤    │logo│header │ ─ rule / strata ─        │
│ RunningTimerBar (full-width strip)│    │ ⌂  │views  │                          │
├───────────────────────────────────┤    │ ▭  │queues │ Content:                 │
│ FlashRegion                       │    │ ✓  │saved  │  canvas | grid | reading │
├───────────────────────────────────┤    │ …  │       │  (+ optional inspector)  │
│ main#main-content                 │    │acct│       │                          │
│   (page supplies max-w-* itself)  │    └────┴───────┴──────────────────────────┘
├───────────────────────────────────┤    Full viewport. No global footer.
│ footer (copyright)                │    Timer moves into the utility bar as a pill.
└───────────────────────────────────┘    FlashRegion stays, repositioned under the bar.
```

| Current element | Disposition |
|---|---|
| `TimerProvider` wrapper | **Survives unchanged.** Still the outermost shell provider |
| Sticky `<header>` + `max-w-7xl` | **Replaced** by `UtilityBar` (48px, full width) |
| Brand text link "Intechral Portal" | **Replaced** by `<BrandMark variant="compact">` at the rail top |
| Desktop `<nav aria-label="Primary navigation">` | **Replaced** by `Rail` + `Drawer` |
| Mobile Radix `Dialog` nav sheet | **Reworked** into the S-width nav sheet (rail + current drawer content) |
| Theme toggle button | **Moves** into the account menu as the Appearance segmented control (§13) |
| Account `DropdownMenu` | **Reworked**: rail-anchored, personal-only, "Manage" section **removed** ([§17](#17-account-menu-redesign)) |
| `RunningTimerBar` | **Replaced** by `TimerPill` + `TimerTray` in the utility bar |
| `FlashRegion` | **Survives**, repositioned directly under the utility bar |
| `<main id="main-content">` | **Survives** as the `main` landmark; becomes the full-viewport canvas region |
| `<footer>` copyright | **Removed** from the operator shell (Direction D §6 has no footer). Retained on auth pages if it appears there |
| `AuthLayout` | **Untouched** except for tokens and `BrandMark` |

### 7.2 Blade

| Current | Target |
|---|---|
| `partials/nav.blade.php` horizontal bar | `partials/shell/rail.blade.php` + `partials/shell/utility-bar.blade.php` |
| Inline `@php` call to `NavigationBuilder` inside the view | A **view composer** or middleware-shared `$shell` payload (same builder, same DTO as React) |
| Hand-rolled user dropdown | Same markup pattern, restyled, personal-only, rail-anchored |
| Mobile hamburger + panel | S-width nav sheet with the same semantics |
| `timer-overlay` strip | Restyled vanilla-JS `TimerPill` + `TimerTray` in the utility bar |
| `footer.blade.php` | Removed from the authenticated shell |
| `<main id="main-content">` | Survives; page bodies keep their own containers |

A **simplified drawer** is acceptable in Blade: docked/hidden only, no overlay, no pin, no per-surface override. Blade must render the same rail, the same utility bar, the same tokens and the same top-level navigation data — nothing more is required.

## 8. Theme and token migration strategy

The hard constraint is Direction D §2.4: **add, don't swap**; no intentional regressions.

### 8.1 Resolving F1 — the utility-name collision

Three options were considered against the live census.

| Option | Effect | Verdict |
|---|---|---|
| **A** — Register Direction D tokens under their §2.2 names in `@theme inline` and delete the conflicting `@layer utilities` block | Generates `bg-surface`/`text-muted`/`text-primary` with Direction D values, silently repointing 567 Blade call sites in one step | **Rejected** — a flag-day swap, explicitly forbidden |
| **B** — Register Direction D tokens under prefixed names (`bg-d-surface`, `text-d-muted`) | Zero collision, but every Direction D component carries an ugly transitional prefix that must later be renamed across the whole new codebase | **Rejected** — buys safety now, pays with a second migration |
| **C** — Register Direction D tokens under their §2.2 names, and **first** rename the five colliding legacy utilities to an explicitly legacy prefix, mechanically, in one isolated commit | One purely mechanical rename (`text-primary` → `text-legacy-primary`, etc.) across 567 Blade sites, with values unchanged; afterwards the two systems are namespace-disjoint and Direction D names are clean from day one | **Selected** |

Option C's rename is a pure find-and-replace on class names whose definitions keep their exact current values, so it is verifiable by diff inspection and by the visual pass. The colliding names are exactly five: `bg-surface`, `text-primary`, `text-secondary`, `text-muted`, plus `bg-base`/`bg-elevated`/`border-base`/`border-subtle` which do not collide but move with them for consistency. The rename lands **before** any Direction D token is registered, as its own reviewable slice (WP1a).

### 8.2 Target layer order in `app.css`

```
@import 'tailwindcss';                    @source globs (unchanged)

@theme { … }                              Direction D font families; the legacy
                                          --color-brand-* indigo scale stays (compat)

@theme inline { … }                       (1) Direction D semantic tokens → utilities
                                              canvas, rail, drawer, surface,
                                              surface-sunken/hover/selected,
                                              rule, rule-control, rule-strong,
                                              text, text-secondary, text-muted, text-faint,
                                              accent, accent-hover, accent-soft, accent-line,
                                              live, live-soft, live-text,
                                              ink, on-ink,
                                              danger, danger-soft,
                                              warning, warning-glyph, warning-soft,
                                              success, success-glyph,
                                              progress-fill, progress-track, stage-future,
                                              focus, scrim
                                          (2) shadcn aliases (unchanged names, remapped values)

:root, [data-theme="light"] { … }         Direction D light values
                                          + legacy raw family (unchanged)
                                          + the five F2 variables, newly defined
                                          + motion tokens, shadow tokens

[data-theme="dark"] { … }                 Direction D dark values + the same legacy set

@layer utilities { … }                    renamed legacy utilities (WP1a), values unchanged
```

### 8.3 Alias mapping

Applied only where the mapping cannot reduce contrast, erase a control boundary, or make an element vanish (§2.4.3).

| Legacy alias | Maps to | Safe? |
|---|---|---|
| `background` | `canvas` | Yes |
| `foreground` | `text` | Yes |
| `card` | `surface` | Yes |
| `muted-foreground` | `text-muted` | Yes (both AA on canvas and surface) |
| `border` / `input` | `rule-control` | Yes — `rule-control` is the control-boundary token by definition |
| `ring` | `focus` | Yes |
| `destructive` | `danger` | Yes |
| `primary` | **Hold at legacy indigo initially** | **No** — Direction D primary is ink (L7). Repointing `primary` to `ink` turns every `bg-primary` button into a near-black slab and every `text-primary` link into body-coloured text across all 12 React pages at once. Flip in WP2 together with the Button restyle, not in WP1 |
| `secondary`, `accent-foreground`, `success`/`warning`/`info` | Hold at legacy | Deferred to the slice that migrates their consumers |
| `--bg-*`, `--text-*`, `--border-*`, `--surface-*` raw family | **Unchanged values** | They back 900+ Blade sites; they are frozen, not remapped, until Blade page bodies migrate |

### 8.4 Resolving F2 — defining the five orphans

Each gets a definition chosen to preserve today's *effective* appearance, not the name's literal reading:

| Variable | Light | Dark | Reasoning |
|---|---|---|---|
| `--surface-base` | `var(--bg-base)` | `var(--bg-base)` | Used as a page-level background; today it inherits the body |
| `--surface-muted` | `var(--bg-surface)` | `var(--bg-surface)` | Used for subdued blocks |
| `--surface-elevated` | `var(--bg-elevated)` | `var(--bg-elevated)` | Direct synonym |
| `--border-muted` | `var(--border-subtle)` | `var(--border-subtle)` | The softer of the two border tokens |
| `--surface-accent` | `var(--surface-info)` | `var(--surface-info)` | Used as a tinted callout background |

Each of the 50 call sites is inspected during WP0 to confirm the mapping reads correctly rather than assumed from the name. Any site where the inherited-transparent result is actually *better* keeps `transparent`.

### 8.5 Landing order and verification

1. **WP1a** — rename the eight legacy utilities; values unchanged. Gate: `./dev check`, plus a full Blade visual pass in both themes. Independently revertible.
2. **WP1b** — define the five F2 variables. Gate: the same visual pass; the 50 sites specifically.
3. **WP1c** — add the Direction D token blocks and the safe alias remappings from §8.3. Gate: `./dev check` plus the [legacy-compatibility screen matrix](#254-legacy-compatibility-screen-matrix).
4. **WP1d** — fonts ([§9](#9-typography-and-font-delivery)).

No Direction D component exists yet at the end of WP1. The token layer lands **inert**: it defines vocabulary and nudges the shadcn aliases toward Direction D values, and nothing else.

**Alias retirement** is tracked work, never a side effect (§2.4.7). Each product epic that migrates the last consumer of an alias removes it in that slice, with a repository search proving zero remaining references. This epic retires nothing; it creates the register.

## 9. Typography and font delivery

### 9.1 Families (locked)

| Role | Family | Weights needed | Fallback |
|---|---|---|---|
| UI | IBM Plex Sans | 400, 500, 600 | `system-ui, sans-serif` |
| Data | IBM Plex Mono | 400, 500 | `ui-monospace, monospace` |
| Display | Newsreader (optical size) | 400, 500 | `Georgia, serif` |

Mono always carries `font-variant-numeric: tabular-nums`. Inter and JetBrains Mono are replaced at the base layer.

### 9.2 Delivery options assessed

| Option | Requests | Privacy | Deployment | FOUT control | Assessment |
|---|---|---|---|---|---|
| **Keep fonts.bunny.net** (status quo) | 1 preconnect + 1 CSS + N font fetches to a third party | Third-party request carrying the visitor's IP and UA on every page | Zero build change | Poor — external CSS is render-blocking and outside our `font-display` control | Lowest effort; keeps a third-party dependency on the critical path of an authenticated business portal |
| **npm packages** (`@fontsource-variable/*`) | Bundled through Vite; hashed, immutable, same-origin | None | Adds 3 dependencies; Vite emits the `woff2` assets automatically | Full control via the package's `@font-face` or our own | Good, but pulls the full weight set unless subset manually, and adds dependency surface |
| **Self-host committed `woff2` subsets** | Same-origin, hashed by Vite, preloadable | None | Files in `src/resources/fonts/`, referenced from `app.css`; Vite fingerprints and emits them; cPanel-compatible static assets | Full control: `font-display: swap`, explicit `unicode-range`, `<link rel="preload">` for the two critical faces | **Recommended** |

### 9.3 Recommendation

**Self-host `woff2` files committed to the repository.** Rationale, tied to this repository:

- The current bunny.net link is `render-blocking external CSS in `<head>`` on **both** root views. Self-hosting removes a third-party origin from the critical path of an authenticated portal — a measurable first-paint win and a privacy improvement, since every authenticated page view currently discloses the user's IP to a third party.
- Vite already fingerprints and emits assets referenced from `app.css`, and the hosting target is cPanel-compatible static hosting ([Product Roadmap → Deployment](../product/product-roadmap.md#future--deployment--release-engineering)). Self-hosted files need no new infrastructure.
- Three families × the weights above is a real payload. Subsetting to `latin` + `latin-ext` with explicit `unicode-range` keeps it proportionate; Newsreader ships as a variable optical-size face, so one file covers 400/500.
- It avoids adding three npm dependencies whose only job is to copy files we can commit once.

**Budget gate:** total font payload for the default (latin) subset must stay **≤ 180 KB** across all faces. Exceeding it means dropping a weight (Plex Sans 500 is the first candidate, synthesised from 400/600 only if the visual pass accepts it) or deferring Newsreader to lazy load, since it is used only on entity headers.

**Load strategy:** `font-display: swap`; `<link rel="preload">` for Plex Sans 400 and 500 only; Plex Mono and Newsreader load normally. The `@font-face` block lives in `app.css`, so both renderers get it from one place and the bunny.net `<link>` and `preconnect` are deleted from both root views in the same commit.

**Decision gate G1 (WP0).** This recommendation is not locked. WP0 measures the actual subset payload and runs a rendering check on the densest legacy Blade screens (the operator ticket queue and the invoice form) for clipping and truncation — the §2.4 acceptance condition for a base-layer font swap. If the payload or the density check fails, the fallback is fontsource packages with the same subset budget. **This gate does not block WP1a/WP1b.**

## 10. Brand asset consumption

### 10.1 Canonical assets and one path discrepancy

The committed assets are:

- `src/resources/images/brand/intechral-logo.svg` — full logo
- `src/resources/images/brand/intechral-logo-compact.svg` — compact mark

> **Resolved in WP0 (gate G2, 2026-09-25).** Both files were `git mv`'d into `brand/`, so the paths above are now the live ones and match the canonical contract. The discrepancy described in the rest of this section is historical. See [A1.5](#a15-g2--assets-moved-consumption-proven-one-defect-found-in-the-committed-full-logo).

The design contract §17.1 and §19 cite them as `src/resources/images/brand/intechral-logo.svg` and `…/brand/intechral-logo-compact.svg`. **The `brand/` subdirectory does not exist.** This is a documentation/filesystem mismatch, not a missing asset — both files are present, committed in `6ea4135`, and authoritative.

**Recommendation:** move both files into `src/resources/images/brand/` so the filesystem matches the canonical contract, as the first action of WP0. It is a `git mv` with no current referencing code (neither renderer references either file today), so the blast radius is zero. The alternative — amending §17.1 to the flat path — edits the approved design contract to match an accident, which is the wrong direction. **This is an owner-visible change to a committed path and is recorded as decision gate G2.**

### 10.2 The technical problem with theming them

Both SVGs share a structure that blocks naive consumption:

```xml
<svg viewBox="0 0 512 512" role="img" aria-labelledby="title desc">
  <title id="title">…</title>
  <desc id="desc">…</desc>
  <defs>
    <linearGradient id="strokeFadeFull" …>
      <stop offset="0%" stop-color="#19E7F2" stop-opacity="0.22"/>
      …
    </linearGradient>
    <style>.s{ fill:none; stroke:url(#strokeFadeFull); stroke-width:8; … }</style>
  </defs>
  <path class="s" d="…"/> …
</svg>
```

Three consequences:

1. **The cyan is hard-coded** in `stop-color`, not `currentColor`. Direction D requires solid ink in light and the cyan upward fade in dark. Neither `color:` inheritance nor a `fill`/`stroke` override reaches the gradient stops.
2. **Both files use `id="title"` and `id="desc"`**, and both embed a `<style>` defining the global class `.s`. Inlining both on the same page produces duplicate IDs and a class collision, and `stroke:url(#strokeFadeFull)` would resolve against whichever gradient won.
3. Consuming them via `<img src>` or `background-image` makes them **untheming-able** — no CSS crosses that boundary — and dark mode would show the cyan fade on a light canvas.

### 10.3 Recommended consumption

**Inline the SVG, scope its identifiers, and theme it by overriding the gradient stops from the token layer.** This changes only presentation attributes, which §17.1 explicitly permits, and never touches path data.

It works because **CSS rules always beat SVG presentation attributes** in the cascade. With the mark inlined, the app stylesheet can restyle the stops:

```css
/* Dark: canonical cyan with the source artwork's upward fade — stops untouched. */
/* Light: solid ink, fade flattened. Both are presentation-attribute overrides. */
[data-theme="light"] .brand-mark stop { stop-color: var(--ink); stop-opacity: 1; }
```

Implementation per renderer:

| Renderer | Mechanism |
|---|---|
| React | A `BrandMark` component importing the canonical file with Vite's `?raw` suffix, stripping the `<title>`/`<desc>` (the component supplies its own accessible name), rewriting the gradient `id` and the `.s` class to an instance-unique value, and adding `class="brand-mark"`. Path data is never touched |
| Blade | `partials/shell/brand-mark.blade.php` doing the same transformation server-side, reading the same canonical file |
| Favicon | **Generated** from `intechral-logo-compact.svg`, never redrawn. The existing `public/favicon.ico` predates the canonical assets and is replaced in WP4 |

`variant="compact"` is the default for the operator rail (28px) and the favicon; `variant="full"` is for auth pages and any context with horizontal room. The customer top bar chooses at customer-shell time.

**WP0 spike S4** verifies: the rendered mark at 28px in both themes; that the id-scoping transform survives two marks on one page; and that the stop override produces genuine solid ink in light rather than a washed gradient. If the transform proves fragile, the fallback is a **build-time generation step** whose *input* is the canonical file — still permitted by §17.1 ("generated from one of these sources rather than recreated independently").

## 11. Navigation information architecture

### 11.1 Current → target matrix

Populated entirely from `routes/web.php`, `NavigationBuilder.php` and the controllers, at `6ea4135`.

| Target workspace | Current source routes | Available now? | Capability gate | Drawer sections available now | Visit mode | Future-only — must NOT appear |
|---|---|---|---|---|---|---|
| **Home** | `dashboard` | Yes | authenticated | **None** — single surface, no views. Direction D §5.3.5: no drawer | inertia | Queues, decisions, watch lists, approvals, project-health and Helpdesk intelligence |
| **Projects** | `projects.index`, `projects.create`, `projects.edit`, `projects.board`, `projects.milestones.index`, `projects.tasks.show` (`projects.show` 302s to `projects.board`) | Yes | nav gate `projects.view`; row access remains `Project::visibleTo` / `ProjectPolicy` | **Views:** All projects. **Actions:** New project (`projects.manage`) | inertia | Saved views, recent/watched projects, portfolio, Monitoring, Change control, list view |
| **Tasks** | `tasks.index` | Yes | authenticated | **Views:** My tasks, Organisation tasks (the existing in-page tabs, promoted to drawer views) | inertia | All Tasks (capability-gated), saved views, filters, Complete/Reopen, peek inspector |
| **Helpdesk** | `tickets.index`, `tickets.create`, `operator.tickets.index`, `operator.tickets.reports` | Yes | `tickets.view` (any entry); Queue and Reports on `tickets.assign` | **Views:** My requests (`tickets.view`), Queue (`tickets.assign`), Reports (`tickets.assign`) | **document** | Incidents, Knowledge, SLA, saved queue views, triage inspector |
| **Time** | `time.index`, `time.allocation`, `operator.time.index` | Yes | `time.log`; Reports on `time.view_all` | **Views:** My time, Allocation, Reports (`time.view_all`) | inertia | Approvals, team allocation, rates, budget consumption |
| **Directory** | `crm.contacts.index`, `crm.companies.index`, `organizations.index` | Partly | `crm.manage` (all three) | **Views:** People (`crm.contacts.index`), Organizations (`crm.companies.index`), Portal access (`organizations.index`) | **document** | **Relationships**, classifications (Customers/Partners/Prospects/Vendors), Person↔User linking, relationship hub |
| **Finance** | `billing.invoices.index` (`billing.manage`) **or** `billing.client.invoices.index` (else) | Yes | `billing.manage` → operator; `billing.view` → own. Branch preserved verbatim | **Views:** Invoices (operator) **or** My invoices (client) | **document** | Retainers, Rates, Budgets, Billing/Commercial Account, billing runs, Payments as a section |
| **System** | `users.index`, `roles.index`, `operator.cms.index` | Yes | any of `users.view`, `roles.view`, `cms.edit` | **Views:** Users (`users.view`), Roles (`roles.view`), Pages (`cms.edit`) | **document** | Settings, Integrations, audit/system controls, invitation management as a section |
| **Pages** *(transitional, see §11.3)* | `cms.index` | Yes | `cms.view` **and not** `cms.edit` | None — single surface | **document** | Knowledge; public publishing |

### 11.2 What this changes, and what it deliberately does not

**Relabels that are safe now** (pure presentation over unchanged routes):

- Dashboard → **Home**
- Tickets + Ticket Queue + ticket Reports → one **Helpdesk** workspace
- Billing → **Finance**
- CRM contacts → Directory → **People**
- Users, Roles, CMS Pages → **System**
- Time Reports moves from the "Manage" group into the **Time** workspace
- Organizations moves from "Manage" into **Directory**
- The **"Manage" group is retired as a concept.** Its six entries are redistributed into the workspaces above; none of them remains in the account menu ([§17](#17-account-menu-redesign))

**Deliberately not done:**

- **No Directory domain migration.** `crm_companies` and `organizations` are two different tables with two different meanings ([IA → Organization](../product/information-architecture.md#organization)). Directory shows both as distinct entries — "Organizations" for the directory-style `crm_companies` record and "Portal access" for the tenancy `organizations` record. Collapsing them is a data migration and belongs to the Directory epic.
- **No Relationships entry.** Nothing in the schema models a Relationship. Direction D forbids implying unbuilt features exist.
- **No Incidents or Knowledge** under Helpdesk. Neither exists.
- **No Retainers, Rates or Budgets** under Finance. None exists.
- **No All Tasks** capability split. `tasks.index` is a single ungated surface today.
- **No permission renames.** Every gate above is an existing catalogue permission used exactly as `NavigationBuilder` uses it now. The IA's "Directory capability" is `crm.manage` today and stays `crm.manage`.

### 11.3 The viewer-facing CMS problem

`cms.index` ("Pages") is currently a top-level item gated on `cms.view`, which the built-in `user` role holds by default. The IA places operator page management under **System → Pages** but leaves viewer placement explicitly **Open**.

Naively following the eight-workspace target would drop `cms.index` from navigation entirely, **removing every customer's access to published pages** — a real functional regression, not a cosmetic one.

**Recommendation:** keep a transitional ninth rail item, **Pages**, gated on `cms.view AND NOT cms.edit`. Operators (who hold both) reach page management through System → Pages and never see the duplicate; customers keep their access. The item is marked transitional in the navigation contract and is resolved by the Knowledge/CMS evolution epic, which decides whether this content is Knowledge or true CMS. **Decision gate G3**, non-blocking: the recommendation ships unless the owner directs otherwise.

### 11.4 Visit modes during coexistence

`visit` is already part of the item contract and is set per destination, exactly as today. Five of the nine workspaces (Helpdesk, Directory, Finance, System, Pages) are `document`; four (Home, Projects, Tasks, Time) are `inertia`. As Blade modules migrate in later epics, individual entries flip — the contract does not change.

Crossing a `document` boundary is a full page load, so the React shell unmounts and the Blade shell renders. Shell continuity across that boundary is what [§14](#14-blade-parity) exists to guarantee, and it is a first-class Playwright assertion ([§25.3](#253-playwright)).

## 12. Navigation server contract

### 12.1 Design constraint: presentation-neutral content

An earlier draft of this contract embedded `drawer { default, sections[] }` directly in each workspace. That was wrong under **L17**: it made the server-owned navigation model structurally specific to the rail/drawer geometry, so a future Focused presentation could only consume it by pretending to be a drawer.

The contract is therefore split in two:

| Part | Owns | Who reads it |
|---|---|---|
| **`context`** — *content* | The authorized contextual navigation: sections of items the actor may reach inside this workspace | **Every** presentation. Operational projects it into the drawer; Focused projects the same data into menus, tabs and view selectors |
| **`presentation`** — *hints* | Presentation-family-specific defaults, namespaced per family | Only the matching shell. An unknown family is ignored |

`context` never contains the words drawer, rail or panel. `presentation` never contains navigation destinations. This is the whole separation, and it is small on purpose — it carries exactly enough semantics for the two Direction D presentation families and is **not** a generic navigation framework (rule 9).

### 12.2 Target shape

`NavigationBuilder::build(Request): array` returns one object, not a list of groups:

```php
[
  'currentWorkspace' => 'projects',          // null when no workspace matches
  'workspaces' => [
    [
      // ── Identity and destination (presentation-neutral) ──────────────
      'key'      => 'projects',
      'label'    => 'Projects',
      'icon'     => 'folder-kanban',         // stable key, NOT markup
      'href'     => '/projects',
      'visit'    => 'inertia',               // 'inertia' | 'document'
      'isActive' => true,

      // ── CONTENT: authorized contextual navigation, presentation-neutral ──
      // Operational renders these as drawer sections.
      // Focused renders the same data as menus / tabs / a view selector.
      'context'  => [
        [
          'key'   => 'views',
          'label' => 'Views',
          'kind'  => 'views',                // semantic projection hint, see below
          'items' => [
            [
              'key'      => 'projects.all',
              'label'    => 'All projects',
              'href'     => '/projects',
              'visit'    => 'inertia',
              'isActive' => true,
              'count'    => null,            // reserved; always null in this epic
            ],
          ],
        ],
        [
          'key'   => 'actions',
          'label' => null,
          'kind'  => 'actions',
          'items' => [
            [
              'key'      => 'projects.create',
              'label'    => 'New project',
              'href'     => '/projects/create',
              'visit'    => 'inertia',
              'isActive' => false,
              'count'    => null,
            ],
          ],
        ],
      ],

      // ── PRESENTATION: family-namespaced hints only. No destinations. ──
      'presentation' => [
        'operational' => [
          'panel' => 'open',                 // 'open' | 'collapsed' | null
        ],
      ],
    ],
    // …
  ],
]
```

**`context[].kind`** is the one piece of semantics that lets a non-drawer presentation project sensibly instead of guessing. Five values, all derived from what the live routes actually provide:

| `kind` | Meaning | Operational projection | Plausible Focused projection (later epic) |
|---|---|---|---|
| `views` | Mutually exclusive ways of looking at the same workspace | Drawer section; selected row carries the `accent-line` strip | View selector or tabs |
| `queues` | Filtered work lists, countable | Drawer section with right-aligned mono counts | Menu group, or tabs with counts |
| `entities` | Specific records (recent/watched) | Drawer section | Menu group — **no entries exist today** |
| `saved` | User-saved views | Drawer section | Menu group — **no entries exist today** |
| `actions` | Workspace-level actions rather than destinations | Drawer footer / header action | Primary action in the top bar or page header |

`entities` and `saved` are **declared but never emitted in this epic** — no route or endpoint provides them. They are in the enum so the projection table is complete and a later epic adds data, not a new concept. An unknown `kind` must render as a plain section rather than throwing.

**`presentation.operational.panel`** is the only presentation hint in this epic. It carries the Direction D §5.3 per-workspace default (`open` | `collapsed`), and it is `null` when the workspace has no contextual panel.

**Emptiness is semantic, not presentational.** `context => []` means the workspace has no contextual navigation at all, for *any* presentation — Home is the live example (§5.3.5: one surface, no views). Both shells derive "nothing to project" from the empty `context`, and `panel` is `null` in that case. Neither shell infers emptiness from the other's hint.

### 12.3 Contract rules

1. **One source of truth.** The client never derives visibility, destinations, active state or presentation defaults. It renders what it is given. (L14)
2. **Icons are keys, not markup.** The server sends `'folder-kanban'`; React maps the key to a `lucide-react` component, Blade maps it to an inline SVG partial. No SVG crosses the wire, and an unknown key renders a documented neutral fallback rather than throwing.
3. **Active state stays server-computed** via `$request->routeIs(...)`, as today. Workspace and context-item `isActive` are computed the same way. Exactly one workspace and at most one context item may be active (Direction D §8: at most one strip per screen) — asserted by test.
4. **Authorization is expressed only in `context`, never in `presentation`** (L17). A presentation hint may never add, remove, gate or re-order a destination. Dropping the entire `presentation` key must leave the actor's authorized navigation model completely intact — asserted by test ([§25.1](#251-pest)).
5. **`presentation` is namespaced per family and optional.** A shell reads `presentation[<its family>]` and **ignores every other key**. A missing or unknown family falls back to documented defaults rather than throwing, so adding `presentation.focused` later is additive and breaks nothing.
6. **`context => []` means no contextual navigation, for every presentation.** Operational then renders no drawer and its rail item links straight to the surface (§5.3.5); Home uses this. `presentation.operational.panel` is `null` in that case.
7. **A workspace is emitted only if it has at least one permitted entry.** An empty workspace is omitted entirely, never rendered disabled.
8. **`count` is reserved and always `null`.** The field exists so a count slot has a stable contract in either projection, but no endpoint provides counts and none is added. Populating it is later product work.
9. **Two levels of nesting, no more.** The unused `children: []` field is dropped. Nesting is `workspace → context section → item`, and Direction D §8 forbids a permanent third navigation level.
10. **No plugin framework.** The builder stays a hand-written PHP class with explicit workspace methods, as it is today. No registry, no discovery, no config-driven navigation. The `context`/`presentation` split carries exactly enough structure for the two Direction D presentation families and is not generalised beyond them.
11. **Both renderers consume the same array.** React gets it through `HandleInertiaRequests::share` (still a lazy closure). Blade gets it through a **view composer** bound to the shell partials, replacing the `@php` call inside `nav.blade.php` — the builder is invoked once per request either way.

### 12.4 Account-menu boundary in the contract

The builder emits navigation only. It does **not** emit account-menu items: those are static, personal, identical for every user, and gated by nothing. Keeping them out of the navigation payload is what structurally prevents the "Manage" section from ever returning ([§17](#17-account-menu-redesign), [§27](#27-security-and-privacy-review)).

### 12.5 Shared Inertia props

`HandleInertiaRequests::share` gains exactly two keys and changes one:

| Prop | Change |
|---|---|
| `navigation` | **Reshaped** to [§12.2](#122-target-shape). Still a lazy closure |
| `shell` | **New**: `['presentation' => 'operational']` — the presentation discriminator ([§23](#23-audience-presentation-and-the-shell-seam)). One string, server-resolved, always `'operational'` in this epic. Named for the presentation family rather than the audience, per L17 |
| `auth.user` | **Gains `avatar`**: `['initials' => 'DW', 'url' => null]`. `url` is `null` in this epic (no avatar storage exists); `initials` are derived server-side so both renderers agree |

Nothing else is added. `auth.permissions` already ships the effective permission list and is not extended. No model, no role list, no organization list ([§27](#27-security-and-privacy-review)).

## 13. React operator shell

### 13.1 Composition

```
<AppShell>                                    reads shell.presentation; Operational only today
  <SkipLink />                                first focusable element, targets #main-content
  <TimerProvider enabled={can('time.log')}>   UNCHANGED from today
    <div data-shell-grid>                     CSS grid: [rail] [drawer?] [canvas]
      <Rail>                                  <nav aria-label="Workspaces">
        <BrandMark variant="compact" />
        <DrawerToggle />                      rendered only while the drawer is collapsed
        <RailItem … aria-current="page" />    one per workspace
        <AccountTrigger />                    40×40 rounded-square tile, 28px circular Avatar
      </Rail>
      <Drawer>                                <nav aria-label="{Workspace} views">
        <DrawerHeader />                      workspace name + collapse (docked) / pin+close (overlay)
        <DrawerSection><DrawerItem …/></…>
      </Drawer>
      <div data-canvas>
        <UtilityBar>                          <header>
          <Breadcrumb />                      always present, ends with the current page
          <ViewSwitcher />                    only while the drawer is collapsed
          <SearchTrigger />                   NEXT — not rendered in this epic
          <TimerPill />                       §18
        </UtilityBar>
        <FlashRegion />
        <main id="main-content">{children}</main>
      </div>
    </div>
  </TimerProvider>
</AppShell>
```

### 13.2 Component responsibilities

| Component | Notes |
|---|---|
| `AppShell` | Reads `shell.presentation` and delegates. The **single shell-resolution boundary** — the only place that knows more than one presentation can exist (L17, §23.2) |
| `Rail` | 64px; items 52×46; `<nav aria-label="Workspaces">` of plain `<a href>` in Tab order (L11); `aria-current="page"` on the active item; selected treatment is `surface-selected` + 1px `rule` ring, **no strip** (§8) |
| `Drawer` | 248px; three states — docked, collapsed, overlay ([§15](#15-drawer-state-and-persistence)). Selected item is the **only** place the 2px `accent-line` strip appears |
| `UtilityBar` | 48px `<header>`. Never carries workspace navigation (§6) |
| `Breadcrumb` | Built from `currentWorkspace` + the active drawer item + a page-supplied trail prop. Always ends with the current page |
| `ViewSwitcher` | Renders only while the drawer is collapsed; a Radix DropdownMenu (a legitimate composite widget, so its arrow keys are correct — L11) |
| `AccountTrigger` / `AccountMenu` | [§17](#17-account-menu-redesign) |
| `TimerPill` / `TimerTray` | [§18](#18-global-timer-foundation-scope) |
| `SkipLink` | **New.** First focusable element; visible on focus; `href="#main-content"` |

### 13.3 What is deleted

`app-layout.tsx`'s sticky header, `max-w-7xl` wrapper, brand text link, horizontal primary nav, standalone theme toggle, the account menu's "Manage" section, and the global `<footer>`. `RunningTimerBar` is deleted once `TimerPill`/`TimerTray` pass their tests (WP6), not before.

### 13.4 Coexistence during the epic

`AppLayout` is **renamed in place** to keep the 12 `Page.layout` lines working, then reimplemented. It never forks: there is no period where some pages use an old shell and others a new one. The shell flips once, in WP4, for all 12 pages simultaneously — which is safe precisely because page bodies are untouched (they keep their own containers, [§19](#19-page-frames-and-content-widths)).

## 14. Blade parity

### 14.1 What parity means here

Direction D §19 step 5 requires that "the two renderers don't diverge". The minimum safe parity is **shell chrome and tokens**, not interaction fidelity:

| Required | Not required |
|---|---|
| Same 64px rail, same workspace items, same order, same active state | Overlay drawer |
| Same 48px utility bar with breadcrumb and timer pill | Drawer pinning or per-surface overrides |
| Same token system and typography | Inspectors, view switcher, command affordances |
| Same top-level navigation **data** (one builder, one payload) | React-equivalent animation or focus choreography |
| Same account menu contents and personal-only boundary | Radix-equivalent menu semantics beyond `role="menu"` + Escape + click-outside (already present) |
| Same brand mark, same skip link, same landmarks | — |

### 14.2 Blade drawer: simplified

Blade gets **docked or hidden only**, driven by the same `presentation.operational.panel` hint from the contract and the same `data-drawer` attribute ([§15](#15-drawer-state-and-persistence)). No overlay, no pin, no focus trap. At M/S the Blade drawer is simply not rendered and its content folds into the existing nav sheet. This is explicitly permitted ("A simplified drawer is acceptable in Blade until those areas migrate").

### 14.3 Blade page bodies

**Untouched.** The 45 Blade views keep their markup and their legacy utility classes (renamed in WP1a). They will look transitional inside the new chrome — accepted by §2.4.4. What must not happen is illegibility, invisible controls or broken layout, which is what the [compatibility matrix](#254-legacy-compatibility-screen-matrix) checks.

### 14.4 Files

| File | Action |
|---|---|
| `layouts/app.blade.php` | Rewritten: shared pre-paint bootstrap partial, new shell includes, footer removed |
| `views/app.blade.php` (Inertia root) | Same pre-paint bootstrap partial, fonts updated. Otherwise unchanged |
| `layouts/partials/shell/bootstrap.blade.php` | **New** — the single pre-paint script for theme + drawer, included by both roots. Resolves today's duplication |
| `layouts/partials/shell/rail.blade.php` | **New** |
| `layouts/partials/shell/drawer.blade.php` | **New** (simplified) |
| `layouts/partials/shell/utility-bar.blade.php` | **New** |
| `layouts/partials/shell/account-menu.blade.php` | **New** — extracted from `nav.blade.php`, personal-only |
| `layouts/partials/shell/brand-mark.blade.php` | **New** ([§10](#10-brand-asset-consumption)) |
| `layouts/partials/nav.blade.php` | **Deleted** after its parts are redistributed |
| `layouts/partials/footer.blade.php` | **Deleted** from the authenticated shell |
| `layouts/partials/timer-overlay.blade.php` | Rewritten as the pill/tray mount ([§18](#18-global-timer-foundation-scope)) |
| `App\View\Composers\ShellComposer` | **New** — binds the navigation payload and shell props to the shell partials |

## 15. Drawer state and persistence

### 15.1 The contract to satisfy (Direction D §5.3)

Remembered per `(user, workspace)` where applicable · sensible per-workspace default · **no distracting layout flash** · works across Inertia visits · works across full-document Blade transitions.

Direction D §5.3.4 *recommends* a cookie read by Laravel. The recommendation is not locked, and the live architecture points elsewhere.

**Scope clarification (L17).** Drawer state is an **Operational-presentation preference** — how wide the contextual panel is in one geometry — and is **not the user's shell identity**. It says nothing about which presentation family the actor uses, and it is not a step toward a persisted shell preference (L18 forbids one). Two consequences that matter for later work:

- A future Focused shell **inherits none of this** and must not emulate a 248px drawer merely for parity ([§23.3](#233-functional-parity-not-geometric-parity)). It projects the same authorized `context` into its own affordances and remembers whatever suits them, or nothing at all.
- The storage key is therefore namespaced to the family ([§15.5](#155-storage-shape)), so a second family cannot collide with or inherit Operational's panel state.

### 15.2 Evidence that changes the analysis

1. **SSR is off** (`inertia({ ssr: false })`). The Inertia root view ships `<body><div id="app"></div></body>`. **Nothing of the React shell paints before JavaScript runs.** There is therefore no hydration mismatch to guard against and no pre-JS layout to get wrong — a synchronous read during React's first render is already flash-free on every Inertia page.
2. **Blade is the opposite case.** `layouts/app.blade.php` renders complete shell markup server-side, so Blade genuinely needs the state before the body paints.
3. **The pre-paint attribute technique is already proven in this repository.** The theme IIFE in `<head>` sets `data-theme` on `<html>` before body paint, and `inertia-coexistence.spec.ts` verifies it survives a React→Blade→React walk. Drawer state is the same shape of problem, one attribute wider.
4. **Per-workspace state needs to know the current workspace before paint.** That is server knowledge, and duplicating URL→workspace matching inside an inline script would fork navigation truth — violating L14.

### 15.3 Recommended architecture

**Server-stamped workspace + `localStorage` + one shared pre-paint bootstrap. No cookie.**

1. Both root views render `<html data-theme="…" data-workspace="{{ $shell['currentWorkspace'] }}">`. The workspace comes from `NavigationBuilder`, so there is exactly one matcher.
2. `partials/shell/bootstrap.blade.php` — one inline IIFE, included by **both** roots (replacing today's duplicated theme script). Before paint it:
   - resolves and applies `data-theme` (existing behaviour, unchanged);
   - reads `localStorage['shell.operational.panel']` (a JSON map `{"projects":"open","tasks":"collapsed"}`), looks up `data-workspace`, falls back to a server-stamped `data-drawer-default`, and sets `data-drawer="open|collapsed"` on `<html>`;
   - is wrapped in `try/catch` so a blocked or corrupt `localStorage` falls through to the server default rather than throwing.
3. The shell grid is driven **from CSS** off `html[data-drawer]`, so the correct geometry is in the first paint on Blade and in the first React render on Inertia.
4. React reads the same attribute at mount, owns it thereafter, and writes both the attribute and `localStorage` on toggle. On each Inertia visit the shell updates `data-workspace` and re-resolves `data-drawer` from the map.
5. Width classes are layered on top: L honours "open" only if the user pinned at L; **M/S ignore the preference entirely** (§5.3.3), enforced in CSS so no JS timing is involved.

### 15.4 Why not the cookie

| | Cookie + Inertia prop | Server-stamped workspace + localStorage |
|---|---|---|
| Blade first paint | Correct | Correct (pre-paint attribute, same technique as theme today) |
| Inertia first paint | Correct | Correct — **no SSR, so nothing paints before JS** |
| New server surface | A write path (endpoint or JS cookie write) + a shared prop + cookie sizing/`SameSite` decisions | None |
| Consistency with §15.5 | A client-owned UI preference travelling on every HTTP request | Client-owned preference stays client-side |
| Consistency with the theme | Diverges — theme is localStorage, drawer would be a cookie | Identical contract, one shared script |
| JS disabled | Correct | Falls back to the server default — acceptable for a React SPA |

The only real advantage of the cookie is the JS-disabled case, which does not apply to a platform whose primary surfaces are React.

**Spike S1 (WP0)** settles it with evidence: instrument a prototype and confirm no layout shift at XL on a Blade page and an Inertia page, in both themes, in a cold load and a hard reload. **If S1 shows any reflow the cookie is adopted instead** — the contract, not the mechanism, is what is locked.

### 15.5 Storage shape

`localStorage['shell.operational.panel']` = `{"<workspaceKey>": "open"|"collapsed", "<workspaceKey>:<surfaceKey>": "open"|"collapsed"}`.

The key is namespaced to the **Operational** family deliberately: it is that presentation's panel state, not a global shell preference, so a future Focused shell neither reads nor inherits it. The per-surface override key is written **only** when the user changes state on that surface (§5.3.1); otherwise the surface default applies. Unknown keys are ignored, and a parse failure resets the map rather than throwing.

## 16. Responsive behaviour

Width classes are the Direction D §5.2 contract, implemented as CSS container/media queries on the shell grid so no JavaScript decides layout.

| Class | Width | Operator behaviour |
|---|---|---|
| **XL** | ≥ 1360 | Rail + drawer docked or collapsed per workspace default / remembered state |
| **L** | 1024–1359 | Rail; drawer collapsed by default and opens as an **overlay**; a pin docks it and is remembered for L |
| **M** | 768–1023 | Rail; drawer is **overlay only**, pin hidden; preference ignored |
| **S** | < 768 | Rail becomes a 56px top bar with a menu button opening a nav sheet containing workspaces + the current drawer content; preference ignored |

Rules that matter for implementation:

- Preference is honoured **only at XL** (and the separate L pin). M/S ignore it in CSS, not in JS, so there is no resize-listener race.
- The overlay drawer has **no scrim** (§5.4) — content stays visible. It closes on `Esc`, outside click and navigation, moves focus in on open and returns focus to the toggle on close.
- Touch targets are ≥ 44px at S; rows are ≥ 64px.
- The existing Playwright mobile assertion at 390×844 (`inertia-coexistence.spec.ts`) must keep passing in spirit — the nav sheet replaces the mobile menu and the test is updated to the new semantics, not deleted.
- Inspectors are out of scope, so the M/S inspector-to-sheet rule is documented but not implemented.

## 17. Account menu redesign

### 17.1 Target contents (Direction D §13)

| # | Item | Meta | Destination now |
|---|---|---|---|
| — | Header: avatar, name, email | | — |
| 1 | Profile | | `profile.show` |
| 2 | Security & MFA | "MFA on" (`success`) / "Not set up" (`warning`) | `profile.show` (2FA section) |
| 3 | Connected accounts | Provider names | `profile.show` (social section) |
| 4 | Sessions | "N active" | `profile.show` (sessions section) |
| 5 | Appearance | Inline segmented control: Light · Dark | in-menu |
| 6 | Notifications | Disabled, marked FUTURE | — |
| — | Divider | | |
| 7 | Keyboard shortcuts | `?` | NEXT — **not rendered** |
| 8 | Sign out | | `POST logout` |

### 17.2 Scope decisions

- **The "Manage" section is removed** from both renderers. Its six entries live in the Helpdesk, Time, Directory and System workspaces ([§11](#11-navigation-information-architecture)). This is the single most important account-menu change and is enforced by test in both Pest and Vitest ([§27](#27-security-and-privacy-review)).
- **Profile is not split into separate pages in this epic.** Direction D §13 says "items link to account pages at reading width" and the IA says the current single Profile page "splits into these sections without changing Fortify behavior". Splitting one Inertia page into four routes is product work with its own Fortify-adjacent risk. **Items 2–4 anchor-link into the existing `profile/show` sections** (`#security`, `#connected-accounts`, `#sessions`), which is honest, requires no routing change, and leaves the split to a later account epic. Recorded as a scope decision, not an open question.
- **Meta values ("MFA on", "N active") are rendered only if the data is already on the page.** No new endpoint or shared prop is added to populate them; where the value is unavailable the meta slot is simply empty. Shipping "Not set up" to a user with MFA on would be worse than shipping nothing.
- **Notifications** renders disabled with a visible FUTURE affordance — but per Direction D's own rule, implementation must not ship the mockups' `FUTURE` tag styling as if it were product chrome. It is a disabled item with `aria-disabled` and a reason, nothing more.
- **Appearance** absorbs today's standalone theme-toggle button. It is a `radiogroup` inside the menu with Light and Dark only (L6). The button is removed from the utility bar and from the Blade nav.
- **Trigger** (L9): operator rail → a 40×40 rounded-square `<button>` (radius 6, `surface` + `rule-control` ring) containing a 28px circular `Avatar`; `aria-haspopup="menu"`, `aria-expanded`, accessible name "Account menu: {name}". Opens up and right.

## 18. Global timer foundation scope

### 18.1 In and out

Straight from Direction D §12.0. **In:** zero/one/several states in the pill; newest timer shown compactly with `+N`; a tray listing all active timers; individual Stop from pill and tray; editable description via the existing `PATCH` endpoint; contextual start/stop on surfaces that already have it; pending and error states; `TimerProvider` reconciliation **unchanged**; zero-state link to the existing start flow, with direct reuse of the existing cascading context selector permitted.

**Out (NEXT):** Stop all · long-running warning and its threshold · richer global context search / quick start · "Today N logged" in the tray header. **Out (Future):** Switch.

### 18.2 Backend

**No backend changes.** All five existing endpoints already support every Foundation capability:

| Capability | Endpoint |
|---|---|
| List active timers, clock offset | `GET /time/timers/active` |
| Start | `POST /time/timer/start` |
| Stop (individual, idempotent) | `POST /time/timer/{entry}/stop` |
| Edit description | `PATCH /time/timer/{entry}/description` |
| Zero-state start selector | `GET /time/context-options` |

Stop All is out of scope precisely because no endpoint exists, and client-side fan-out of individual stops is explicitly not a substitute.

### 18.3 React

- `TimerPill` — utility-bar right. States: none (ghost "Start timer") · one (live dot + mono `H:MM:SS` + context truncated ~28 chars + Stop) · several (same, showing the **most recently started**, plus a `+N` badge in `live-soft`/`live-text` and a ▾) · pending (hollow live ring, "Starting…"/"Stopping…", no elapsed, disabled) · failed (last confirmed state + "Couldn't stop" in `danger`, `danger` border; retry for stop only, never for start) · narrow S (dot + `H:MM` + `+N`). Never wider than ~360px.
- `TimerTray` — a **Radix Popover** (new dependency), 400–420px, level 2. Header "N running"; rows newest-first with live dot, title, context line, inline-editable description (blur/Enter, pending and error feedback), elapsed, Stop; footer "Open Time →". Normal Tab order through row controls; `Esc` closes and returns focus to the pill. `aria-live="polite"` announcements for started/stopped. **No** Stop all, no "Today N logged", no "Start another…" search — those parts of mockup D7 are NEXT.
- `TimerControl` — contextual idle/running/pending/unavailable control for the surfaces that already start timers. Starting from a context never stops other timers.
- **Ticking stays localised.** This is the explicit EPIC-011D lesson and a hard requirement: the one-second interval lives **inside `TimerPill`** (and inside `TimerTray` while open), never in `AppShell` or `TimerProvider`. A timer tick must not re-render the rail, drawer, breadcrumb or page. Enforced by the [performance test](#26-performance-and-bundle-considerations).
- `RunningTimerBar` is deleted only after `TimerPill`/`TimerTray` pass their tests.

### 18.4 Blade

Per [§2](#2-relationship-to-the-product-roadmap) and ADR-007, **no React island on Blade pages**. `timer-overlay.js` is reworked in place to render the same pill and tray as vanilla JS against the same endpoints, mounted in the utility bar. Its eight-entry decorative `PALETTE` — including four hard-coded hexes — is **deleted**; tiles use `live`/`live-soft`/`live-text` like every other surface. The existing `timerStarted`/`timerStopped` custom-event contract is preserved so `components/time-tracker.blade.php` and the ticket pages keep working unchanged.

## 19. Page frames and content widths

### 19.1 The problem

The new shell is full-viewport. Twelve React pages currently each supply their own `mx-auto max-w-*` container:

| Width | Pages |
|---|---|
| `max-w-7xl` | dashboard, projects/index, time/index, time/allocation, operator/time/index |
| `max-w-5xl` | tasks/index, projects/create, projects/edit, projects/tasks/show, profile/show |
| `max-w-4xl` | projects/milestones/index |
| none (already full-bleed) | projects/board |

`app-layout.tsx` also applies `max-w-7xl` to its header only; `<main>` is already unconstrained.

### 19.2 Approach — additive, opt-in, zero forced rewrites

`<main>` becomes the full-viewport canvas region. **Legacy pages need no change at all**: their `mx-auto max-w-*` container simply centres inside a wider main, which remains correct and usable. There is no flag day and no requirement that every page migrate together.

Three new primitives, which pages adopt when their product epic reaches them:

| Primitive | Behaviour | Direction D §9 class |
|---|---|---|
| `<PageFrame width="canvas">` | Full width minus page padding (40/32/24/16 by width class) | Canvas — tables, boards, queues |
| `<PageFrame width="grid">` | Full width, `1fr + 340–360px` split | Grid — Home, overviews |
| `<PageFrame width="reading">` | Centred column, `max-w` from a `reading` variant (640 forms / 700 conversation / 720 account / 760 knowledge) | Reading — forms, conversations, account |

`PageHeader` and the new `EntityHeader` + `Strata` compose inside a `PageFrame`. Page padding is owned by `PageFrame`, not by `main`, so a canvas page can deliberately reclaim it.

### 19.3 Migration path

- **WP7** converts only the pages it is already touching — **Home** (Dashboard) to `grid`, and **projects/board** to `canvas` (a one-line win: it is already full-bleed and immediately benefits from the wider viewport).
- The remaining ten pages keep their containers and are converted by the epic that redesigns them.
- An ESLint `no-restricted-syntax` rule is **not** added; forbidding `max-w-*` would fight the ten pages that still legitimately need it. Instead the alias/width register ([§24](#24-migration-compatibility-strategy)) lists the remaining ten so they are visible, not enforced.

## 20. Shared primitive strategy

Classification of every existing shared primitive. The governing rule (Direction D §19 step 2) is that restyling must keep existing consumers readable and usable, and that **no page is migrated merely for aesthetics**.

| Component | Class | Notes |
|---|---|---|
| `Button` | **Restyle in place** | Variants → primary (**ink**), secondary (outline: `surface` + `rule-control`), ghost, destructive; sizes sm/md/lg; radius 5 (7 at lg). Touches every React page at once, so it lands with the `primary`-alias flip (§8.3) and its own visual pass |
| `Input`, `Textarea`, `Label` | **Restyle in place** | Radius 5, `rule-control` border, `focus` ring at 2px/2px offset |
| `NativeSelect` | **Retain** | Stays native and accessible. A Direction D `Select`/`Combobox` is added **only** when a real consumer needs searching — no consumer does in this epic |
| `DropdownMenu` (Radix) | **Restyle** | Level-2 elevation, radius 8. Reused by `AccountMenu` and `ViewSwitcher` |
| `Dialog` / `DialogShell` | **Restyle** | Level 3 (`shadow-overlay` + `scrim`), radius 8 |
| `ConfirmationDialog` | **Restyle** | Plus the §15 rule: confirm only when the action is consequential |
| `FormDialog` | **Restyle** | No structural change |
| `Alert` | **Restyle** | Aligns with §15.3 region-banner treatment |
| `Badge` | **Retain via alias, then split** | Its five variants are bound to raw status `var(--*)`. Direction D replaces *status* usage with the new `Status` component (glyph + label, never colour alone). `Badge` survives for tags and filter chips. Its status variants stay working until each consumer adopts `Status` |
| `Progress` | **Rework** | Becomes `ProgressBar`/`Meter` per §11.3: 4px/6px, radius half height, `progress-fill`/`progress-track`, always paired with a mono number, optional expected marker, `aria-valuetext` including the expected value. Its existing `role="progressbar"` + `aria-valuetext` contract is already correct and is preserved |
| `Pagination` | **Restyle** | No structural change |
| `PageHeader` | **Rework** | Overline + `title` + optional summary + actions (one ink primary) |
| `SectionPanel` | **Rework → `Section`** | Section title over a `rule-strong` line (§4.3), not a bordered panel — the "structure over boxes" rule (L10) |
| `NavigationLink` | **Replace** | Superseded by `RailItem` / `DrawerItem`, which add the missing `aria-current` |
| `FlashRegion` | **Restyle** | Keeps its current live-region semantics |
| `RunningTimerBar` | **Replace** | → `TimerPill` + `TimerTray` |
| Domain components (`projects/`, `tasks/`, `time/` — 50 files) | **Untouched** | They inherit restyled primitives and tokens. Their redesign belongs to their product epics |

**New components in this epic** (Direction D §18, foundation subset only): `AppShell`, `Rail`, `RailItem`, `Drawer`, `DrawerSection`, `DrawerItem`, `UtilityBar`, `Breadcrumb`, `ViewSwitcher`, `SkipLink`, `AccountMenu`, `AccountTrigger`, `Avatar`, `BrandMark`, `TimerPill`, `TimerTray`, `TimerControl`, `PageFrame`, `Section`, `EntityHeader`, `Strata`, `Status`, `Tag`, `EmptyState`, `ErrorState`, `Skeleton`, `Tabs` (Radix), `Tooltip` (Radix), `Popover` (Radix).

**Deferred, despite appearing in Direction D §18:** `Priority`, `StagePath`, `Chip`/`FilterChip`, `FilterBar`, `DataTable` conventions, `BulkBar`, `Card`, `Combobox`, `CustomerShell`/`TopNav`. Each is built by the first product epic that has a real consumer — the roadmap's "foundations earn their keep" principle. Building them here would mean writing components with no caller and no way to validate them.

**New dependencies required:** `@radix-ui/react-popover`, `@radix-ui/react-tabs`, `@radix-ui/react-tooltip`. All are from the already-adopted Radix family, and all three have a concrete consumer in this epic (tray, page tabs, disabled-reason tooltips). Nothing is installed during planning.

## 21. Dashboard → Home adoption

### 21.1 What the data supports today

| Mockup concept | Data available now? | Disposition |
|---|---|---|
| Open tickets count | Yes — `metrics['tickets']` | **Ship**, as a figure in a section, **not a KPI card** (L10) |
| Active projects count | Yes — `metrics['projects']`, already `Project::visibleTo` | **Ship**, same treatment |
| Time this month + unbilled | Yes — `metrics['time']` | **Ship**, mono numerals |
| Outstanding invoices | Yes — `metrics['billing']` | **Ship**, same treatment |
| Recent tickets list | Yes — `recentTickets[]` (6) | **Ship** as a Direction D list with `Status` glyph+label |
| Quick actions | Yes — `quickActions[]` | **Ship**, secondary buttons; at most one ink primary in the region |
| CRM summary | Yes — `crmSummary`, `crm.manage` | **Ship**, relabelled **Directory** to match the IA |
| Running timers on Home | Yes — already in `TimerProvider` | **Ship** if it costs nothing beyond reading existing context |
| "My work" queue | **No** — no assigned-work query | Near-term (Tasks overhaul) |
| Decisions / approvals awaiting me | **No** — no Approval entity at all | **Future.** Must not be faked |
| Project health signals | **No** — health is a derived signal with no implementation | **Future.** Must not be faked |
| Helpdesk intelligence, SLA | **No** | **Future.** Must not be faked |
| Watch lists, saved views | **No** | Future |
| Notifications | **No** | Future |

### 21.2 Scope

Home is the **first intentional Direction D surface** and must demonstrate the language honestly: `PageFrame width="grid"`, `PageHeader` with overline + title + summary, `Section` titles over `rule-strong`, structure over boxes (the four metrics become a figure row, **not** four cards), `Status` glyph+label on the ticket list, mono numerals, and real `EmptyState` copy distinguishing truly-empty from filtered-empty.

**Route and DTO changes are minimal and honest:**

- `/dashboard` keeps its URL and route name in this epic. Only the **label** becomes "Home". Renaming the route touches `NavigationBuilder`, Wayfinder output, the root redirect, `DashboardController`, four Pest tests and two Playwright specs for no user-visible benefit — that rename belongs with a later Home product slice.
- `DashboardController`'s four props are **unchanged in shape**. No new query, no new count, no speculative prop. If a section cannot be honestly filled from these four props, it does not appear.
- The `crmSummary` prop keeps its key (changing it is a needless breaking change); only the rendered heading becomes "Directory".

## 22. Accessibility and focus behaviour

### 22.1 Baseline requirements (Direction D §14)

- `:focus-visible` = 2px `focus` outline, 2px offset, inheriting the element's radius; inset (no offset) inside dense rows. Focus styles are never removed.
- **Skip link** as the first focusable element in both shells — this does not exist today and is new work.
- Landmarks: `nav` "Workspaces" (rail), `nav` "{Workspace} views" (drawer), `header` (utility bar), `main`, optional `aside`.
- `aria-current="page"` on the selected rail item and the selected drawer item — also new (`NavigationLink` sets none today).
- Primary navigation stays plain links in Tab order. **No roving tabindex, no arrow-key requirement** (L11). Arrow keys exist only inside genuine composite widgets — Radix `DropdownMenu` (account menu, view switcher) and Radix `Tabs` (page tabs) — which already carry the matching ARIA.
- Overlay focus management: overlay drawer moves focus in on open and returns it to the toggle on close; popovers/menus/tray return focus to the trigger on `Esc`; dialogs trap focus and close on `Esc` unless an action is in flight.

### 22.2 Q2 — focus on Inertia navigation

Direction D §14.2 proposes moving focus to the page `h1` via `tabindex="-1"`, and explicitly flags it as **new behaviour to be validated** against the existing EPIC-011 keyboard/accessibility expectations.

This should not be adopted on assumption. Focusing `h1` on every visit is a contested pattern: it can be helpful for screen-reader users and actively harmful for sighted keyboard users, who lose their place; and moving focus into a non-interactive element has inconsistent announcement behaviour across AT.

**Spike S2 (WP0)** — a small real-browser characterisation, not a research project. It establishes:

1. **What happens today** on an Inertia visit with no focus management — where focus lands (almost certainly the clicked rail link, which persists across the visit because the shell is persistent), and what is announced.
2. Three candidates measured against the contract:
   - **(a)** no focus move + a polite live region announcing the new page title;
   - **(b)** focus moved to `main` (`tabindex="-1"`), which is a landmark and announces its label;
   - **(c)** focus moved to the page `h1` as §14.2 proposes.
3. Checks for each: is the location change understandable to a screen-reader user · does the skip link still work · is focus destructive for a sighted keyboard user (does Tab resume somewhere sensible) · does back/forward behave.

**Provisional preference, to be confirmed or overturned by S2 evidence:** **(a)** — no focus move, plus a polite announcement — because the persistent shell means focus remains on the rail link the user just activated, which is a coherent and non-destructive resting place, and the skip link already provides the deliberate jump into content. Whatever S2 concludes is recorded in the epic as an amendment and implemented in WP4. **The contract is locked; the mechanism is not.**

### 22.3 Deliberately deferred

Exhaustive screen-reader matrix (NVDA/JAWS/VoiceOver), real-device mobile and cross-browser validation stay in [FINAL HARDENING](../product/product-roadmap.md#final-hardening), consistent with EPIC-011E's accepted deferral. This epic does a real-browser keyboard walkthrough plus one NVDA smoke pass on the shell, matching the EPIC-011E precedent.

## 23. Audience, presentation, and the shell seam

### 23.1 L17 — audience and presentation are separate concerns

Direction D's rollout is unchanged: operators get the rail + contextual drawer, the future customer experience gets the calmer top bar, this epic builds **only** the operator shell, and until the customer shell exists customer users keep using the capability-filtered operator shell. No shell-switching UI is in scope (L18).

What this epic must **not** do is bake those pairings in as though geometry were a permission.

| Concern | Decided by | Determines |
|---|---|---|
| **Authorization / audience** | Capabilities and relationships, server-side | Which workspaces exist for the actor · which routes, actions and data they may reach · which contextual entries appear |
| **Shell presentation** | A presentation family, server-resolved | *Only* how that already-authorized model is projected |

Two presentation families, named conceptually so the architecture does not read as role-coupled:

| Family | Geometry | Optimised for |
|---|---|---|
| **Operational** | Rail + contextual secondary navigation | Frequent, complex work |
| **Focused** | Top navigation, context projected through menus, tabs and view selectors | Lighter participation |

**Default pairing — a default, not a semantic:** internal/operator audience → Operational; customer audience → Focused (later epic).

Longer term the owner may want both to be first-class presentation modes: a light-use operator may prefer Focused; a project-heavy customer may benefit from Operational; contractors, vendors, partners and other audiences may need either depending on how deeply they use the platform. That is a **Future possibility, not current scope** ([§31](#31-deferred-follow-on-work)).

**Therefore, do not build:** rail = operator permission · top bar = customer permission · shell choice as an authorization decision.

**Terminology.** `OperatorShell` and `CustomerShell` remain fine names when discussing the approved Direction D visual designs, and **no production component is renamed for terminology in this epic**. The requirement is the architectural separation, not the vocabulary. Where the architecture itself names a family — the `shell.presentation` prop and the `presentation` key in the navigation contract — it uses `operational` / `focused`.

### 23.2 The three seams

The smallest structure that stops a second presentation from later requiring a rewrite of every page.

1. **A presentation discriminator in shared props.** `shell: ['presentation' => 'operational']` from `HandleInertiaRequests`. One string, server-resolved from the start, always `'operational'` in this epic. Because resolution is server-side, the eventual second family is a server decision, not a client heuristic — and because the prop names a *presentation*, nothing downstream learns to treat it as a role.
2. **`AppShell` is the single shell-resolution boundary.** The 12 `Page.layout = (page) => <AppLayout>{page}</AppLayout>` lines become `<AppShell>{page}</AppShell>`. `AppShell` reads `shell.presentation` and delegates; today it returns the Operational shell unconditionally. **No other component, and no page, may branch on presentation.** Adding a Focused shell later is one branch inside one component.
3. **Pages are chrome-agnostic.** Enforced by convention and review:
   - a page must contain **no role check that chooses its chrome** — `auth.permissions` may still shape *content*, which is authorization, not presentation;
   - a page must **not import or reference** `Rail`, `Drawer`, `UtilityBar`, `Breadcrumb`, `TimerPill` or any rail/drawer component;
   - a page composes only `PageFrame`, `PageHeader`/`EntityHeader`, `Section` and shared primitives, which are shell-agnostic by construction — Direction D's "same tokens, same components, different frame" (§7) is exactly this property.

   The intended consequence: the same page content is **capable** of living inside either presentation where product UX permits.

**Capability filtering stays server-owned and presentation-independent.** The `NavigationBuilder` gate is the same regardless of which shell renders it, which is what makes the filtering tests in [§27](#27-security-and-privacy-review) meaningful for both families rather than only the one that exists.

### 23.3 Functional parity, not geometric parity

A rule for the future, recorded now so the contract is not over-fitted to the drawer:

> If both presentations eventually become selectable, they should aim for **functional access to the same authorized workspace and context actions** where appropriate — **not identical geometry**.

| | Operational | Focused |
|---|---|---|
| Path to context | workspace → rail → contextual panel | workspace → top nav → menu / tabs / view selector |

The Focused presentation **does not need to reproduce a permanent 248px drawer**, and a future Focused shell must never be judged against drawer parity. [§12.2](#122-target-shape)'s `context[].kind` exists precisely so the same authorized data can be projected into whichever affordance suits the family.

### 23.4 Presentation is one layer, not the whole experience

Direction D's customer differentiation rule stands: customer UX may be calmer and differently composed (§7). Future shell selectability must **not** collapse operator and customer product differences into "the same page with a different nav."

Audience and capabilities may still legitimately affect available information, terminology, density, actions, and internal vs customer-facing content. Shell presentation is only one layer of that experience, and choosing Operational as a customer would not, and must not, grant operator content.

### 23.5 What stays undecided

**Customer routing topology** — same routes with capability-aware pages vs a distinct namespace — remains an approved open question (Direction D §20.1, IA "Open"). None of the three seams forces it either way, so this epic does not force it either.

**Until Focused-shell adoption**, customer users continue on the Operational shell **filtered by capability** (Direction D §19 step 4). That filtering is the ordinary `NavigationBuilder` gate, proven by test ([§27](#27-security-and-privacy-review)): a customer sees Home, Helpdesk, Finance (own invoices), Pages, plus Projects/Tasks/Time where permitted, and never System or Directory.

## 24. Migration compatibility strategy

### 24.1 Principles

Direction D §2.4 is a hard constraint, not advice.

1. **Add, don't swap.** Every token slice is additive. Landing tokens never, by itself, changes what an unmigrated screen depends on in a breaking way.
2. **Keep legacy aliases** until their last consumer migrates.
3. **Map aliases only where safe** ([§8.3](#83-alias-mapping)); hold where a mapping would reduce contrast, break a control affordance or hide an element.
4. **Adoption is deliberate and per surface.** Unmigrated screens are expected to look transitional.
5. **What must not break:** readability, AA contrast, visible control boundaries, focus visibility, layout integrity, dark-mode legibility on both renderers.
6. **Define, never orphan** — the five F2 variables get real definitions ([§8.4](#84-resolving-f2--defining-the-five-orphans)).
7. **Alias retirement is tracked work.** This epic retires nothing and creates the register.
8. **Verification per slice:** `./dev check` plus a visual pass of representative unmigrated screens in both themes.

### 24.2 The alias and width register

A new section in this epic, maintained as slices land, listing for each legacy alias, legacy utility and remaining `max-w-*` page: its consumers, its current mapping, and the epic expected to retire it. It is the artefact that makes retirement trackable rather than accidental.

### 24.3 Reversibility

Each WP1 slice is independently revertible: WP1a is a mechanical rename, WP1b adds five definitions, WP1c adds token blocks and remaps a bounded alias list, WP1d swaps fonts. Reverting any one does not require reverting the others, which is what makes a bad visual outcome cheap to undo.

## 25. Test strategy

Tests-first, in the EPIC-010D/011E house style: characterize current behaviour, then assert the new contract.

### 25.1 Pest

| Area | Assertions |
|---|---|
| `NavigationBuilder` shape | Returns `currentWorkspace` + `workspaces[]`; each workspace has `key,label,icon,href,visit,isActive,context,presentation`; no `children` key; `count` always `null` |
| **Content is presentation-neutral** (L17) | No key anywhere under `context` is named `drawer`, `rail` or `panel`; `context[].kind ∈ {views, queues, entities, saved, actions}`; `entities` and `saved` are never emitted in this epic |
| **Presentation hints carry no authority** (L17, rule 4) | Stripping the entire `presentation` key from the payload leaves the actor's authorized navigation model byte-identical — same workspaces, same `context` sections, same items, same `isActive`. Asserted for both an operator and a `user`-role actor |
| **Presentation hints are family-namespaced** (rule 5) | `presentation` contains only the `operational` key in this epic; `presentation.operational.panel ∈ {open, collapsed, null}`; an added unknown family key changes nothing the Operational shell renders |
| **Emptiness is semantic** (rule 6) | Home emits `context => []` **and** `presentation.operational.panel => null`; the two agree, and neither is inferred from the other |
| Presentation does not vary by audience | For the same route, an operator and a `user`-role actor receive the **same** `presentation` hints for any workspace both can see. Presentation is not a function of capability — only `context` is |
| Capability filtering — operator | Sees all nine workspaces including System and Directory |
| Capability filtering — `user` role | Sees Home, Projects, Tasks, Helpdesk, Time, Finance, Pages; **never** System, **never** Directory |
| No leakage | For a `user`-role actor, the serialized navigation payload contains **no** `admin/`, `operator/`, `crm/`, `organizations/` href anywhere, at any depth |
| Context entries respect sub-capabilities | Helpdesk `context` shows Queue/Reports only with `tickets.assign`; Time shows Reports only with `time.view_all`; System shows Users only with `users.view`, Roles only with `roles.view`, Pages only with `cms.edit` |
| Finance branch preserved | `billing.manage` → `billing.invoices.index`; `billing.view` only → `billing.client.invoices.index` |
| Pages transitional rule | Visible with `cms.view` and **not** `cms.edit`; absent for an operator |
| Empty workspaces omitted | A workspace with zero permitted entries does not appear |
| Active state | Exactly one workspace `isActive`; at most one `context` item `isActive`, on representative routes across both renderers |
| Guests | Still returns an empty payload |
| Shared Inertia props | `shell.presentation === 'operational'` (a presentation family, never a role name); `auth.user.avatar.initials` present, `avatar.url` null; **no** `auth.roles`, no organization list, no model beyond id/name/email/avatar |
| Account-menu boundary | The navigation payload contains **no** account-menu items; no administrative href is reachable from anything the account menu renders |
| Blade shell data | The Blade shell composer supplies the same payload as the Inertia prop for the same actor and route (one builder, asserted equal) |
| Blade shell renders | An authenticated Blade page (`tickets.index`) renders the rail, the utility bar and the skip link, and does **not** render a "Manage" heading |
| Home DTO minimality | `dashboard` still ships exactly `metrics`, `recentTickets`, `quickActions`, `crmSummary` — no new props |
| Coexistence unchanged | `InertiaFoundationTest`'s Blade-document assertions still pass |

### 25.2 Vitest / RTL

| Area | Assertions |
|---|---|
| `Rail` | One link per workspace; `aria-current="page"` on the active one only; `<nav aria-label="Workspaces">`; links are `<a href>` with **no** `tabindex` and **no** arrow-key handlers (the L11 guard) |
| `Drawer` — docked | Sections and items render; active item has `aria-current="page"`; collapse button has `aria-expanded="true"` |
| `Drawer` — collapsed | Not rendered; the rail toggle appears; the `ViewSwitcher` appears in the breadcrumb |
| `Drawer` — overlay | Focus moves into the drawer on open; `Esc` closes and returns focus to the toggle; outside click closes; navigation closes; **no scrim** element |
| Drawer state | Toggling writes the workspace key to `localStorage['shell.operational.panel']`; a corrupt or throwing `localStorage` falls back to the server default without throwing |
| **Drawer projects `context`, it does not define it** | `Drawer` renders from the workspace's `context` sections alone; given identical `context` with `presentation` omitted it still renders every section and item, defaulting only its open/collapsed state |
| **Pages do not branch on presentation** (§23.2) | A lint/static check asserts no file under `pages/` imports `Rail`, `Drawer`, `UtilityBar`, `Breadcrumb` or `TimerPill`, and that no page reads `shell.presentation` |
| `SkipLink` | First focusable element; targets `#main-content`; visible on focus |
| `AccountMenu` | Contains Profile, Security & MFA, Connected accounts, Sessions, Appearance, Notifications (disabled), Sign out. **Asserts absence** of Users, Roles, Organizations, CMS Pages, Ticket Queue, Time Reports and any "Manage" label |
| Appearance control | A `radiogroup` with Light and Dark only; selecting one flips `data-theme` and writes `localStorage` |
| `AccountTrigger` | A `<button>` with `aria-haspopup="menu"`, `aria-expanded`, name "Account menu: {name}"; renders a circular `Avatar` inside |
| `BrandMark` | Renders inline SVG; two instances on one page produce **distinct** gradient ids and no duplicate `id` attributes; carries the `brand-mark` class |
| `TimerPill` — zero | Ghost "Start timer"; opens the tray to the empty state |
| `TimerPill` — one | Live dot, mono elapsed, context label, Stop labelled with the context |
| `TimerPill` — many | Shows the **most recently started**; `+N` badge; pill body opens the tray; Stop acts on the shown timer only |
| `TimerPill` — pending | "Starting…"/"Stopping…", no elapsed, disabled |
| `TimerPill` — failed | Last confirmed state retained; danger message; retry offered for stop, **not** for start |
| `TimerTray` | Rows newest-first; description edit calls `updateDescription` and shows pending/error; `Esc` returns focus to the pill; **no** Stop-all control, no "Today N logged", no "Start another…" search |
| Timer localisation | Advancing fake timers by 1s re-renders the pill and does **not** re-render a sentinel mounted in the rail (the EPIC-011D regression guard) |
| `PageFrame` | Each width variant applies the documented constraint; `canvas` applies no `max-w` |
| `Status` | Renders glyph **and** text label; the label is never the only difference between two states; colour is never the sole signal |
| `ProgressBar` / `Meter` | `aria-valuetext` includes the expected value where supplied; fill never uses the `live`/cyan token |
| Home | Renders from the four existing props; renders **nothing** for approvals, project health or Helpdesk intelligence; four metrics are not cards |
| Responsive structure | Where jsdom is meaningful: the S nav sheet exposes workspaces + current drawer content; the pill shows the narrow form |

Existing `app-layout.test.tsx` is rewritten rather than deleted — its "Manage" assertions **invert** into absence assertions, which is the clearest possible record of the boundary change.

### 25.3 Playwright

Real-browser flows, semantics over pixels. Chromium, matching the current config.

| # | Flow |
|---|---|
| 1 | Cold load in light and in dark: **no theme flash and no drawer layout shift**, on an Inertia page and a Blade page |
| 2 | Operator rail navigation across all permitted workspaces; correct `aria-current`; breadcrumb ends with the current page |
| 3 | Drawer docked → collapsed → reload: state persists per workspace; switching workspaces applies the other workspace's own state |
| 4 | Drawer overlay at L: opens, traps nothing but returns focus to the toggle on `Esc`, closes on outside click and on navigation; pinning docks and is remembered |
| 5 | Inertia→Inertia navigation: shell persists, timer state survives, focus behaviour matches the S2 decision |
| 6 | **React → Blade → React continuity**: rail, utility bar, theme, drawer state and timer state all survive both document transitions (extends today's `inertia-coexistence.spec.ts`) |
| 7 | Account menu: opens from the rail trigger, contains the personal items, **contains no administrative item**, Appearance flips the theme, Sign out posts |
| 8 | Keyboard: skip link is the first Tab stop and lands in `main`; rail and drawer are reachable by plain Tab; `Esc` closes each overlay; focus is visible throughout |
| 9 | Multiple timers: start two, verify the pill shows the newest with `+1`, stop one from the tray, navigate Inertia and Blade, confirm state reconciles from the server |
| 10 | 390px narrow shell: rail becomes the top bar, nav sheet exposes workspaces + drawer content, timer pill uses the narrow form, targets ≥ 44px |
| 11 | Wide canvas: the board at ≥ 1360 reclaims the viewport with the drawer collapsed |
| 12 | **Customer capability filtering while on the operator shell**: a `user`-role actor sees no System and no Directory rail item, and direct navigation to `admin/users` still 403s (proving visibility ≠ authorization) |

**Visual snapshots.** Screenshot tests are **not** the foundation — they are brittle against a design system in motion. Proposed separately, and only if the team wants them: **two** targeted snapshots, shell-only (rail + drawer + utility bar, with the page body masked), one per theme, at XL. They guard shell chrome regressions without coupling to page content. This is a proposal, not a requirement of the epic.

### 25.4 Legacy-compatibility screen matrix

Checked in both **light and dark**, on both renderers, after WP1c and again after WP2. The purpose is to prove the compatibility aliases work — **not** to make these screens look Direction D-complete.

| # | Surface | Route | Why chosen |
|---|---|---|---|
| 1 | React form | `projects/create` | Inputs, labels, validation, native select — the densest legacy React form |
| 2 | React dense table/list | `tasks/index` | Rows, badges, tabs, pagination |
| 3 | React project surface | `projects/board` | Board columns, cards, drag affordances, `--surface-*` consumers |
| 4 | React account surface | `profile/show` | The largest React page; 2FA, sessions, social sections |
| 5 | Blade helpdesk (customer) | `tickets/show` | Internal-note treatment, attachments, status/priority badges with hard-coded colours |
| 6 | Blade helpdesk (operator) | `operator/tickets/index` | The densest Blade table; bulk controls; queue filters |
| 7 | Blade admin/system | `admin/roles/edit` | Permission checkbox matrix — the most `border-base`-dependent screen |
| 8 | Blade finance | `billing/invoices/edit` | Line-item form, money alignment, `--surface-input` consumers |
| 9 | Blade directory | `crm/companies/show` | Detail layout with `--surface-elevated` / `--surface-muted` (two F2 orphans) |
| 10 | Auth page | `login` | Confirms `AuthLayout` and the brand mark survive the token and font change |

Each check records: text/background contrast holds at AA · control boundaries remain visible · focus remains visible · no overlap, clipping or collapsed region · dark mode legible. Screens 5–9 specifically exercise the fifty F2 call sites.

## 26. Performance and bundle considerations

| Risk | Mitigation |
|---|---|
| **Per-second global re-render** (the EPIC-011D lesson) | The tick interval lives inside `TimerPill`/`TimerTray` only. Guarded by a Vitest assertion that a rail-mounted sentinel does not re-render on tick ([§25.2](#252-vitest--rtl)) |
| Surface-specific deps leaking into the shell | `chart.js` + `chartjs-plugin-dragdata` (allocation) and `@dnd-kit/*` (board) must stay out of the shell chunk. Verified by inspecting the build output after WP4, not assumed. The existing per-page `import.meta.glob` split already achieves this — the check is that the shell does not accidentally import them |
| Duplicated navigation state | There is exactly one navigation source (the server prop) and no client store. Drawer *state* is the only client-owned navigation-adjacent value, and it lives in one hook |
| Duplicated shell implementations | React and Blade share the payload, the tokens, the pre-paint bootstrap and the brand mark. They do not share components — Blade renders its own markup, which is the accepted cost of coexistence |
| Font payload | ≤ 180 KB latin subset budget with an explicit fallback plan ([§9](#9-typography-and-font-delivery)) |
| Shell JS growth | Three new Radix packages, each with a real consumer. Popover and Tooltip are small; Tabs is used by page tabs. Measured after WP2 |
| Icon bundle | `lucide-react` is tree-shaken per import. The server sends icon **keys**, so React must map them through an explicit static object (a lookup table of named imports), **not** a dynamic `lucide[name]` access, which would defeat tree-shaking. This is a concrete implementation requirement |
| Re-render on Inertia visit | The shell is persistent; only the page subtree changes. Preserved by keeping `AppShell` above the page in the `layout` property, exactly as today |

Baseline measurements (shell chunk size, first-paint, font payload) are captured in **WP0** and re-measured in **WP8**, so the epic can state its own cost rather than guess it.

## 27. Security and privacy review

**Navigation visibility is not authorization.** Every route keeps its middleware and policy. The shell only decides what to *draw*.

| Control | How it is proven |
|---|---|
| No administrative leakage | Pest: a `user`-role actor's serialized navigation payload contains no `admin/`, `operator/`, `crm/` or `organizations/` href at any depth |
| No operator-workspace leakage | Pest: System and Directory are absent for a `user`-role actor; Helpdesk Queue/Reports absent without `tickets.assign`; Time Reports absent without `time.view_all` |
| Visibility ≠ authorization | Playwright #12: a customer with no System rail item still receives 403 when navigating directly to `admin/users` |
| Account data minimisation | Shared props carry `id`, `name`, `email`, and `avatar.initials` only. **No** roles, **no** organizations, **no** model serialization. Asserted by the existing `InertiaFoundationTest` `missing()` assertions, extended to the new keys |
| Account menu stays personal | Pest + Vitest assert the absence of every administrative entry; the navigation contract structurally excludes account items ([§12.4](#124-account-menu-boundary-in-the-contract)) |
| No new server surface | This epic adds **no route, no endpoint, no migration, no permission**. The drawer preference is client-side ([§15](#15-drawer-state-and-persistence)), which is also why it carries no server-side privacy question |
| Capability filtering for customers on the operator shell | Playwright #12 plus the Pest filtering matrix — this is the mechanism customers rely on until the customer shell exists |
| Existing hardening preserved | EPIC-010D ticket authorization, EPIC-010B tenant scoping, EPIC-010C billed-entry locking and EPIC-011E board/checklist sequencing are untouched. The epic changes no controller, policy or query |
| Third-party request removal | Self-hosting fonts removes a per-page-view third-party request that currently discloses each authenticated user's IP and user-agent to an external host ([§9](#9-typography-and-font-delivery)) |

One deliberate note: `auth.permissions` already ships the user's full effective permission list to the client and is used by `TimerProvider`. This epic does not widen it, and does not narrow it either — narrowing is a separate change with its own consumers to audit.

## 28. Work packages

Derived from the live code. Each package is an independently shippable slice that passes `./dev check`.

### WP0 — Characterization and architecture spikes

**Status: Complete (2026-09-25) — results in [Amendment 1](#amendment-1-wp0-results-2026-09-25).**

Small and largely disposable. No production code ships except the brand-asset move.

| Item | Output |
|---|---|
| Baseline capture | Screenshots of the ten compatibility screens in both themes; shell chunk size; first-paint; current font payload |
| **Token consumer audit** | The complete register: 8 legacy utilities, the raw variable families, the shadcn aliases, the 50 F2 call sites individually inspected, the 12 `max-w-*` pages |
| **S1 — drawer persistence / no-flash spike** | Prototype `data-workspace` + localStorage + shared bootstrap; measure reflow at XL on Blade and Inertia, both themes, cold and hard reload. Confirms [§15.3](#153-recommended-architecture) or falls back to the cookie |
| **S2 — Inertia focus characterization** | Real-browser + NVDA smoke comparison of "no move + announcement" vs `main` vs `h1`. Produces the locked WP4 target ([§22.2](#222-q2--focus-on-inertia-navigation)) |
| **S3 — font decision (gate G1)** | Build the latin subset; measure against the 180 KB budget; render the two densest Blade screens for clipping/truncation |
| **S4 — brand SVG consumption check** | Verify id-scoping + `stop-color` override produces solid ink in light and the cyan fade in dark, at 28px, with two marks on one page |
| **Brand asset move (gate G2)** | `git mv` both SVGs into `src/resources/images/brand/` to match the canonical contract |
| **Current → target nav mapping** | Confirm the [§11.1 matrix](#111-current--target-matrix) against `route:list`; settle the Pages question (gate G3) |

**Exit:** S1–S4 answered with evidence; G1–G3 decided; the register exists; the epic is amended with any decision that differs from the recommendation here.

**Exit met.** S1–S4 answered in real Chromium; G1–G3 decided; the token, navigation, performance, responsive and compatibility registers exist; fourteen corrections recorded in [A1.16](#a116-plan-corrections-applied-by-this-amendment). No durable test was added — [§28 WP0](#wp0--characterization-and-architecture-spikes) assigns none, and the two gaps found are handed to WP3 and WP6 ([A1.14](#a114-tests--deliberately-none-added-and-why)).

### WP1 — Semantic tokens and typography

Four independently revertible slices.

- **WP1a — Complete (2026-09-25) — results in [Amendment 2](#amendment-2-wp1a-results-2026-09-25).** Rename the thirteen legacy utilities to a `legacy-` prefix; values unchanged. Resolves F1.
- **WP1b — Complete (2026-09-25) — results in [Amendment 3](#amendment-3-wp1b-results-2026-09-25).** Define the six orphan variables (A1.3's `--accent-success` included). Resolves F2.
- **WP1c — Complete (2026-09-25) — results in [Amendment 4](#amendment-4-wp1c-results-2026-09-25).** Add the Direction D token blocks (light + dark) via `@theme inline` on the existing `data-theme` contract; apply the safe alias remappings; **hold `primary`**.
- **WP1d** — self-host the three families per G1; delete the bunny.net `<link>` and `preconnect` from both root views; add the `@font-face` block and preloads.

**Exit:** `./dev check` green after each slice; the ten-screen compatibility matrix passes in both themes; no Direction D component exists yet.

### WP2 — Shared primitives

- Restyle `Button` (ink primary) **together with** the `primary`-alias flip, as one reviewable change with its own visual pass.
- Restyle `Input`, `Textarea`, `Label`, `DropdownMenu`, `Dialog`/`DialogShell`, `ConfirmationDialog`, `FormDialog`, `Alert`, `Pagination`.
- Rework `Progress` → `ProgressBar`/`Meter`; `SectionPanel` → `Section`; `PageHeader`.
- Add `Avatar`, `Status`, `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip`, `Popover` (three new Radix deps).
- Unit tests for semantics: roles, `aria-current`, `aria-valuetext`, glyph+label never colour-alone.
- **No page migrations for aesthetics.**

**Exit:** primitives pass semantics tests; the compatibility matrix re-passes; no page body restructured.

### WP3 — Navigation contract

- Reshape `NavigationBuilder` to [§12.2](#122-target-shape): workspaces, presentation-neutral `context` sections with their `kind` projection hints, family-namespaced `presentation` hints, icon keys, `currentWorkspace`. The `context`/`presentation` separation (L17) is a WP3 design requirement, not a later refactor.
- Add the `shell` and `auth.user.avatar` shared props.
- Add the Blade view composer; remove the `@php` builder call from the view layer.
- Retire the "Manage" group from the payload.
- The full Pest matrix from [§25.1](#251-pest), written first.

**Exit:** both renderers consume one payload; every capability-filtering and leakage test passes; no route or permission changed.

### WP4 — React operator shell

- `AppShell` + `OperatorShell`: `Rail`, `Drawer` (docked/collapsed/overlay), `UtilityBar`, `Breadcrumb`, `ViewSwitcher`.
- `SkipLink`, landmarks, `aria-current`, focus-visible treatment, overlay focus return, `Esc`, `Ctrl+\`.
- `AccountTrigger` + `AccountMenu` (personal-only, Appearance control absorbing the theme toggle).
- `BrandMark`; regenerate the favicon from the canonical compact asset.
- Drawer persistence per S1 (Operational-panel state only, [§15](#15-drawer-state-and-persistence)); Inertia focus behaviour per S2.
- Width classes XL/L/M/S in CSS.
- Flip all 12 `Page.layout` lines to `AppShell`; delete the old header, footer and `NavigationLink`.
- **Preserve the seam correctly** ([§23.2](#232-the-three-seams)): `AppShell` is the only component reading `shell.presentation`; `Drawer` projects the workspace's `context` rather than owning it; no page branches on presentation or imports rail/drawer components. No Focused shell and no shell preference (L18).

**Exit:** the shell is live for every Inertia page; page bodies unchanged; the seam assertions pass; Vitest and the keyboard walkthrough pass.

### WP5 — Blade parity

- New shell partials (rail, simplified drawer, utility bar, account menu, brand mark, bootstrap).
- `layouts/app.blade.php` and `views/app.blade.php` both use the shared pre-paint bootstrap.
- Delete `partials/nav.blade.php` and the authenticated footer.
- Mixed-navigation Pest and Playwright coverage (#6).

**Exit:** a Blade page and an Inertia page are visibly the same product; theme and drawer state survive both transitions.

### WP6 — Global timer shell integration

- `TimerPill`, `TimerTray` (Radix Popover), `TimerControl`; delete `RunningTimerBar` after tests pass.
- Rework `timer-overlay.js` into the Blade pill/tray; delete the decorative `PALETTE`; preserve the `timerStarted`/`timerStopped` events.
- `TimerProvider` **unchanged**; no backend change.
- The full timer matrix from [§25.2](#252-vitest--rtl) plus Playwright #9, including the tick-localisation guard.

**Exit:** zero/one/many states correct on both renderers; reconciliation behaviour unchanged; no NEXT feature present.

### WP7 — Page frames and Home

- `PageFrame` (canvas/grid/reading), `EntityHeader`, `Strata`.
- Dashboard → **Home**: label, Direction D surface language, honest data only, no new props, route unchanged.
- Convert `projects/board` to `PageFrame width="canvas"` (a genuine wide-canvas win).
- Leave the other ten pages' containers alone.

**Exit:** Home is a real Direction D surface; nothing fabricated; other pages still work.

### WP8 — Hardening and verification

- Re-run the ten-screen compatibility matrix, both themes, both renderers.
- Full responsive pass at XL/L/M/S including 390px.
- Keyboard walkthrough + one NVDA smoke pass on the shell.
- Mixed Blade/Inertia continuity.
- Re-measure shell chunk, first-paint, font payload against the WP0 baseline.
- Regression audit across the existing Pest, Vitest and Playwright suites.
- Documentation closeout: the alias/width register, the CI handoff note, deferred-work list, epic status.

**Exit:** [§29](#29-exit-criteria) satisfied.

## 29. Exit criteria

1. Direction D semantic tokens exist for light and dark on the existing `data-theme` no-flash contract, exposed through `@theme inline`, with no component referencing a hex value.
2. Legacy aliases, the raw variable families and the renamed legacy utilities remain defined; the five F2 orphans are defined; the alias/width register exists.
3. The ten-screen compatibility matrix passes in both themes on both renderers: AA contrast, visible control boundaries, visible focus, intact layout, legible dark mode. No intentional regression shipped.
4. IBM Plex Sans, IBM Plex Mono and Newsreader load per the G1 decision, within the payload budget, with no clipping or truncation on the dense legacy screens.
5. `NavigationBuilder` emits the workspace contract; both renderers consume the same payload; the "Manage" group is gone; every capability-filtering and leakage test passes.
6. The React operator shell is live for all 12 Inertia pages: rail, drawer (docked/collapsed/overlay), utility bar, breadcrumb, skip link, landmarks, `aria-current`, per-workspace drawer persistence with no layout flash.
7. Blade renders the same rail, utility bar, tokens, brand mark, skip link and navigation data; a simplified drawer is acceptable; theme and drawer state survive React→Blade→React.
8. The account menu is personal-only in both renderers, with absence of administrative items asserted by test; Appearance replaces the standalone theme toggle.
9. `TimerPill` + `TimerTray` deliver exactly the Foundation scope on both renderers; `TimerProvider` reconciliation is unchanged; no backend change; no NEXT feature present; the tick stays localised and is guarded by test.
10. `PageFrame` exists with all three width classes; Home adopts Direction D using only existing DTO data; no fabricated approvals, project health or Helpdesk intelligence.
11. The shell seam exists (L17, [§23.2](#232-the-three-seams)): the `shell.presentation` prop, `AppShell` as the single resolution boundary, no page branching on presentation or importing rail/drawer components. The navigation contract's `context` is presentation-neutral and its `presentation` hints are family-namespaced. No Focused shell and **no persisted shell preference** is implemented (L18).
12. `./dev check` is green; the Playwright suite including the new shell flows is green.
13. No new route, endpoint, migration, permission, Docker or CI file was added.
14. The CI handoff note ([§30](#30-ci-handoff)) is recorded.

## 30. CI handoff

**CI is not implemented in this epic.** No GitHub Actions or other CI files are created here.

The [Product Roadmap](../product/product-roadmap.md#lightweight-ci-baseline) places the lightweight CI baseline **after** the shell/Home foundation and **required before the Tasks overhaul begins**. Direction D §19 step 8 says the same.

> **HANDOFF — do not skip.** When this epic reaches Verified, the **next** planned work is the *Lightweight CI baseline*, not the Tasks overhaul. Its scope is already fixed by the roadmap: clean checkout, clean environment, full Pest against MariaDB, frontend (Wayfinder generation, typecheck, lint, format check, unit tests, production build), the critical Playwright suite, and a blocking pass/fail gate. `./dev check` defines the checks; CI proves they pass from a clean checkout. Build matrices, deployment, release promotion and production orchestration are explicitly **not** in its scope.

Two notes this epic hands forward: the Playwright suite grows by roughly twelve shell flows, which affects CI runtime budgeting; and the production build now emits self-hosted font assets, which the clean-checkout build must produce correctly.

## 31. Deferred follow-on work

| Deferred | Goes to |
|---|---|
| Lightweight CI baseline | Next roadmap item ([§30](#30-ci-handoff)) |
| Tasks overhaul (D2/D9 patterns), `DataTable` conventions, `BulkBar`, `FilterBar`, `Chip`/`FilterChip`, `J/K/X/E/T` shortcuts, Tasks peek inspector | Tasks overhaul |
| Timer NEXT items: Stop all (needs an endpoint), long-running warning + threshold, global quick-start search, "Today N logged" | Timer UX improvement |
| Projects expansion (D3), `StagePath`, `Priority`, project health, list view, Monitoring | Projects UX expansion |
| `CustomerShell`, `TopNav`, customer routing topology, multi-organization switcher | Customer product work |
| **Shell presentation parity / unification** — see [§31.1](#311-shell-presentation-parity--unification-future) | **Future** (own project; explicitly **not** EPIC-013) |
| Helpdesk queue inspector, Incidents, Knowledge, SLA | Helpdesk MVP |
| Directory People/Organizations consolidation, Relationships, classifications | Directory |
| Finance Retainers, Rates, Budgets, Billing Account | Finance |
| Viewer-facing CMS placement (retires the transitional Pages item) | Knowledge/CMS evolution |
| Profile split into Profile / Security / Connected / Sessions pages | A later account slice ([§17.2](#172-scope-decisions)) |
| "System" theme (a third `data-theme`) | NEXT, after owner confirmation |
| Density preference (Compact/Comfortable) | Future |
| Command palette, global search, notifications | Future platform capabilities |
| Alias retirement for each legacy alias | The epic migrating its last consumer |
| The ten remaining `max-w-*` pages | Their product epics |
| Exhaustive screen-reader matrix, real-device, cross-browser | FINAL HARDENING |
| Two optional shell visual snapshots | Proposed separately ([§25.3](#253-playwright)) |

### 31.1 Shell presentation parity / unification (Future)

**Not part of EPIC-013.** EPIC-013 builds the Operational shell only and preserves the seam (L18). This entry exists so the direction is recorded, not scheduled — it is deliberately **not** added to the Product Roadmap as a phase.

Potential future scope, if and when actual usage demonstrates value:

- implement the **Focused** presentation (the Direction D customer shell, §7);
- evaluate **user-selectable** presentation, rather than assuming the audience→family defaults hold;
- establish **functional** parity between the Operational and Focused projections of the same authorized `context` ([§23.3](#233-functional-parity-not-geometric-parity)) — never geometric parity;
- determine sensible defaults per audience: customers, operators, contractors, vendors, partners and others;
- **learn from real use before exposing any preference** — no profile setting, admin assignment, role configuration, organization policy or switching control is built speculatively.

Entry conditions, so this is not started prematurely: the Focused shell exists and has at least one real product surface; the `context`/`presentation` separation has survived a second consumer; and there is observed demand for a pairing other than the default.

## 32. Risks and rollback

| # | Risk | Likelihood | Impact | Mitigation | Rollback |
|---|---|---|---|---|---|
| R1 | Token layer degrades unmigrated Blade screens (567 utility sites + 900 raw-variable sites) | Medium | High | F1 resolved by a mechanical rename before any token lands; F2 orphans defined; `primary` held until WP2; ten-screen matrix in both themes | Revert the single WP1c commit; WP1a/WP1b stand alone |
| R2 | Ink primary buttons make existing React pages look broken or lose affordance | Medium | Medium | The `primary` flip ships **with** the Button restyle in WP2, with its own visual pass, not silently in WP1 | Revert WP2's alias line; Button keeps legacy indigo |
| R3 | Font swap clips or truncates dense legacy Blade layouts | Medium | Medium | G1 gate in WP0 renders the two densest screens before committing; `font-display: swap`; explicit fallback stacks | Revert WP1d; the bunny.net link is one line in each root view |
| R4 | Drawer persistence flashes or shifts layout | Medium | Medium | S1 spike proves it before WP4; CSS-driven geometry; cookie is the pre-agreed fallback | Ship with the drawer always at the workspace default; persistence is additive |
| R5 | Inertia focus change regresses keyboard UX or existing EPIC-011 expectations | Medium | Medium | S2 characterization first; provisional preference is the least invasive option; existing a11y tests run against the choice | Remove the focus behaviour; the skip link alone still meets the baseline |
| R6 | Blade parity scope creeps toward redesigning Blade page bodies | Medium | High | [§14.1](#141-what-parity-means-here) states the minimum explicitly; page bodies are a stated non-goal | Cut back to rail + utility bar + tokens only |
| R7 | Timer rework regresses the EPIC-011D reconciliation guarantees | Low | High | `TimerProvider` is not modified; only presentation changes; the full existing timer test suite must stay green | Restore `RunningTimerBar`; it is deleted only after the replacement passes |
| R8 | Shell flip breaks all 12 pages at once | Low | High | Page bodies untouched (they keep their own containers); the flip is 12 identical one-line changes | Revert WP4; the previous `AppLayout` implementation is one commit back |
| R9 | Navigation reshape leaks an administrative entry | Low | High | Pest leakage tests written **before** the reshape; Playwright #12 proves visibility ≠ authorization | Revert WP3; no route or policy was touched, so authorization is unaffected regardless |
| R10 | Timer tick re-renders the whole shell | Low | High | Interval confined to the pill/tray; Vitest sentinel guard | Isolate with `memo`; the guard catches it before merge |
| R11 | Scope creep into Tasks/Projects/Helpdesk product work | Medium | High | [§5](#5-non-goals) is explicit; WP7 converts exactly two pages | Cut WP7 back to Home only |
| R12 | Brand SVG theming proves fragile | Low | Low | S4 verifies before WP4; build-time generation from the canonical source is the pre-agreed fallback | Ship the mark dark-mode-only (cyan) until resolved |

**Overall rollback posture.** No migration, no route change, no permission change, no endpoint change. Every risk above is reverted by reverting commits, with no data consequence — which is the main reason this epic can move quickly.

**Delivery — dedicated implementation branch recommended.** Derived from blast radius, not habit: the token layer touches 1,500+ call sites across 45 Blade views, WP1a alone rewrites class names in every Blade file, WP4 flips all 12 React pages at once, and WP5 deletes the Blade nav partial. A bad visual outcome is likely to surface only after several slices have landed, so `main` must stay releasable throughout. Suggested `feature/epic-013-direction-d-shell`, with each work package a reviewable commit. This is a deliberate exception to the normal preference against a branch per ordinary change.

## 33. Open questions and decision gates

### 33.1 Decision gates inside this epic (recommendation made; settled in WP0)

| Gate | Question | Recommendation | Blocks | **Outcome (WP0, 2026-09-25)** |
|---|---|---|---|---|
| **G1** | Font delivery: self-host vs package vs external | **Self-host `woff2` subsets**, ≤ 180 KB latin budget ([§9](#9-typography-and-font-delivery)) | WP1d only | **CLOSED — confirmed.** Self-host, latin subsets, **static** Newsreader 400/500: **7 files, 143.4 KB**. Vite fingerprinting verified; density gate passed with 0 px change. See [A1.4](#a14-g1--self-hosted-woff2-confirmed-with-the-budget-arithmetic-corrected) |
| **G2** | Brand assets live at `src/resources/images/`; the contract says `…/images/brand/` | **`git mv` into `brand/`** to match the canonical contract ([§10.1](#101-canonical-assets-and-one-path-discrepancy)) | WP4 only | **CLOSED — done.** Both files moved; id-scoping and stop-override proven with four marks on one page. `BrandMark` must also strip `vector-effect`. See [A1.5](#a15-g2--assets-moved-consumption-proven-one-defect-found-in-the-committed-full-logo) |
| **G3** | Where viewer-facing CMS Pages (`cms.view`) lives under the eight-workspace IA | **Transitional ninth rail item**, gated `cms.view AND NOT cms.edit`, retired by the Knowledge/CMS epic ([§11.3](#113-the-viewer-facing-cms-problem)) | WP3 only | **CLOSED — confirmed** across seven actor profiles; label should read "Resources". See [A1.6](#a16-g3--the-transitional-pages-item-is-confirmed-across-seven-actor-profiles) |
| **S1** | Drawer persistence mechanism | **Server-stamped workspace + localStorage + shared pre-paint bootstrap**; cookie is the fallback ([§15](#15-drawer-state-and-persistence)) | WP4 | **CLOSED — confirmed unchanged.** No flash in any case, including React↔Blade; **cookie fallback not taken**. See [A1.7](#a17-s1--drawer-persistence-confirmed-unchanged-the-cookie-is-not-needed) |
| **S2** | Focus target after an Inertia visit | **Provisionally: no focus move + polite announcement**; decided by evidence ([§22.2](#222-q2--focus-on-inertia-navigation)) | WP4 | **CLOSED — refined.** Announce always; repair focus to `main` **only when the visit destroyed it**. Plain (a) would leave a live in-page-control defect. See [A1.8](#a18-s2--the-provisional-preference-is-overturned-in-part-a-hybrid-is-locked) |

None of these blocks the start of the epic. **WP0 closed all five on 2026-09-25** ([Amendment 1](#amendment-1-wp0-results-2026-09-25)).

### 33.2 Approved open questions that this epic does not need answered

From Direction D §20 and the IA. Listed so they are not accidentally reopened, and explicitly **not** owner asks:

1. **Customer routing topology** — same routes with capability-aware pages vs a distinct namespace. The [§23](#23-audience-presentation-and-the-shell-seam) seam works either way.
2. **"System" theme option** — NEXT; adding a third `data-theme` needs confirmation before it touches the no-flash contract.
3. **Multi-organization customer switcher** — whether, where and how.
4. **Long-running timer threshold** — the value and whether it is a setting. The warning itself is NEXT.
5. **Directory data model** — Person entity, `crm_companies`/`organizations` consolidation, Relationship cardinality. Directory epic.
6. **`time.view_own`** — give it enforcement meaning or retire it. Not a shell question.
7. **Whether current CMS content is Knowledge or true CMS** — determines where G3's transitional item finally lands.

### 33.3 Genuine owner asks

**None.** Every question above either has a recommendation with a WP0 gate, or belongs to a later epic. Nothing blocks safe implementation of the foundation.

## 34. Implementation model guidance

Preference: Sonnet for routine, well-scoped implementation; Opus for architecture-heavy, ambiguous, cross-cutting or security-sensitive work; Fable only for a specific exceptional reason.

| WP | Model | Why |
|---|---|---|
| **WP0** | **Opus** | Spikes S1/S2 are genuinely ambiguous architecture with contested trade-offs; the token consumer audit must be exhaustive across 1,500+ call sites and a miss is expensive later |
| **WP1a** | **Sonnet** | Mechanical rename across 45 files, values unchanged, verifiable by diff |
| **WP1b** | **Sonnet** | Five definitions against a WP0-produced inspection list |
| **WP1c** | **Opus** | The highest-blast-radius change in the epic: cross-cutting, every screen affected, and the alias mapping requires contrast judgement per token |
| **WP1d** | **Sonnet** | Well-scoped once G1 is decided |
| **WP2** | **Sonnet** | A clear component list against an explicit spec. The `primary` flip is the one judgement call and is covered by the WP1c reasoning |
| **WP3** | **Opus** | Security-sensitive: capability filtering and leakage prevention, plus a contract consumed by two renderers |
| **WP4** | **Opus** | The largest architecture-heavy package: shell composition, drawer state machine, responsive classes, focus management, flipping 12 pages |
| **WP5** | **Sonnet** | Parity against a now-settled contract; the hard decisions were made in WP3/WP4 |
| **WP6** | **Opus** | Touches the hardened EPIC-011D reconciliation model and the tick-localisation constraint; a regression here is a correctness bug, not a visual one |
| **WP7** | **Sonnet** | Frames are simple; Home's discipline is enumerated in [§21.1](#211-what-the-data-supports-today) |
| **WP8** | **Opus** | Cross-cutting verification, accessibility judgement, and the decision on whether exit criteria are genuinely met |

Six Sonnet packages, six Opus packages. Fable is not recommended for any package.

---

## Amendment 1: WP0 Results (2026-09-25)

WP0 ran on `main` at `f1476fb` (the committed plan). **The only production change it makes is the gate G2 brand-asset move** — a `git mv` of two SVGs with zero code consumers, which [§28 WP0](#wp0--characterization-and-architecture-spikes) names as a WP0 deliverable. No application, route, policy, migration, dependency, package-manifest, CSS or component file changed. Every spike was run against the live application in real Chromium and then removed.

All five gates are closed: **G1 self-hosting confirmed with measured payloads · G2 moved and its consumption strategy proven, with one correction · G3 confirmed against seven actor profiles · S1 confirmed unchanged · S2 overturned in part by evidence.** Fourteen plan corrections are recorded in [A1.16](#a116-plan-corrections-applied-by-this-amendment); the decision registers ([§28 WP0](#wp0--characterization-and-architecture-spikes), [§33.1](#331-decision-gates-inside-this-epic-recommendation-made-settled-in-wp0)) and [§10.1](#101-canonical-assets-and-one-path-discrepancy) are updated in place, and the analytical sections are left as written. **Where this amendment conflicts with the body, the amendment wins.**

Status is unchanged: EPIC-013 remains **Planned**. The status moves with the epic, not the work package.

### A1.1 Method and environment

| | |
|---|---|
| Repository state | `main` @ `f1476fb`, working tree clean at start |
| Application | Live dev stack (`portal_nginx` → `http://nginx`), MariaDB dev data |
| Browser | Chromium 1243 via Playwright 1.63, installed **into the container** at `/opt/ms-playwright`; the host WSL lacks Chromium's shared libraries. No repository or manifest change |
| Spike location | `/tmp/spike` inside the container and the session scratchpad — never the repository |
| Actor characterization | Executed inside a `DB::beginTransaction()` / `rollBack()` pair, so no role or user was persisted |

**A note on two numbers in [§4.1](#41-theme-and-css).** The consumer census conflates two different things under one row. `text-primary` appears 203 times in Blade, but **198 of those are `var(--text-primary)`**, the CSS custom property, not the utility class. The distinction is the whole of finding F1, and correcting it changes the size of WP1a by an order of magnitude (A1.2).

### A1.2 F1 — the utility-name collision is real, far smaller than planned, and already shipping a defect

**Corrected call-site census.** Counted by parsing `class` / `className` attributes and stripping variant prefixes, so `hover:text-primary` and `--text-primary` are excluded. `welcome.blade.php` is excluded throughout: it carries a self-contained inlined Tailwind v4 build that defines its own variables and shares nothing with `app.css`.

| Legacy utility | Blade | React | Total | Files |
|---|---:|---:|---:|---|
| `bg-surface` | 32 | 0 | **32** | 14 |
| `text-primary` | 6 | 10 | **16** | 8 |
| `text-secondary` | 5 | 0 | **5** | 1 |
| `bg-base` | 1 | 0 | **1** | 1 |
| `border-subtle` | 1 | 0 | **1** | 1 |
| `border-base` | 1 | 0 | **1** | 1 |
| `text-muted` | 1 | 0 | **1** | 1 |
| `shadow-theme-sm` | 1 | 0 | **1** | 1 |
| `shadow-theme-lg` | 1 | 0 | **1** | 1 |
| `bg-elevated`, `text-inverse`, `btn-accent`, `shadow-theme-md` | 0 | 0 | **0** | — |
| **Total** | **49** | **10** | **59** | — |

**WP1a is 59 call sites, not 567.** The body's "567 Blade call sites" is the `var(--*)` count and is corrected here. Further, **31 of the 49 Blade sites (63%) live in `layouts/partials/nav.blade.php` (14) and `layouts/partials/footer.blade.php` (3)** plus the ticket/admin views; `nav.blade.php` and `footer.blade.php` are **deleted by WP5** ([§14.4](#144-files)), so a third of WP1a's surface is temporary by construction.

**A live defect, found while verifying the collision.** Tailwind v4 does **not** generate a base `.text-primary` utility, because the hand-written `@layer utilities` rule already occupies that class name. It *does* generate the variant forms. The built stylesheet contains exactly:

```css
.text-primary            { color: var(--text-primary) }   /* hand-written legacy ink */
.hover\:text-primary:hover      { color: var(--primary) }  /* Tailwind, indigo accent */
.group-hover\:text-primary…     { color: var(--primary) }  /* Tailwind, indigo accent */
```

So in React, `className="text-primary"` renders **ink**, while `hover:text-primary` renders **indigo**. Confirmed in the browser on `/dashboard`: the "View all" link computes `oklch(0.21 0.034 264.665)` — exactly `--text-primary` — where `--primary` is `#4f46e5`. Same result in dark.

All ten React call sites are links (`text-primary hover:underline`) or active tabs (`border-primary text-primary`) and unambiguously intend the accent. The active-tab case renders today as an **indigo underline with ink text**, because `border-primary` has no colliding hand-written rule and resolves correctly while `text-primary` does not.

**Consequence for WP1a, which the plan does not currently account for:** renaming `.text-primary` → `.legacy-text-primary` frees the class name, so Tailwind will then generate `.text-primary { color: var(--color-primary) }` and **those ten React sites will change from ink to indigo in the same commit.** WP1a is therefore *not* "values unchanged, verifiable by diff" for React. Two options, and the second is recommended:

1. Rename and accept the ten React sites flipping to indigo — which is what they were written to mean, and arguably a fix.
2. **Recommended:** rename the legacy utilities (WP1a), and in the same slice rewrite the ten React call sites from `text-primary` to an explicit `text-[var(--primary)]` or the shadcn-intended utility, so the slice remains *intentional* rather than incidentally correcting a bug. Record the ink→indigo change as a deliberate fix in the WP1a commit message, and include `/dashboard`, `/tasks`, `/time`, `/projects` and the two auth pages in that slice's visual pass.

**The `legacy-` prefix is free.** No class beginning `legacy-` exists anywhere in the repository (Blade, React or CSS).

### A1.3 F2 — five orphans confirmed exactly, a sixth found, and one proposed mapping corrected

The body's five counts reproduce **exactly**: `--surface-base` 24, `--surface-muted` 10, `--surface-elevated` 9, `--border-muted` 4, `--surface-accent` 3 — 50 sites, all Blade, every one inspected individually.

**Confirmed mechanism.** In the browser, every element carrying one of these variables computes `background-color: rgba(0, 0, 0, 0)` and inherits its ancestor. On `/operator/tickets` the body is `#fff` in light and `oklch(13%)` in dark, and the "cards" are transparent against it.

**A sixth orphan the plan missed:** `--accent-success`, used once in React at [`components/projects/board-column.tsx:59`](../../src/resources/js/components/projects/board-column.tsx#L59) as `bg-[var(--accent-success,#22c55e)]`. It has an inline fallback so nothing is broken today, but it belongs in the register. Recommended definition: `var(--success)`, which makes it theme-aware instead of pinned to `#22c55e`.

**Mapping decisions, from the inspected call sites rather than the names:**

| Variable | Sites | What the sites actually are | Plan proposed | WP0 verdict |
|---|---:|---|---|---|
| `--surface-base` | 24 | Card/panel/table containers, **always** paired with `border-color: var(--border-base)`; sit directly on the page background | `var(--bg-base)` | **Confirmed.** Strictly appearance-preserving |
| `--border-muted` | 4 | Draft/cancelled invoice badge borders | `var(--border-subtle)` | **Confirmed** |
| `--surface-accent` | 3 | Tinted accent chips (`open` ticket status, role chips), paired with `color: var(--accent)` | `var(--surface-info)` | **Confirmed.** The only defined tinted surface that pairs legibly with `--accent` |
| `--surface-muted` | 10 | Draft/cancelled invoice badges, paired with `var(--text-muted)` | `var(--bg-surface)` | **Confirmed, with a contrast note** — see below |
| `--surface-elevated` | 9 | `<thead>` rows and the `closed` status badge — a *subtle lift above the card*, not a page surface | `var(--bg-elevated)` | **Corrected → `var(--bg-surface)`** |

**Why `--surface-elevated` is corrected.** The call sites sit *inside* the `--surface-base` cards, so the right value is one step above the card, not two. With `--surface-base` defined as `--bg-base`:

| | `--bg-base` (the card) | `--bg-surface` (recommended) | `--bg-elevated` (plan) |
|---|---|---|---|
| Light | `#fff` | `oklch(98.5%)` — a faint tint | `#fff` — **no distinction at all** |
| Dark | `oklch(13%)` | `oklch(21%)` — a subtle lift | `oklch(27.8%)` — a two-step jump that reads as a raised band |

The plan's `--bg-elevated` gives table headers *no* boundary in light and an over-strong one in dark. `--bg-surface` is the smallest definition that makes the name honest in both themes. This is a **deliberate, visible change in dark** (headers gain a boundary they lack today) and must be verified in the WP1b pass rather than slipped in — it is an improvement, not a regression, and [§8.4](#84-resolving-f2--defining-the-five-orphans) explicitly sanctions choosing per site.

**One pre-existing contrast failure to hand forward, not to fix in WP1b.** The `--surface-muted` / `--text-muted` badge pair is `gray-400` text on `gray-50` (≈2.6:1) once defined, against ≈2.8:1 on white today. Both fail AA. Defining the variable does not *create* the failure and WP1b must not be blamed for it, but the pair should be changed to `--text-secondary` when Finance adopts `Status`. Recorded for WP8 and the Finance epic.

### A1.4 G1 — self-hosted WOFF2 confirmed, with the budget arithmetic corrected

**Current payload, measured in the browser.** `/dashboard` fetches **3 files / 70.7 KB**; `/operator/tickets` fetches **4 files / 94.5 KB** — Inter latin only. **One third-party origin (`fonts.bunny.net`) is contacted on every authenticated page view**, confirming the privacy argument in [§9.3](#93-recommendation). **JetBrains Mono is declared but never fetched on either page**: no element resolves to it, so `--font-mono` is currently decorative on these surfaces.

**Measured Direction D subsets** (fontsource WOFF2, latin unless stated):

| Face | Size |
|---|---:|
| IBM Plex Sans 400 / 500 / 600 | 22.1 / 23.6 / 23.7 KB — **69.4 KB** |
| IBM Plex Sans latin-ext 400/500/600 | 15.6 / 16.1 / 16.1 KB — 47.8 KB |
| IBM Plex Mono 400 / 500 | 14.4 / 14.5 KB — **28.9 KB** |
| Newsreader variable (`wght`) | **56.7 KB** |
| Newsreader static 400 / 500 | 22.0 / 23.1 KB — **45.1 KB** |

**Two corrections to [§9.3](#93-recommendation).**

1. **Newsreader has no optical-size variable file.** `newsreader-latin-opsz-wght-normal.woff2` does not exist; only a `wght` axis is published. The body's rationale ("ships as a variable optical-size face, so one file covers 400/500") is wrong on both counts — and at 56.7 KB the variable face is **11.6 KB larger** than the two static weights it would replace. **Use static Newsreader 400 + 500.**
2. **latin + latin-ext across all three families is 202.8 KB and breaks the 180 KB budget.** Latin-only is **143.4 KB**, comfortably inside it.

**Locked G1 decision: self-host WOFF2, latin subsets, static Newsreader.**

| | |
|---|---|
| Files | Plex Sans 400/500/600 · Plex Mono 400/500 · Newsreader 400/500 — **7 files, 143.4 KB**, against a 180 KB budget |
| Location | `src/resources/fonts/`, referenced from `app.css` |
| Preload | Plex Sans 400 and 500 only |
| `font-display` | `swap` throughout |
| latin-ext | **Declared with its own `unicode-range`, not counted against the budget.** Subsetted faces download only when a latin-ext glyph is actually rendered, which for a portal carrying European company and person names is the correct behaviour and costs nothing on an all-latin page. Adding Plex Sans latin-ext (+47.8 KB, conditional) is recommended; Mono and Newsreader stay latin-only |
| Realistic first paint | Plex Sans 400+500 preloaded = **45.7 KB**, *better than today's 70.7–94.5 KB*, because Direction D drops the 700 weight and Mono/Newsreader load only when used |
| Fallback if this fails | fontsource packages at the same subset budget (unchanged) |

**Vite mechanics verified, not assumed.** A temporary `@font-face` block referencing `../fonts/*.woff2` was added to `app.css` and a production build run. Vite emitted `assets/plex-sans-400-CDDApCn2.woff2`, registered both files in `manifest.json`, and rewrote the CSS to `url(/build/assets/plex-sans-400-CDDApCn2.woff2)` — fingerprinted, immutable, same-origin, cPanel-compatible. **The spike was fully reverted**; `app.css` is byte-identical to `f1476fb` and `src/resources/fonts/` does not exist.

**Dense-screen check (the §2.4 acceptance condition).** The two densest Blade screens were identified by measurement, not assumption:

| Screen | Elements | Form controls | Table cells |
|---|---:|---:|---:|
| `/admin/roles/{id}/edit` — permission matrix | **173** | **43** (41 checkboxes) | 0 |
| `/operator/tickets` — ticket queue | **90** | 12 | 24 |

**This corrects [§9.3](#93-recommendation), which names "the operator ticket queue and the invoice form".** The invoice form is not dense (11 controls); the roles permission matrix is by a wide margin the densest Blade screen and replaces it in the gate.

Both screens were rendered with the real Plex Sans/Mono faces injected over the live pages, in both themes:

| Screen | Theme | Page height Δ | New clipping | Horizontal overflow |
|---|---|---:|---|---|
| `/admin/roles/1/edit` | light / dark | **0 px** / **0 px** | none | none |
| `/operator/tickets` | light / dark | **0 px** / **0 px** | none | none |

The single element reported as clipped on `/operator/tickets` is a pre-existing visually-hidden `View` label, present before and after. **G1 passes.**

### A1.5 G2 — assets moved; consumption proven; one defect found in the committed full logo

**The move is done.** `src/resources/images/{intechral-logo,intechral-logo-compact}.svg` → `src/resources/images/brand/`. Git records both as pure renames (`R`). Verified beforehand that **no file in the repository references either asset** — the only mentions are in `docs/`, and the design contract already cites the `brand/` path, so the move makes the filesystem match the canonical contract rather than the reverse.

**Structure confirmed, with one correction.** Both files do carry `id="title"`, `id="desc"` and a global `.s` class. But the two gradient ids **differ** (`strokeFadeFull` vs `strokeFadeCompact`), so [§10.2](#102-the-technical-problem-with-theming-them)'s claim that `stroke:url(#strokeFadeFull)` "would resolve against whichever gradient won" is wrong as stated for two *different* files. The real collisions are: duplicate `title`/`desc` ids across both files, duplicate gradient ids when the **same** file is inlined twice, and the `.s` class, which genuinely does collide across both files because an SVG `<style>` inlined in HTML is document-global.

**S4 result — the recommended approach works.** A `BrandMark`-shaped transform (strip `<title>`/`<desc>`, prefix every id and rewrite its `url(#…)` references, scope `.s`, add `class="brand-mark"` and `aria-label`) was applied to **four marks on one document**:

| Check | Result |
|---|---|
| Duplicate ids | **none** — `bm1-strokeFadeCompact`, `bm2-strokeFadeFull`, `bm3-…`, `bm4-…` |
| Gradient resolution | each mark resolves **its own** gradient; no cross-contamination |
| Accessible name | `role="img"` preserved, `aria-label` on every instance, `<title>`/`<desc>` stripped |
| Light override → solid ink | **confirmed**: all four stops compute `rgb(26, 27, 30)` at `stop-opacity: 1` |
| Dark → canonical fade | **confirmed**: `rgb(25, 231, 242)` at 0.2 / 0.5 / 0.82 / 1, exactly the source artwork |

CSS overriding SVG presentation attributes works exactly as [§10.3](#103-recommended-consumption) predicts. **No build-time generation step is needed**; the fallback is not taken.

**The defect: `vector-effect: non-scaling-stroke` makes the full logo unusable below ~256 px.** `intechral-logo.svg` carries it in its `.s` rule; `intechral-logo-compact.svg` does not. It pins the stroke at **8 CSS pixels regardless of rendered size**, so the mark fills in. Measured ink coverage (a legible line mark is roughly 5–25%):

| Size | compact (as committed) | full (as committed) | full, `vector-effect` removed |
|---:|---:|---:|---:|
| 28 px | 25.0% | **74.0%** | 30.9% |
| 40 px | 20.0% | **65.7%** | 26.3% |
| 96 px | 11.6% | **48.4%** | 14.0% |
| 256 px | 8.3% | **20.0%** | 10.7% |

Visually confirmed in both themes: at 28 px the committed full logo is a **solid filled triangle**, and at 96 px and 160 px its stepped interior closes up. This matters because [§10.3](#103-recommended-consumption) assigns `variant="full"` to **auth pages**, which render the mark at roughly 96–160 px — squarely inside the broken range.

**Decision:** `BrandMark` **removes `vector-effect:non-scaling-stroke`** as part of the same transform that scopes the ids. This changes only a presentation attribute inside the embedded `<style>` and never touches path data, which [Direction D §17.1](../design/direction-d-design-system.md) explicitly permits. The canonical source files are **not** edited — L16 is respected, and the committed artwork stays authoritative.

**Locked G2 consumption contract:**

| | |
|---|---|
| Canonical paths | `src/resources/images/brand/intechral-logo.svg` · `…/intechral-logo-compact.svg` |
| React | `BrandMark` imports the file with Vite `?raw`; strips `<title>`/`<desc>` and `aria-labelledby`; prefixes every id and its `url(#…)` refs with an instance-unique token; scopes `.s`; removes `vector-effect`; adds `class="brand-mark"` and an `aria-label` |
| Blade | `partials/shell/brand-mark.blade.php` performs the identical transform server-side on the same canonical file |
| Theming | Token layer overrides the stops: `[data-theme="light"] .brand-mark stop { stop-color: var(--ink); stop-opacity: 1 }`; dark inherits the source artwork untouched |
| Variants | `compact` for the 28 px rail and the favicon; `full` for auth pages and horizontal contexts |
| Not needed | Build-time asset generation; per-theme derivative files |

### A1.6 G3 — the transitional Pages item is confirmed, across seven actor profiles

`NavigationBuilder` output was captured for seven actors; the five non-seeded profiles were created and destroyed inside a rolled-back transaction.

| Actor | Sees `Pages` today | `cms.view` | `cms.edit` | G3 rule → `Pages` | Target `System` | Target `Directory` |
|---|---|---|---|---|---|---|
| `operator` (all permissions) | yes (+ *CMS Pages*) | y | y | **no** | yes | yes |
| `user` (role defaults) | **yes** | y | n | **YES** | no | no |
| CMS editor (`cms.view`+`cms.edit`) | yes (+ *CMS Pages*) | y | y | **no** | yes | no |
| CMS editor, no `cms.view` | no (only *CMS Pages*) | n | y | **no** | yes | no |
| Helpdesk agent | no | n | n | no | no | no |
| Billing operator | no | n | n | no | no | no |
| CRM manager | no | n | n | no | no | yes |

**The rule `cms.view AND NOT cms.edit` behaves correctly for every profile.** The regression it exists to prevent is real and confirmed: the built-in `user` role holds `cms.view` by default ([`PermissionCatalogue::userDefaults()`](../../src/app/Shared/Permissions/PermissionCatalogue.php)), and `/pages` is a read-only published-page viewer (`CmsController` filters `CmsPage::published()`), so dropping it would remove every customer's access to published content while leaking nothing.

**The one gap the plan does not discuss, and why it is not a regression.** An actor with `cms.edit` loses the *viewer* entry: today they see both `Pages` and `CMS Pages`, and under G3 they see only System → Pages. This is acceptable because the editor index already links to the published view per page — [`operator/cms/index.blade.php:53`](../../src/resources/views/operator/cms/index.blade.php#L53) renders `route('cms.show', $page->slug)` with `target="_blank"` on every row. No navigation path is lost.

**Two notes for WP3, neither blocking.**

- **Label mismatch.** The nav item is labelled *Pages* but the surface's `<h1>` and title are **"Resources"**. The rail label should match the page ("Resources"), or the page should be relabelled. Recommend matching the page and leaving the route name alone.
- **A `cms.edit`-only actor gets a `System` workspace containing one entry.** Correct under rule 7, but "System" is a heavy label for a content editor. Acceptable for the transitional period; the Knowledge/CMS epic retires it.

**G3 is confirmed as recommended**, with the label correction above.

### A1.7 S1 — drawer persistence confirmed unchanged; the cookie is not needed

The recommended architecture ([§15.3](#153-recommended-architecture)) was prototyped by injecting the pre-paint bootstrap and a CSS-driven geometry rule into the real `<head>` of live pages, then recording the geometry on the **first animation frame** and the total layout-shift score.

| Case | First frame | Settled | CLS | Verdict |
|---|---|---|---:|---|
| Inertia `/time`, cold, no stored preference | `312px / open` | `312px / open` | 0 | **no flash** |
| Blade `/operator/tickets`, cold | `312px / open` | `312px / open` | 0 | **no flash** |
| Inertia, stored `time: collapsed` | `64px / collapsed` | `64px / collapsed` | 0 | **no flash** |
| Blade, stored `helpdesk: open` | `312px / open` | `312px / open` | 0 | **no flash** |
| Hard reload | `312px / open` | `312px / open` | 0 | **no flash** |
| **M width 1024, stored `open`** | `64px` while the attribute stays `open` | same | 0 | **CSS ignores the preference, no JS involved** — [§16](#16-responsive-behaviour) satisfied |
| **`localStorage` throws** | `64px / collapsed` (server default) | same | 0 | **falls through, does not throw** |

**Transitions**, with `{projects: open, time: collapsed, helpdesk: open}` stored:

| Transition | Geometries observed | Verdict |
|---|---|---|
| cold → `/projects` (Inertia) | one | clean |
| Inertia → Inertia (`/time`) | two: `248px/open/projects` → `0px/collapsed/time` | **correct** — same document, workspace genuinely changed; one transition, no intermediate wrong value |
| Inertia → Inertia (`/tasks`) | two, same shape | correct |
| **React → Blade** (`/tickets`) | **one** | **no flash across the renderer boundary** |
| **Blade → React** (`/projects`) | **one** | **no flash across the renderer boundary** |
| Browser Back (Blade document) | one | correct |

**S1 confirms [§15.3](#153-recommended-architecture) unchanged. The cookie fallback in [§15.4](#154-why-not-the-cookie) is not taken.** The premise it rests on is verified: `inertia({ ssr: false })` is set, and the Inertia root ships `<body>` containing only the `data-page` JSON and an empty `<div id="app">` — nothing of the React shell paints before JavaScript runs.

**Three implementation requirements for WP4, from the spike:**

1. **On Inertia → Inertia, re-resolve during render, not in a post-navigate listener.** The spike used an `inertia:navigate` listener and showed no intermediate wrong frame, but that ordering is not guaranteed. Applying `data-workspace` / `data-drawer` while React renders the new page makes the geometry change atomic with the content change.
2. **The bootstrap must be the first thing in `<head>`.** Today's two theme scripts are *not* identical, contrary to [§4.1](#41-theme-and-css): the Inertia root places it **before** `@vite`, the Blade root places it **after** both `@vite` and the render-blocking bunny.net stylesheet. A stylesheet blocks execution of any script that follows it, so the Blade root's theme application is currently gated behind a third-party font fetch. The shared `bootstrap.blade.php` must sit before `@vite` in both roots. (WP1d's removal of the bunny.net link independently improves this.)
3. **Guard for a missing `documentElement`.** Not a production concern for an inline `<head>` script, but the `try/catch` in [§15.3](#153-recommended-architecture) should wrap the whole body, as specified.

**Shared theme bootstrap seam — confirmed, and left for WP4.** One script can replace both, because the two differ only in variable naming and placement. [§14.4](#144-files) already assigns `partials/shell/bootstrap.blade.php` to WP4/WP5; **WP0 does not refactor it**, since the epic does not assign that permanent change to WP0.

### A1.8 S2 — the provisional preference is overturned in part; a hybrid is locked

**Current behaviour, characterized in Chromium.** The shell is persistent: a sentinel attribute set on `<header>` survives an Inertia visit, confirming `AppLayout` is not remounted.

| Situation | Where focus lands today | Announced |
|---|---|---|
| Primary nav link, mouse | **stays on the activated link** | nothing |
| Primary nav link, keyboard (Enter) | **stays on the activated link**; next Tab → the next nav link | nothing |
| **In-page control** (`/time` view tabs, `/tasks` filter link) | **`<body>` — focus is destroyed** | nothing |
| Browser Back / Forward | unchanged from before the visit | nothing |

There is **no skip link** anywhere (zero in-page anchors), `#main-content` exists but nothing targets it, and **no nav link carries `aria-current`** — all three confirmed, as [§4.3](#43-react-shell) states.

**This splits the question the plan treats as one.** [§22.2](#222-q2--focus-on-inertia-navigation)'s provisional preference (a) is justified by "focus remains on the rail link the user just activated, which is a coherent and non-destructive resting place". **That is true for nav links and false for in-page controls**, because those live inside the subtree Inertia replaces — so activating a view tab or a filter link silently drops focus to `<body>`. That is a real keyboard-accessibility defect today, not a hypothetical.

**Candidates measured.** After a keyboard-activated **nav** visit:

| Policy | Focus after visit | Next Tab | Cost |
|---|---|---|---|
| (a) no move | the activated nav link | the next nav link | **nothing announced** |
| (b) focus `main` | `<main#main-content>` | `+ New task` | **destroys the user's place in the rail** |
| (c) focus `h1` | `<h1>Tasks</h1>` | `+ New task` | **destroys the user's place in the rail** |

**Locked decision for WP4 — (a) refined, not (a) as written:**

> **Announce every page change politely. Move focus only when the visit destroyed it.**
>
> On each Inertia navigation, write the new page's `h1` text to a polite live region. Then, **if and only if** `document.activeElement` is `body` or `documentElement` — meaning the activating control was inside the replaced subtree — move focus to `#main-content` with `tabindex="-1"`.

Measured against the contract:

| Case | Focus after | Repaired? | Announced |
|---|---|---|---|
| Primary nav link (keyboard) | the activated link; next Tab → next nav link | **no** — rail position preserved | yes |
| In-page view tab | `<main#main-content>` | **yes** — defect repaired | yes |
| Browser Back | `<main#main-content>` | yes | yes — closes today's silent-back gap |

This keeps (a)'s virtue (never destructive for a sighted keyboard user navigating the rail), fixes the defect (a) would leave in place, and gives screen-reader users an announcement in all three cases — which none of the three original candidates did on its own. Neither (b) nor (c) is adopted as a default; `main` is used only as the repair target, and the page `h1` is never focused.

**Two WP4 implementation notes.** `inertia:navigate` is the hook, and it fires for back/forward as well. The live region must be **cleared before it is re-set** when consecutive pages share an `h1` (`/time` → `/time/allocation` both read "My Time"), or assistive technology will not re-announce identical text.

**The skip link ([§22.1](#221-baseline-requirements-direction-d-14)) remains required and unchanged** — it is the deliberate jump into content and is genuinely absent today.

### A1.9 Navigation and renderer baseline

**Current output** is two flat groups; `/dashboard` matches **no** navigation item today, so Home is purely additive. Active state resolves to exactly one item on every route tested (`/projects`, `/tasks`, `/time`, `/tickets`, `/operator/tickets`, `/pages`, `/operator/cms`, `/admin/users`, `/billing/invoices`, `/crm/companies`, `/organizations`).

**Consumers.** React reads the shared prop in `app-layout.tsx` only; no page imports a navigation component and **no page performs any permission check at all** (`auth.permissions` has zero page-level consumers). Blade calls the builder from inside `partials/nav.blade.php`.

**Three declared fields are dead.** `NavigationItem` declares `method`, `activePatterns` and `children`; `NavigationLink` reads only `key`, `label`, `href`, `visit`, `isActive`, and nothing else reads them either. Dropping all three in the [§12.2](#122-target-shape) reshape is safe and additionally stops route-name patterns being serialized to the client.

**Landmark divergence to fix in WP5.** React scopes `<nav aria-label="Primary navigation">` to the link list; Blade puts that label on the **entire sticky bar**, so the brand link, theme toggle and account menu sit inside the primary-navigation landmark. Direction D's `nav` "Workspaces" must contain workspace navigation only.

### A1.10 Presentation-neutral contract — challenged against live data, one defect found

The [§12.2](#122-target-shape) shape carries every field the live routes need, and the `context` / `presentation` split survives the real data: no live entry requires a presentation hint to express authorization, and dropping `presentation` entirely leaves the authorized model intact for every actor in A1.6. `entities` and `saved` correctly have no emitter. The Finance capability branch expresses cleanly as a per-actor `href` + `label`. Nothing was found that a future Focused projection would need and cannot get.

**But the active-state rule cannot be satisfied with the pattern style the builder uses today.** [§12.3](#123-contract-rules) rule 3 requires at most one active context item; Direction D §8 allows at most one strip per screen. Carrying the current broad patterns into context items produces **three reproducible collisions**:

| Route | Workspace | Context items reported active |
|---|---|---|
| `/operator/tickets/reports` | Helpdesk | **Queue *and* Reports** |
| `/time/allocation` | Time | **My time *and* Allocation** |
| `/projects/create` | Projects | **All projects *and* New project** |

The cause is that `operator.tickets.*`, `time.*` and `projects.*` each match their sibling's routes. **Two requirements for WP3, neither currently in the plan:**

1. **Context items use explicit route names, not wildcards**, and resolution is **most-specific-wins** when more than one matches. Workspace-level patterns may stay broad; context-level patterns may not.
2. **`kind: 'actions'` items always emit `isActive => false`.** An action is not a destination, so "New project" should never carry a selected strip. This removes the third collision by design rather than by pattern-tuning.

Both belong in the Pest assertions [§25.1](#251-pest) already plans, which should include a case per collision above.

### A1.11 AppShell seam — verified cheap

Every condition [§23.2](#232-the-three-seams) asks for already holds:

| Seam requirement | Live state |
|---|---|
| 12 identical `Page.layout` lines | **confirmed** — all 12 are `(page) => <AppLayout>{page}</AppLayout>` |
| No page branches on role for chrome | **confirmed** — zero pages reference `auth.permissions` or `can()` |
| No page imports rail/drawer/nav components | **confirmed** — pages import only `AppLayout` or `AuthLayout` |
| Navigation consumed in one place | **confirmed** — `app-layout.tsx` only |

Renaming `AppLayout` in place ([§13.4](#134-coexistence-during-the-epic)) therefore requires **zero page edits**. Adding a Focused presentation later is one branch inside one component, as designed.

### A1.12 Performance and bundle baseline

Production build at `f1476fb`, for WP8 to compare against:

| Asset | Raw | Gzip |
|---|---:|---:|
| `app.tsx` entry (React/Inertia) | 350.5 KB | 110.1 KB |
| **`app-layout` (the shell chunk)** | **102.0 KB** | **34.2 KB** |
| `app.js` entry (Blade) | 49.3 KB | 18.6 KB |
| `app.css` (single stylesheet, both renderers) | 78.2 KB | 15.6 KB |
| `wayfinder` | 39.3 KB | 13.5 KB |
| `allocation` (chart.js) | 234.3 KB | 79.9 KB |
| `board` (dnd-kit) | 61.3 KB | 19.9 KB |
| Font payload (third-party) | 70.7 KB React / 94.5 KB Blade | — |

**Isolation verified, not assumed.** `chart.js` appears **only** in `allocation-*.js`; `@dnd-kit` appears **only** in `board-*.js`; **neither is present in the shell chunk**.

**Tick locality verified by inspection.** Exactly **one** `setInterval` exists in the entire React codebase — [`running-timer-bar.tsx:160`](../../src/resources/js/components/time/running-timer-bar.tsx#L160) — and it sets component-local state (`setBrowserNow`), so a tick re-renders only that subtree, never `TimerProvider` or `AppLayout`. **No test pins this**, which is a real gap: see A1.14.

### A1.13 Responsive and theme baseline

**Theme.** No-flash holds everywhere: the attribute is correct on the **first animation frame** on React, Blade and auth, in both themes, and survives React → Blade → React. No defect found.

**Responsive**, operator, `/dashboard` (React) and `/operator/tickets` (Blade):

| Width | React | Blade |
|---:|---|---|
| 1360 | desktop nav, no overflow, content 1280 (`max-w-7xl`) | desktop nav, no overflow, content 1280 |
| 1280 | as above | as above |
| 1024 | desktop nav, no overflow, content 1024 | as above |
| **768** | desktop nav, **horizontal overflow: 771 > 768** | no overflow |
| 390 | nav collapses to the menu button | menu button |

**One pre-existing defect recorded:** the React dashboard overflows horizontally by 3 px at exactly 768 px. It is not caused by this epic and is not fixed here, but the full-viewport shell will make it more visible, so WP4 should confirm it is gone rather than inherit it.

### A1.14 Tests — deliberately none added, and why

[§28 WP0](#wp0--characterization-and-architecture-spikes) assigns **no test work** to WP0; its outputs are baselines, spikes, gate decisions and the register. Navigation tests belong to WP3 ([§25.1](#251-pest), and [R9](#32-risks-and-rollback) requires the leakage tests be written *before* the reshape), and the tick-locality guard belongs to WP6 ([§25.2](#252-vitest--rtl), [R10](#32-risks-and-rollback)). **No durable test was added in WP0.** Two gaps are handed forward explicitly:

| Gap | Owner | Note |
|---|---|---|
| Nothing pins timer-tick locality | **WP6** | The sentinel assertion [§25.2](#252-vitest--rtl) already specifies. The property currently holds (A1.12) but is unguarded |
| `NavigationBuilderTest` has 3 cases and no leakage assertion | **WP3** | Write the [§25.1](#251-pest) matrix, including the three A1.10 collision cases, **before** the reshape |

### A1.15 Legacy-compatibility route set (locked)

All ten resolve HTTP 200. "F2 sites" counts elements on the live page carrying one of the five orphan variables.

| # | Surface | Route name | Renderer | F2 sites |
|---|---|---|---|---:|
| 1 | React form | `projects.create` | Inertia | 0 |
| 2 | React dense list | `tasks.index` | Inertia | 0 |
| 3 | React project surface | `projects.board` | Inertia | 0 |
| 4 | React account | `profile.show` | Inertia | 0 |
| 5 | Blade helpdesk (customer) | `tickets.show` | Blade | — |
| 6 | Blade helpdesk (operator) | `operator.tickets.index` | Blade | **5** |
| 7 | Blade admin/system | `roles.edit` | Blade | **1** |
| 8 | Blade finance | `billing.invoices.show` | Blade | — |
| 9 | Blade directory | `crm.companies.show` | Blade | — |
| 10 | Auth | `login` | Inertia | 0 |

**Fixture gap that blocks an honest matrix run.** The development database currently holds **0 invoices, 0 CRM companies and 0 CMS pages** (2 tickets, 4 projects, 2 roles). Screens **5, 8 and 9** cannot be checked against real content until those exist, and those are exactly the screens carrying the `--surface-muted` / `--border-muted` / `--surface-elevated` call sites. **WP1c and WP8 must seed an invoice, a CRM company and a published CMS page before running the matrix**, or three of the ten checks are vacuous.

### A1.16 Plan corrections applied by this amendment

| # | Section | Correction |
|---|---|---|
| C1 | [§4.1](#41-theme-and-css), [§8.1](#81-resolving-f1--the-utility-name-collision), [§28 WP1a](#wp1--semantic-tokens-and-typography) | WP1a is **59 call sites**, not 567. The 567/203/200/127 figures are `var(--*)` counts, not utility-class counts |
| C2 | [§8.1](#81-resolving-f1--the-utility-name-collision), [§28 WP1a](#wp1--semantic-tokens-and-typography) | WP1a is **not value-neutral for React**: freeing `text-primary` lets Tailwind generate it, flipping 10 sites from ink to indigo. Handle deliberately (A1.2) |
| C3 | [§8.4](#84-resolving-f2--defining-the-five-orphans) | `--surface-elevated` maps to **`var(--bg-surface)`**, not `var(--bg-elevated)` |
| C4 | [§8.4](#84-resolving-f2--defining-the-five-orphans) | A **sixth** orphan exists: `--accent-success` → define as `var(--success)` |
| C5 | [§9.3](#93-recommendation) | Newsreader has **no optical-size variable file**; use **static 400/500** (45.1 KB), which is smaller than the variable face (56.7 KB) |
| C6 | [§9.3](#93-recommendation) | latin + latin-ext across all three families is **202.8 KB and breaks the budget**. Budget is met by latin-only (**143.4 KB**); latin-ext is declared conditionally and not counted |
| C7 | [§9.3](#93-recommendation) | The density gate screens are `/admin/roles/{id}/edit` and `/operator/tickets` — **not the invoice form**, which is not dense |
| C8 | [§10.2](#102-the-technical-problem-with-theming-them) | The two gradient ids **differ**; the cross-file collision is the `.s` class and the `title`/`desc` ids, not the gradient reference |
| C9 | [§10.3](#103-recommended-consumption) | `BrandMark` must also **strip `vector-effect:non-scaling-stroke`**, or the full logo renders as a solid blob at every size an auth page would use |
| C10 | [§12.3](#123-contract-rules), [§25.1](#251-pest) | Context items need **explicit route names + most-specific-wins**, and `kind: 'actions'` items must never be active. Three live collisions otherwise |
| C11 | [§15.3](#153-recommended-architecture), [§4.1](#41-theme-and-css) | The two theme scripts are **not identical**: the Blade root's sits after `@vite` and the render-blocking font stylesheet. The shared bootstrap goes **before** `@vite` in both roots |
| C12 | [§22.2](#222-q2--focus-on-inertia-navigation) | Candidate (a) is adopted **refined**: announce always, and repair focus to `main` only when the visit destroyed it. Plain (a) leaves a live defect on in-page controls |
| C13 | [§25.4](#254-legacy-compatibility-screen-matrix) | Screens 5, 8 and 9 have **no fixtures** in the development database; seed before running the matrix |
| C14 | [§11.3](#113-the-viewer-facing-cms-problem) | The `cms.index` surface is titled **"Resources"**, not "Pages"; the rail label should match |

### A1.17 Hygiene

- **Spikes removed.** No temporary route, component, CSS, Blade partial or test survives. The `app.css` font spike was reverted byte-for-byte and `src/resources/fonts/` does not exist.
- **No dependency or manifest change.** `package.json`, `package-lock.json` and `composer.json` are untouched. Chromium was installed **into the container** at `/opt/ms-playwright`, not into the repository.
- **No business data mutated.** The seven-actor characterization ran inside a rolled-back transaction; no role or user persisted. No fixture was created.
- **No root-owned files in the repository.** The font spike's container-side copies were removed with the directory.
- **Pre-existing observation, untouched:** `/projects` carries four leftover E2E fixture projects (`E2E WP5/WP6/WP7 …`) from earlier browser runs. Not created by WP0 and not removed by it.

### A1.18 WP1a handoff

WP1a may start. It is smaller and more contained than planned, and it carries one deliberate behaviour change:

1. Rename the nine defined legacy utilities to a `legacy-` prefix across the **59 call sites** in A1.2 (the `legacy-` namespace is free). Four of the thirteen defined utilities have **zero** call sites and can simply be renamed with the rest.
2. In the same slice, resolve the ten React `text-primary` sites deliberately (A1.2, option 2), so the ink→indigo change is intentional and reviewed rather than incidental.
3. Gate: `./dev check`, plus a visual pass over the A1.15 route set in both themes — with particular attention to `/dashboard`, `/tasks`, `/time`, `/projects`, `login` and `forgot-password`, which contain the ten React sites.
4. WP1b then defines **six** variables (A1.3), using `var(--bg-surface)` for `--surface-elevated`.

G1, G2, G3, S1 and S2 are closed and none of them blocks WP1a.

---

## Amendment 2: WP1a Results (2026-09-25)

WP1a ran on `feature/epic-013-direction-d-shell` at `3601bea` (the committed WP0 baseline), working tree clean at start. **WP1a is now Complete.** The only production changes are the rename itself: the thirteen `@layer utilities` definitions in `app.css`, their thirteen Blade/React consumer sites, and the deliberate resolution of the ten React `text-primary` sites. No route, migration, permission, dependency or package-manifest file changed. `./dev check` is green. **Where this amendment conflicts with the body or with Amendment 1, this amendment wins for WP1a-scoped facts.**

### A2.1 Method

The F1 census was re-run from the live tree exactly as instructed, before any edit, using a word-boundary raw-text scan (not an attribute-regex parse) across every `.blade.php`, `.tsx` and `.ts` file under `src/resources` (excluding `welcome.blade.php`, which carries its own self-contained Tailwind build). The scan excluded `var(--*)` references and `-foreground`-suffixed shadcn classes, and classified every hit as **base** or **variant** by scanning backward from the match to the nearest whitespace/quote boundary for a `:`. Findings were cross-verified against the actual compiled Tailwind output (`npm run build`, inspecting `public/build/assets/app-*.css`), not inferred from source alone — this is what caught the live `text-primary` collision behaviour and, new in this amendment, the `bg-surface` variant behaviour.

### A2.2 The aggregate reproduces exactly; the per-utility breakdown does not — a second F1 correction

**The nine-utility total reproduces Amendment 1's headline number exactly: 59 (32/16/5/1/1/1/1/1/1 → `bg-surface`/`text-primary`/`text-secondary`/`bg-base`/`border-subtle`/`border-base`/`text-muted`/`shadow-theme-sm`/`shadow-theme-lg`).** A first pass using an attribute-scoped regex (mirroring what Amendment 1's methodology appears to have used) reproduced these nine numbers precisely. **That reproduction is itself the defect.** A full manual read of `nav.blade.php` and a corrected raw scan show the attribute-regex approach silently drops every occurrence embedded in a Blade `{{ $x ? '…' : '…' }}` PHP ternary — the closing quote of the outer `class="…"` swallows the inner single-quoted PHP string literal as one token, so `'bg-surface` (with the stray leading quote attached) never equals `bg-surface`. `nav.blade.php` carries five such ternary/closure constructs (the `$navLink` closure at lines 9–14, and inline ternaries at lines 85, 97 and 147), so it is undercounted by both tools in the same direction, which is why the two independently-flawed methods agree with each other rather than with the ground truth.

**Corrected base/variant breakdown, verified against the compiled CSS:**

| Utility | True base | True variant | Where |
|---|---:|---:|---|
| `bg-surface` | **3** | 32 (`hover:`) | Base: `footer.blade.php`, `nav.blade.php` ×2. Variant: `nav.blade.php` ×6 + 12 other Blade files ×26 |
| `text-primary` | **15** (5 Blade + 10 React) | 10 (9 `hover:` Blade + 1 `group-hover:` React) | Base/variant both concentrated in `nav.blade.php`; React base per A1.2's ten sites |
| `text-secondary` | **9** | 0 | All in `nav.blade.php` |
| `bg-base`, `border-subtle`, `border-base`, `text-muted`, `shadow-theme-sm`, `shadow-theme-lg` | 1 each | 0 | `nav.blade.php` (four of these) / `footer.blade.php` (two) — these five reproduce Amendment 1 exactly, since none sits inside a ternary |
| `bg-elevated`, `text-inverse`, `btn-accent`, `shadow-theme-md` | 0 | 0 | Unused, as Amendment 1 found |

**True total: 34 base + 42 variant = 76 raw occurrences**, against Amendment 1's 59. The **34 base** occurrences are the ones WP1a's "values unchanged, mechanical rename" promise applies to; the **42 variant** occurrences needed the same per-site audit A1.2 already required for `text-primary`, extended here to `bg-surface` (A2.3).

This changes the shape of the slice, not its soundness: every one of the 34 base sites and all 42 variant sites were located, individually classified and (where safe) renamed — see A2.4–A2.6. Nothing in F1's substance (the `surface`/`text-primary`/`text-muted`/etc. namespace must be vacated before Direction D lands) is affected.

### A2.3 A second live-collision-in-waiting found: `hover:bg-surface` is inert today, but is not neutral to a future `surface` token

Amendment 1 analysed `hover:text-primary`'s live collision in detail (A1.2) but did not separately analyse `bg-surface`'s 32 `hover:bg-surface` sites, which its own table folds into the "32" total as if they were base occurrences.

**Verified in the compiled CSS, before any WP1a edit:**

```css
.bg-surface{background-color:var(--bg-surface)}   /* the only bg-surface rule that exists */
```

No `.hover\:bg-surface:hover` rule is generated at all — unlike `text-primary`, `surface` is not registered as a Tailwind colour token anywhere in `app.css` today (no `--color-surface` exists), so Tailwind's JIT engine cannot synthesise *any* variant for it, live or otherwise. **All 32 `hover:bg-surface` sites across 13 Blade files (`admin/roles/{create,edit,index}`, `admin/users/{index,show}`, `errors/403`, `nav.blade.php`, `operator/tickets/{index,reports,show}`, `tickets/{create,index,show}`) are currently dead — they produce no visual effect on hover, today, before and after this slice.**

This matters for WP1c, not WP1a: the moment Direction D registers `surface` as a semantic token (§8.2's planned `@theme inline` entry), Tailwind will begin generating `.hover\:bg-surface:hover` against the new value, and these 32 sites — none of them reviewed for that outcome, several inside chrome that WP5 deletes — would silently gain a hover interaction they were never designed to have. That is exactly the class of "accidental appearance change caused by source-order/token differences" WP1a exists to prevent ([§8.1](#81-resolving-f1--the-utility-name-collision), Option C's rationale), just deferred one slice.

**Decision:** rename all 32 to `hover:legacy-bg-surface`. Confirmed in the compiled CSS after the rename that this remains inert (no rule generated for `hover:legacy-bg-surface` either, since `legacy-bg-surface` is equally not a Tailwind colour token) — so the rename is exactly appearance-preserving today, and fully vacates `bg-surface` (base **and** variant) for WP1c to claim without inheriting an unreviewed interaction. This is recorded as a plan addition, not a contradiction: [§8.1](#81-resolving-f1--the-utility-name-collision) already requires the namespace be "completely vacated," and 32 of the 76 live sites were the part of that vacating Amendment 1's own table did not surface.

### A2.4 Legacy utility rename map

All thirteen definitions in `app.css`'s `@layer utilities`, values byte-identical:

| Old | New |
|---|---|
| `.bg-base` | `.legacy-bg-base` |
| `.bg-surface` | `.legacy-bg-surface` |
| `.bg-elevated` | `.legacy-bg-elevated` |
| `.border-subtle` | `.legacy-border-subtle` |
| `.border-base` | `.legacy-border-base` |
| `.text-primary` | `.legacy-text-primary` |
| `.text-secondary` | `.legacy-text-secondary` |
| `.text-muted` | `.legacy-text-muted` |
| `.text-inverse` | `.legacy-text-inverse` |
| `.btn-accent` | `.legacy-btn-accent` |
| `.shadow-theme-sm` | `.legacy-shadow-theme-sm` |
| `.shadow-theme-md` | `.legacy-shadow-theme-md` |
| `.shadow-theme-lg` | `.legacy-shadow-theme-lg` |

Verified post-rename in the compiled CSS: every `legacy-*` selector resolves to its pre-rename value; `text-primary`, `text-secondary` and `text-muted` are now free and Tailwind generates them against the live shadcn tokens (`var(--primary)`, `var(--secondary)`, `var(--muted)` respectively — none of these three has a remaining unprefixed consumer in the tree); `bg-surface`, `bg-base`, `bg-elevated`, `border-subtle`, `border-base`, `text-inverse`, `btn-accent` and `shadow-theme-{sm,md,lg}` generate no rule at all (no colliding hand-written definition and no matching Tailwind token), i.e. fully unoccupied for WP1c.

### A2.5 The ten React `text-primary` sites — audited individually, not blanket-renamed

Contrary to A1.2's "recommended option 2" (rewrite all ten to an explicit accent utility), each site was read in context and its rendering intent classified. Two distinct patterns emerged, and they got opposite treatment:

| # | File:line | Component/surface | Semantic intent | Prior/current computed colour | Decision | Reason |
|---|---|---|---|---|---|---|
| 1 | `pages/auth/login.tsx:52` | "Forgot password?" link | Quiet inline link (`hover:underline`, no colour-change affordance) | Ink (`--text-primary`) | → `legacy-text-primary` | Ink link pattern, unchanged appearance |
| 2 | `pages/auth/forgot-password.tsx:52` | "Back to sign in" link | Same pattern | Ink | → `legacy-text-primary` | Same |
| 3 | `pages/dashboard/index.tsx:177` | "View all" (tickets) link | Same pattern | Ink | → `legacy-text-primary` | Same |
| 4 | `pages/dashboard/index.tsx:265` | "Open CRM" link | Same pattern | Ink | → `legacy-text-primary` | Same |
| 5 | `pages/projects/index.tsx:52` | "Create your first project" link | Same pattern | Ink | → `legacy-text-primary` | Same |
| 6 | `components/tasks/task-title-cell.tsx:18` | Task title link (list/board rows) | Same pattern; its own non-link fallback (`task.url` absent) already renders `text-foreground` — the same ink value | Ink | → `legacy-text-primary` | Matches the ink of its own sibling `<span className="… text-foreground">` fallback |
| 7 | `pages/time/index.tsx:481` | `TimerContextLink` (time-entry context) | Same pattern, conditional (`entry.context.url ? '…' : undefined`) | Ink | → `legacy-text-primary` | Same |
| 8 | `pages/tasks/index.tsx:32` | `ViewTab` active state (`cn(active ? 'border-primary text-primary' : …)`) | Selected/active tab: paired with `border-primary`, which already resolves to the shadcn accent (no collision) | Ink text + indigo border (mismatched) | **Left as `text-primary`** | Active-state semantic; freeing the base makes text match its own border — a deliberate fix, not an accidental one |
| 9 | `pages/time/index.tsx:378` | "Entries" tab, statically active | Same active-tab pattern (`border-b-2 border-primary … text-primary`) | Ink text + indigo border | **Left as `text-primary`** | Same |
| 10 | `pages/time/allocation.tsx:219` | "Allocation" tab, statically active | Same active-tab pattern | Ink text + indigo border | **Left as `text-primary`** | Same |

Sites 1–7 all follow one recognizable idiom (ink-coloured text, `hover:underline` is the only interactive affordance) and got the same treatment; sites 8–10 all follow the opposite idiom (accent-coloured active state paired with an already-accent border) and got the opposite treatment. Verified post-rename in Chromium, both themes: sites 1–7 render byte-identical computed colour to pre-WP1a (`oklch(0.21 0.034 264.665)` light / `oklch(0.985 0.002 247.839)` dark — unchanged `--text-primary`); sites 8–10 now render `rgb(79, 70, 229)` light / `rgb(99, 102, 241)` dark for **both** text and border (previously text was ink while the border was already indigo — the mismatch A1.2 documented is resolved).

One additional site was found and deliberately left alone: `pages/dashboard/index.tsx:154`, `group-hover:text-primary` on the "View all" chevron icon. Confirmed in the compiled CSS to resolve live to `var(--primary)` (indigo) both before and after this slice — it intends the accent, not the legacy value, so it was not touched, for the same reason as sites 8–10's border pairing.

### A2.6 Variant audit — complete

Every variant-prefixed occurrence of the nine collision-prone utilities was found and classified (A2.2's table), verified against the compiled CSS rather than assumed:

| Variant | Sites | Live behaviour (before = after, verified) | Action |
|---|---:|---|---|
| `hover:text-primary` | 9 (`nav.blade.php`) | `color: var(--primary)` (indigo) — live accent, generated regardless of the base rename | **Left unchanged.** Renaming to `legacy-*` would generate no rule and silently remove the hover-colour affordance across the whole legacy nav chrome — a real regression |
| `group-hover:text-primary` | 1 (React, A2.5) | Same, live accent | **Left unchanged** |
| `hover:bg-surface` | 32 (13 Blade files) | No rule generated, either name (A2.3) | **Renamed** to `hover:legacy-bg-surface` — appearance-preserving, vacates the namespace before WP1c |

No `dark:`, `focus:` or responsive-prefixed occurrence of any of the nine utilities exists anywhere in the tree (confirmed by the same raw scan). No `legacy-legacy-*` or other malformed name was produced (checked by regex post-edit).

### A2.7 Blade migration

Twenty files changed. `nav.blade.php` (14 edit locations: the `$navLink` closure, six plain attributes, three ternaries) and `footer.blade.php` (1 location, 3 classes) received the base renames plus the six `hover:legacy-bg-surface` renames that live inside `nav.blade.php`'s own hover states. The other twelve files (`admin/roles/{create,edit,index}`, `admin/users/{index,show}`, `errors/403`, `operator/tickets/{index,reports,show}`, `tickets/{create,index,show}`) each received only `hover:bg-surface` → `hover:legacy-bg-surface`, mechanically, no other class touched. `nav.blade.php` and `footer.blade.php` remain WP5-deleted chrome ([§14.4](#144-files)) but are left fully correct now, per the instruction not to skip them.

### A2.8 Generated-CSS verification

Confirmed by inspecting `public/build/assets/app-*.css` after `npm run build`, not by source inspection alone:

- Every `legacy-*` selector resolves to its pre-WP1a value (checked all thirteen).
- `text-primary`, `text-secondary`, `text-muted` are unoccupied by any hand-written rule and now resolve through Tailwind to the live shadcn tokens (`--primary`, `--secondary`, `--muted`) — free for WP1c.
- `bg-surface`, `bg-base`, `bg-elevated`, `border-subtle`, `border-base`, `text-inverse`, `btn-accent`, `shadow-theme-{sm,md,lg}` generate no rule at all — fully unoccupied.
- `hover:text-primary` / `group-hover:text-primary` still resolve to `var(--primary)`, unchanged.
- `hover:legacy-bg-surface` (all 32 sites) generates no rule, matching its pre-rename inertness.
- No `legacy-legacy-*` selector exists.

CSS bundle: **78,358 bytes raw / 15,305 bytes gzip**, against the WP0 baseline of 78.2 KB / 15.6 KB (A1.12) — no meaningful change (the `legacy-` prefix's extra characters are offset by gzip's handling of the now-repetitive prefix).

### A2.9 Visual compatibility result

Verified in real Chromium (Playwright, the same container-installed browser as WP0), both themes, signed in as `operator@intechral.test`:

| Surface | Check | Result |
|---|---|---|
| `/dashboard` (React) | "View all", "Open CRM" links | Ink in both themes, byte-identical computed colour to pre-WP1a |
| `/tasks` (React) | Active/inactive `ViewTab` | Active tab: text now matches its own border (indigo, both themes) — the deliberate fix |
| `/time`, `/time/allocation` (React) | Active "Entries"/"Allocation" tab | Same deliberate fix, confirmed both themes |
| `/projects` (React) | Empty-state "Create your first project" link | Not exercised directly (fixture data has projects; source-verified instead, A2.5 site 5) |
| `login`, `forgot-password` (React, guest) | "Forgot password?", "Back to sign in" links | Ink in both themes, confirmed with a fresh browser context per theme to rule out a localStorage/reload race |
| `/admin/roles` (Blade) | Nav bg/border, brand link, footer, user-menu "Profile"/"Sign out" | All render the pre-WP1a legacy values in both themes; `hover:legacy-bg-surface` on an action link confirmed inert before and after hover (identical computed background) |
| `/operator/tickets` (Blade) | Row `hover:legacy-bg-surface` present, F2 orphan variables untouched | Confirmed present in the DOM; F2 sites out of WP1a's scope per the brief, unaffected |

No accidental change was found anywhere. The two deliberate changes (React sites 8–10's text colour, and the ten React sites' explicit resolution generally) are exactly the ones the brief asked to be made explicit.

### A2.10 Accessibility result

No regression: every `legacy-*` renamed site carries forward its exact prior computed colour, so AA contrast, focus visibility and dark-mode readability are unchanged from pre-WP1a. The two deliberate `border-primary`/`text-primary` matches (A2.5, sites 8–10) improve rather than harm accessibility — a sighted user previously saw an indigo underline with ink text on the active tab; both signal channels now agree. No skip link, `aria-current` or focus-management work was touched (correctly out of scope for this slice).

### A2.11 Tests / static guards

No new automated test was added, consistent with [§28 WP0](#wp0--characterization-and-architecture-spikes)/[§25](#25-test-strategy) assigning this kind of CSS-namespace migration a static/source-search verification rather than a snapshot-test suite, and with the brief's explicit instruction to avoid brittle full-class-string assertions. Verification instead relied on:

- A raw word-boundary source scan (base vs. variant, per utility) re-run after every edit, converging to zero unprefixed occurrences of the nine collision-prone utilities except the thirteen deliberately-preserved live-accent variant sites (A2.6).
- A `legacy-legacy-*` malformed-name regex sweep (zero matches).
- Direct inspection of the compiled Tailwind output, both before and after, for every renamed and preserved selector (A2.8).
- A Chromium visual pass across the ten-screen-adjacent compatibility set, both themes (A2.9).

### A2.12 Documentation changes

This amendment; the `Amendments` line and Contents entry at the top of the epic; WP1a's line in [§28](#28-work-packages) marked Complete with a cross-reference.

### A2.13 Deviations / findings

1. **A2.2** — Amendment 1's per-utility F1 breakdown undercounts Blade `text-primary` and `text-secondary` base sites and folds 32 `hover:bg-surface` variant sites into the `bg-surface` "base" total, all traceable to Blade-ternary-embedded PHP string literals that attribute-scoped regexes silently drop. The aggregate 59 was reproduced by the same flawed method and does not represent the true 76 raw occurrences (34 base, 42 variant). This did not block WP1a — the true set was located and handled — but WP1b/WP1c should not reuse Amendment 1's per-utility table as a call-site list without the same caveat.
2. **A2.3** — `hover:bg-surface` is a second, previously undocumented namespace hazard of the same shape as `hover:text-primary`, except inert today rather than live. Neutralized in this slice so WP1c does not inherit it as a silent side effect.
3. No other deviation from the brief. WP1b's ownership (six orphan variables, `--surface-elevated → var(--bg-surface)`) was not touched.

### A2.14 Files changed

`src/resources/css/app.css` · `src/resources/js/components/tasks/task-title-cell.tsx` · `src/resources/js/pages/auth/{login,forgot-password}.tsx` · `src/resources/js/pages/dashboard/index.tsx` · `src/resources/js/pages/projects/index.tsx` · `src/resources/js/pages/time/index.tsx` · `src/resources/views/admin/roles/{create,edit,index}.blade.php` · `src/resources/views/admin/users/{index,show}.blade.php` · `src/resources/views/errors/403.blade.php` · `src/resources/views/layouts/partials/{nav,footer}.blade.php` · `src/resources/views/operator/tickets/{index,reports,show}.blade.php` · `src/resources/views/tickets/{create,index,show}.blade.php` — 20 files, plus this documentation update. No dependency, font, route, migration or CI file touched.

### A2.15 Validation results

`./dev check` (via `bash dev` — see A2.16): CLI self-tests fail, `git diff --check` passes, Pint passes with no changes written, `npm run check` passes (wayfinder generate, `tsc --noEmit`, ESLint `--max-warnings=0`, Prettier check, 378 Vitest tests, production build), Pest passes (933 tests / 3749 assertions). Production build re-run standalone: `npm run build` green, CSS bundle 78,358 B raw / 15,305 B gzip (A2.8).

### A2.16 Cleanup / ownership

- **The `./dev` launcher file itself is not executable** (`-rw-r--r--`, no `+x`) at the start of this session, before any WP1a edit — confirmed by the very first command of the session failing with `Permission denied`, and by `git log -1 -- dev` showing no WP1a-authored commit. This is a pre-existing host/checkout condition, not a WP1a regression, and is left untouched (out of scope; fixing it is not a namespace-isolation change). `bash dev check` was used as the equivalent invocation throughout.
- One Vitest flake reproduced during the full-suite `npm run check` run (`milestone-form-dialog.test.tsx`, a 5000ms timeout under full-suite jsdom load) and passed cleanly in isolation on retry. The file is untouched by this slice; recorded as pre-existing flakiness, not a WP1a regression.
- All temporary Playwright verification scripts were written to the container's project directory and the session scratchpad, and removed before finishing; none is tracked by git.
- No dependency, font, or generated-artefact file was added or is tracked. `git status` shows exactly the 20 source files plus this documentation file.
- No business data was mutated (read-only Chromium visual pass, signed in as the existing `operator@intechral.test` fixture).

### A2.17 WP1b handoff

WP1b may start. It owns, and WP1a did not touch:

- Defining the **six** orphan variables (A1.3): `--surface-base`, `--surface-muted`, `--surface-elevated`, `--border-muted`, `--surface-accent`, and the sixth found in Amendment 1, `--accent-success`.
- The accepted mapping `--surface-elevated → var(--bg-surface)` (A1.3's correction from the original plan's `--bg-elevated`), and the deliberate-in-dark visual change that mapping causes for `<thead>` rows and the `closed` status badge.
- The pre-existing `--surface-muted` / `--text-muted` badge contrast failure (A1.3) — confirmed still present, unrelated to and unworsened by WP1a; hand forward to WP8/Finance, not fixed in WP1b.
- Seeding an invoice, a CRM company and a published CMS page before running the legacy-compatibility matrix (A1.15/C13) — the dev database still holds zero of each, confirmed unchanged in this session.

WP1a additionally hands forward one namespace fact WP1c needs: **`text-primary`, `text-secondary`, `text-muted`, `bg-surface`, `bg-base`, `bg-elevated`, `border-subtle`, `border-base`, `text-inverse`, `btn-accent` and `shadow-theme-{sm,md,lg}` are all now fully unoccupied**, base and variant alike (A2.8), so WP1c's `@theme inline` registration is unobstructed for all of them, not only the five Amendment 1 analysed in depth.

---

## Amendment 3: WP1b Results (2026-09-25)

WP1b ran on `feature/epic-013-direction-d-shell` at `8af256c` (the committed WP1a result), working tree clean at start. **WP1b is now Complete.** The only production change is additive: twelve new lines in `app.css` (six variables × light/dark), each a `var()` reference to an existing raw variable. No route, migration, permission, dependency, package-manifest, Blade, or React file changed. `./dev check` is green. **Where this amendment conflicts with the body or with prior amendments, this amendment wins for WP1b-scoped facts.**

### A3.1 Method

The F2 census was re-run from the live tree exactly as instructed, counting `var(--x)` references (not utility classes, so WP1a's Blade-ternary undercount issue, A2.2, does not apply here — `style="…var(--x)…"` attributes are plain HTML attributes, not Blade PHP string literals). Findings were verified against the compiled CSS after the edit, and against representative consumers rendered in real Chromium (the same container-installed browser used by WP0/WP1a), signed in as `operator@intechral.test`, both themes. WCAG contrast was computed from actual rendered pixels (via an off-screen canvas reading back each computed color as sRGB), not estimated from theme values.

### A3.2 F2 census reproduces exactly

| Variable | Amendment 1 (WP0) | This session | Match |
|---|---:|---:|---|
| `--surface-base` | 24 | 24 | ✅ |
| `--surface-muted` | 10 | 10 | ✅ |
| `--surface-elevated` | 9 | 9 | ✅ |
| `--border-muted` | 4 | 4 | ✅ |
| `--surface-accent` | 3 | 3 | ✅ |
| `--accent-success` | 1 (`board-column.tsx:59`) | 1 (same site) | ✅ |

**No material difference.** Fifty F2 sites plus the one `--accent-success` site, fifty-one total, exactly as A1.3 and this task's brief state. All thirteen consuming files identified in A1.3 confirmed still current: `operator/tickets/{index,reports,show}`, `admin/{roles/{index,edit},users/{index,show}}`, `tickets/{index,show,_status_badge}`, `billing/{invoices/{index,show},client/{index,show}}`, plus `board-column.tsx`. No definition for any of the six variables existed anywhere in the repository before this slice (confirmed by source search across `src/resources/css/`, the only CSS file in the project).

### A3.3 Final compatibility mappings

Defined once per theme block (`:root,[data-theme="light"]` and `[data-theme="dark"]`), grouped under one new "Compatibility orphans (EPIC-013 WP1b)" comment per block, placed immediately before the existing "Shadows" group — the same layer §8.2 assigns them, alongside the pre-existing raw variable families, not a second token source:

```css
--surface-base:     var(--bg-base);
--surface-muted:    var(--bg-surface);
--surface-elevated: var(--bg-surface);
--border-muted:     var(--border-subtle);
--surface-accent:   var(--surface-info);
--accent-success:   var(--success);
```

Identical right-hand sides in both theme blocks — each resolves differently per theme only because the *referenced* variable (`--bg-base`, `--bg-surface`, etc.) is itself theme-scoped, exactly matching the existing file's convention for indirection variables (e.g. `--primary: var(--accent)`, `--muted: var(--bg-surface)`, both already duplicated per-theme in the same way).

### A3.4 Variable census

| Variable | Use count | Representative consumers | Pre-WP1b computed behaviour | Final mapping |
|---|---:|---|---|---|
| `--surface-base` | 24 | Card/panel containers on `admin/{roles,users}`, `operator/tickets`, `tickets/{index,show}` — always paired with `border-color: var(--border-base)` | `background-color` invalid → initial value `transparent`; card showed the plain page background underneath | `var(--bg-base)` — **strictly appearance-preserving** (page bg and `--bg-base` are the same colour) |
| `--surface-muted` | 10 | `draft`/`cancelled` invoice status badges, `billing/{invoices,client}` | `transparent`; badge showed the enclosing `--surface-card` colour (white light / gray-800 dark) through it | `var(--bg-surface)` — **visible, deliberate tint** now appears (gray-50 light / gray-900 dark) |
| `--surface-elevated` | 9 | `<thead>` rows on four list tables; the ticket `closed` status badge; the role `Custom` type badge | `transparent`; identical to the surrounding card, so **no boundary at all** | `var(--bg-surface)` — **corrected from the plan's `--bg-elevated`** (A3.5) |
| `--border-muted` | 4 | Same `draft`/`cancelled` invoice badges' border | `border-color` invalid → falls through to its own initial value **`currentcolor`**, i.e. the badge's own text colour (`--text-muted`, already defined) — verified empirically (A3.6), not merely reasoned from spec | `var(--border-subtle)` — a distinct, intentionally softer border shade, no longer text-coloured |
| `--surface-accent` | 3 | `open` ticket status badge; `Built-in` role badge (×2 files) | `transparent`; showed the enclosing card colour, text-only accent-coloured label with no visible pill | `var(--surface-info)` — the only defined tinted surface that reads legibly with `--accent` text (A1.3), **with a dark-mode contrast caveat, A3.7** |
| `--accent-success` | 1 | Board "Done" column indicator dot, `board-column.tsx:59` | Tailwind arbitrary-value inline fallback `#22c55e` (Tailwind `green-500`) — solid, **theme-invariant** (same hex in both themes) | `var(--success)` — now theme-aware: `green-600` light / `green-400` dark |

### A3.5 `--surface-elevated` correction — verified in Chromium

Confirmed live, both themes, on `/admin/users` (card = `--surface-base`, `<thead>` row = `--surface-elevated`):

| | Card (`--surface-base`) | Header row (`--surface-elevated`) | Boundary contrast (bg-vs-bg) | Header text vs. row | Verdict |
|---|---|---|---:|---:|---|
| Light | `rgb(255,255,255)` | `rgb(249,250,251)` | **1.05:1** — a faint, genuine tint | `7.23:1` | Distinguishable but subtle, as designed — the rejected `--bg-elevated` alternative would have measured **1.00:1**, i.e. zero distinction |
| Dark | `rgb(3,7,18)` | `rgb(16,24,40)` | **1.13:1** — a subtle lift | `6.82:1` | Present without reading as a "raised band"; the rejected `--bg-elevated` alternative would have jumped two full Tailwind steps (A1.3) |

This matches every condition the brief asked to verify: distinguishable from the base layer, not excessively strong in dark, an acceptable header boundary, readable header text, no lost borders (the table's own `border-color: var(--border-subtle)` is untouched by this slice). **This is the accepted deliberate correction from the plan's `--bg-elevated`, confirmed working exactly as A1.3 predicted, not a regression.**

### A3.6 `--accent-success` result

The board "Done"-column dot at `board-column.tsx:59` (`bg-[var(--accent-success,#22c55e)]`) was inspected on a real project board, both themes:

| Theme | Prior (hardcoded fallback) | Now (`var(--success)`) |
|---|---|---|
| Light | `#22c55e` (Tailwind `green-500`, fixed) | `oklch(0.627 0.194 149.214)` = Tailwind `green-600` — confirmed by rendering |
| Dark | `#22c55e` (identical — the fallback never varied by theme) | `oklch(0.792 0.209 151.711)` = Tailwind `green-400` — confirmed by rendering |

`--success` was the narrowest existing match: it is the raw "solid accent" success token already driving `--color-success` (used nowhere else directly as a raw `var(--success)` reference in the tree, but it is exactly the semantic register a small solid indicator dot belongs to — the *other* existing success family, `--text-success`/`--border-success`/`--surface-success`, is a three-part badge trio (border + tinted surface + text) meant for bordered status badges, not a single-value accent fill, and pairing any one piece of that trio alone would misuse the family). This is a deliberate, sanctioned, minor colour shift (A1.3's own recommendation), not an accident: the dot goes from a fixed hex to a theme-aware value, correctly darkening in light and lightening in dark the same way every other success-toned element in the app already does.

### A3.7 Contrast / accessibility result — two debts, not one

**The known debt, confirmed with final WP1b values, not worsened.** `--surface-muted` badge background against the pre-existing `--text-muted` text (invoice `draft`/`cancelled` badges), computed from real rendered pixels via canvas readback:

| Theme | Pre-WP1b (text vs. inherited `--surface-card` ancestor) | Post-WP1b (text vs. `--surface-muted`) | Delta |
|---|---:|---:|---|
| Light | 2.60:1 | 2.49:1 | −0.11 — both deep in FAIL territory; not a perceptible change |
| Dark | 1.94:1 | 2.35:1 | **+0.41 — WP1b measurably improves this one**, still FAIL |

Both readings fail WCAG AA (4.5:1 normal text) before and after. **WP1b does not create this failure and does not meaningfully worsen it** (light moves within noise; dark improves) — confirmed with actual final values, not assumed. Recorded, again, for WP1c/WP2/Finance's `Status` component (A1.3 already flagged this; this session re-confirms it with post-WP1b numbers rather than pre-WP1b estimates).

**A second, previously undocumented debt, found in this slice.** The `--surface-accent`/`--accent` pairing (`open` ticket badge, `Built-in` role badge) was not contrast-checked by Amendment 1 — A1.3's "Confirmed" verdict for `--surface-info` was evidenced in light mode only. Checked here in both themes:

| Theme | `--surface-accent` bg | `--accent` text | Contrast | Verdict |
|---|---|---|---:|---|
| Light | `rgb(239,246,255)` | `rgb(79,70,229)` | 5.78:1 | PASS AA |
| Dark | `rgb(22,37,86)` | `rgb(99,102,241)` | **3.28:1** | **FAIL AA** (12px, weight 500 — too small/light to qualify for the large-text 3:1 exemption) |

This did **not** exist as a passing, functioning badge before WP1b — `--surface-accent` was `transparent`, so the badge had no visible pill at all, only accent-coloured text floating on the page (measured at 4.51:1 against the ultimate inherited page background, which reads as a technical "pass" but for a component that was not rendering its designed shape). Per this task's own instruction not to treat "preserve the accident of transparent" as success, the fair comparison is not transparent-vs-real; it is that **making the intended pill real reveals a genuine, previously-latent AA failure in dark mode**, on the only two consumers of `--surface-accent`. No compatibility-safe alternative exists within the current raw-variable family: `--surface-info` is, as A1.3 found, the only tinted surface that pairs *semantically* with `--accent`, and the sibling tint families (`success`/`danger`/`warning`) are equally deep `-950`-scale dark shades with no reason to expect a materially better ratio against the same `--accent` text. **Decision: keep `var(--surface-info)` — the correct and only compatible mapping — and record this as a second contrast debt of the same shape as A1.3's, handed forward undisguised rather than silently accepted.** No badge redesign, no Direction D semantic, and no alternate token were introduced to chase this number; that repair belongs to WP1c/WP2 alongside the `--surface-muted` one.

**No focus-visibility, control-boundary or dark-mode-legibility regression elsewhere.** Every other measured pairing (header text 7.23:1/6.82:1, `Custom` chip 7.23:1/6.82:1, `Closed` badge 7.23:1/6.82:1) comfortably passes AA in both themes.

### A3.8 Consumer audit

Grouped by semantic use, confirming no variable is doing two incompatible jobs:

| Group | Variable(s) | Consistent? |
|---|---|---|
| Page/card surfaces | `--surface-base` | Yes — every one of the 24 sites is a card/panel container paired with `--border-base` |
| Table headers + two status-type badges | `--surface-elevated` | Yes in *mechanism* (a one-step lift above the card), used identically for `<thead>` rows, the `closed` ticket badge and the `Custom` role badge — all three are "the subdued/neutral state" of their respective component, which is exactly what a one-step lift communicates |
| Status badges (muted) | `--surface-muted`, `--border-muted`, paired with existing `--text-muted` | Yes — both `draft` and `cancelled` invoice states, one shared visual treatment for "inactive/non-current" |
| Status/type chips (accent) | `--surface-accent`, paired with existing `--accent` | Yes — `open` ticket and `Built-in` role are both "the notable/current state" of their component |
| Board status accent | `--accent-success` | Single consumer, no conflict |

No sprawling redesign was needed and none was found necessary: each variable maps to exactly one coherent semantic role across all its consumers.

### A3.9 Visual compatibility result

Real Chromium, both themes, signed in as `operator@intechral.test`:

| Surface | Variable(s) exercised | Light | Dark |
|---|---|---|---|
| `/admin/users` | `--surface-base`, `--surface-elevated` | Card white, header faintly tinted, boundary present | Card near-black, header one step up, subtle lift |
| `/admin/roles` | `--surface-base`, `--surface-elevated`, `--surface-accent` | `Built-in`/`Custom` chips both legible | `Built-in` chip contrast-thin (A3.7); `Custom` chip fine |
| `/operator/tickets` | `--surface-accent` (`open`), `--surface-elevated` (`closed`, via a temporarily flipped fixture ticket, A3.10) | Both badges legible | `open` badge contrast-thin (A3.7); `closed` badge fine |
| `/billing/invoices` | `--surface-muted`, `--border-muted` (via a temporary fixture invoice, A3.10) | `Draft` badge present, border visibly distinct from text colour, contrast weak (known debt, A3.7, not worsened) | Same, contrast weak but improved vs. pre-WP1b |
| `/projects/{id}/board` | `--accent-success` | "Done" column dot now `green-600` (was fixed `#22c55e`) | "Done" column dot now `green-400` |

### A3.10 Fixture/data handling

Per A1.15/C13, the dev database held zero invoices, zero CRM companies and zero CMS pages. **None of the six WP1b variables is consumed on a CRM/company or CMS/page screen** (confirmed by source search — only `billing/*`, `admin/*`, `operator/tickets/*`, `tickets/*` and one React component reference them), so only an invoice fixture was needed for WP1b; CRM/CMS fixtures remain a WP1c/WP8 concern per A2.17, untouched here.

Three temporary, disposable fixtures were created through normal application mechanisms and deleted immediately after the visual pass:

| Fixture | Mechanism | Purpose | Cleanup |
|---|---|---|---|
| One `draft`-status invoice (id 1), `client_id`/`created_by` pointing at existing users | `Invoice::factory()->draft()->create()` | Only way to exercise `--surface-muted`/`--border-muted` — zero invoices existed | Deleted via `Invoice::find(1)->delete()`, confirmed `Invoice::count() === 0` afterward |
| One custom role (id 12, `wp1b-temp-custom-role`) | `Role::create()` (Spatie) | Both existing roles (`operator`, `user`) are built-in by name; no non-built-in role existed to exercise the `Custom` badge / `--surface-elevated` | Deleted via `Role::find(12)->delete()`, confirmed `Role::count() === 2` afterward |
| One ticket (id 4) temporarily flipped `open → closed` | Direct model update on an existing fixture ticket | No closed ticket existed to exercise the `closed` badge / `--surface-elevated` | Restored `open`, confirmed via fresh query afterward |

No new permanent seed data was added. No factory or seeder file was modified. All three actions ran through ordinary Eloquent/factory calls against the existing dev database, verified counts before, during and after.

### A3.11 Compiled CSS verification

Inspected `public/build/assets/app-*.css` after `npm run build`, not source alone:

- All twelve lines present (six variables × two theme blocks); each resolves to its intended `var()` target in both scopes.
- No duplicate later definition overrides any of the six — confirmed by regex search of the compiled output for each variable name (each appears exactly twice: once per theme block).
- No consumer of the six variables computes `transparent`/invalid any more — spot-checked via the rendered pages in A3.9.
- WP1a's freed semantic names (`text-primary`, `text-secondary`, `text-muted`, `bg-surface`, etc.) remain free of hand-written collisions — re-verified: `.legacy-*` still the only thirteen defined utilities in `@layer utilities`, byte-identical to the WP1a commit (`git diff --stat` on `app.css` shows only additions, zero deletions).

CSS bundle: **78,770 bytes raw / 15,346 bytes gzip**, against the WP1a baseline of 78,358 / 15,305 (A2.8) — a **412-byte / 41-byte** delta, exactly proportionate to twelve short `var()` declarations. No unexpected size change.

### A3.12 WP1a regression check

- `git diff --stat` on `app.css`: **18 insertions, 0 deletions** — the thirteen `.legacy-*` utility definitions are untouched.
- All six React files WP1a touched (`login.tsx`, `forgot-password.tsx`, `dashboard/index.tsx`, `projects/index.tsx`, `time/index.tsx`, `task-title-cell.tsx`) and the two additional files carrying the three deliberately-preserved `text-primary` sites (`tasks/index.tsx`, `time/allocation.tsx`) show **zero diff** in this session — the ten React `text-primary` decisions are unchanged.
- No `dark:`/`focus:`/hover variant of any of the nine WP1a-renamed utilities was touched or reintroduced.
- No Direction D semantic token (`--canvas`, `--rail`, `--drawer`, Direction D `--surface`/`--text`/`--accent`, `--live`, etc.) was added.

### A3.13 Tests / static guards

No new automated test was added, consistent with the brief's preference for durable static/compiled-output verification over brittle snapshots for this kind of CSS-value slice, and because no clean existing seam protects "a CSS custom property resolves to X" more durably than reading the compiled output directly (a Vitest/Pest test asserting a specific `getComputedStyle` value would need jsdom, which doesn't implement CSS custom property cascade resolution — a real gap, not a shortcut taken here — so it was verified in real Chromium instead, per the brief's explicit allowance). Verification relied on: the compiled-CSS regex sweep (A3.11), the Chromium visual/contrast pass (A3.5, A3.7, A3.9), and the source-search-based F2 census and WP1a regression checks (A3.2, A3.12).

### A3.14 Documentation changes

This amendment; the `Amendments` line and Contents entry at the top of the epic; WP1b's line in [§28](#28-work-packages) marked Complete with a cross-reference.

### A3.15 Validation results

`./dev check` (via `bash dev`, the `./dev` launcher itself still lacking `+x` — pre-existing, A2.16, unrelated to WP1b): Pest green (933 passed, 3749 assertions), Pint green, `git diff --check` clean. The first full run's `npm run check` step reported 14 Vitest failures across 11 unrelated component-test files, all `Test timed out in 5000ms` under heavy load (`jsdom` created 54 times per run, ~45–48% of run time per Vitest's own diagnostic) — a resource-contention pattern, not a code regression: `app.css` is inert to `jsdom`-rendered component tests (jsdom does not load or resolve real compiled CSS), and none of the failing files touch anything WP1b changed. A standalone re-run reproduced only one of the fourteen (`milestone-form-dialog.test.tsx`, the same pre-existing flake noted in A2.16), which then passed cleanly in isolation. A final full `npm run check` (wayfinder, typecheck, lint, format, all 378 Vitest tests, production build) ran clean.

### A3.16 Cleanup / ownership

- All three temporary fixtures deleted/restored, counts verified back to baseline (A3.10).
- All temporary Playwright verification scripts written to the container project directory, removed before finishing; none tracked by git.
- No dependency, font, or generated-artefact file was added or is tracked.
- `git status` shows exactly one changed file (`app.css`) plus this documentation file.
- No root-owned files; no business data mutated beyond the disposable, fully-cleaned-up fixtures above.

### A3.17 Deviations / findings

1. **A3.7** — a second contrast debt, on `--surface-accent`/`--accent` in dark mode (3.28:1, FAIL AA), not documented by Amendment 1 because A1.3's verification appears to have been light-mode only. Handled the same way as the known `--surface-muted` debt: kept the only compatible mapping, documented, handed forward — not silently fixed, not silently hidden, and not blocking, since no compatibility-safe alternative exists and the failure is inherent to pairing any available tinted-surface token with the existing `--accent` text colour at 12px/500-weight, not a symptom of choosing the wrong variable.
2. No other deviation. The F2 census matched exactly; the accepted `--surface-elevated` and `--accent-success` mappings both verified as specified; WP1a's namespace and React decisions confirmed fully intact.

### A3.18 Files changed

`src/resources/css/app.css` (18 insertions, 0 deletions) — plus this documentation update. No other source file touched.

### A3.19 WP1c handoff

WP1c may start. It owns, and WP1b did not touch:

- The Direction D semantic token layer (`--canvas`, `--rail`, `--drawer`, `--surface` and its `-sunken`/`-hover`/`-selected` states, `--rule`/`--rule-control`/`--rule-strong`, Direction D `--text`, `--accent`/`--accent-hover`/`--accent-soft`/`--accent-line`, `--live`, `--ink`/`--on-ink`, `--danger`/`--warning`/`--success` restyles, `--focus`, `--scrim`) via `@theme inline`, and the safe alias remappings from [§8.3](#83-alias-mapping).
- Two contrast debts to hand to WP2/Finance's `Status`/badge component work, not to fix inline in WP1c: `--surface-muted`/`--text-muted` (A1.3, re-confirmed A3.7) and the newly found `--surface-accent`/`--accent` dark-mode pairing (A3.7). Both are pre-existing-pattern failures that become visible, not created, once their variables are real.
- Seeding an invoice, a CRM company and a published CMS page before running the full legacy-compatibility matrix (A1.15/C13, A2.17) — still zero of each in the dev database; this session's invoice fixture was created and deleted, not left behind.
- WP1c's own token registration is unobstructed: all six WP1b variables and all thirteen WP1a `legacy-*` utilities are stable, defined once each, at the correct existing theme layer, with no competing second token source introduced.

---

## Amendment 4: WP1c Results (2026-09-25)

WP1c ran on `feature/epic-013-direction-d-shell` at `15fa334` (the committed WP1a+WP1b result — see A4.19 finding 1), working tree clean at start. **WP1c is now Complete.** The production change is the Direction D semantic token layer in `app.css`, two measured alias remaps, and a mechanical isolation of the only three Tailwind colour tokens whose names Direction D claims (7 Blade checkbox classes, 2 flash variants). No route, migration, permission, dependency, package-manifest, font, primitive or shell file changed. The full gate is green on the WP1c tree; the literal `./dev check` is blocked only by two pre-existing environment conditions outside the diff (A4.17). **Where this amendment conflicts with the body or with prior amendments, this amendment wins for WP1c-scoped facts.** EPIC-013 remains **Planned**.

### A4.1 Method

- **Isolation.** The running containers bind-mount the main checkout's `src`, not a worktree, so the WP1c tree was copied into the `portal_app` container's own filesystem (`/tmp/wp1c/{base,post}`, never a bind mount) with `node_modules` linked and `vendor` copied. `base` is `15fa334`; `post` is the WP1c tree. Each copy was served by its own `php artisan serve` against the same dev database, so every comparison is between two real applications differing only by this slice.
- **Build fidelity.** A `base` build with the dev tree's compiled-view cache is **byte-identical** to the live `public/build` CSS (sha `49b95615…`, 78,770 B — A3.11's figure). Because that cache contains stale and orphaned templates (A4.19 finding 3), both copies were then rebuilt from a freshly compiled view cache (`view:clear` + `view:cache`) so the before/after comparison reflects source, not residue.
- **Browser.** Chromium 1243 via Playwright 1.63 (the container-installed browser WP0–WP1b used). Every colour was read back as rendered sRGB through a canvas, never taken from the design document.
- **Canonical source.** Token names and values are taken from [Direction D §2.2](../design/direction-d-design-system.md#22-colour-tokens), §2.2 *Shadows* and §16; `direction-d-tokens.css` was used only to cross-check values. Where the two differ, the spec won: it names `warning`/`success`/`progress-fill`/`progress-track`/`surface-sunken`/`surface-selected` and adds `live-text`, where the mockup file has `warn`/`ok`/`fill`/`track`/`sunken`/`sel` and no `live-text`. No mockup-only name (`card-shadow`, `rule-2`, `.st`, …) entered production.

### A4.2 Canonical tokens implemented

Every token in §2.2 plus the two §2.2 shadows and the §16 motion values. **Tailwind exposure rule:** each colour token `x` is registered as `--color-x: var(--ds-x)`, so `bg-x`, `text-x`, `border-x`, `ring-x`, `outline-x`, `divide-x`, `fill-x`, `accent-x` … all exist; the three text tokens additionally register the §2.1 short forms `--text-color-{secondary,muted,faint}`, so the canonical text utilities are exactly `text-text`, `text-secondary`, `text-muted`, `text-faint`.

| Token | Light | Dark | Primary utilities | Live consumers after WP1c |
|---|---|---|---|---|
| `canvas` | `#F6F5F1` | `#0D1416` | `bg-canvas` | 0 |
| `rail` | `#EBE8E1` | `#091012` | `bg-rail` | 0 |
| `drawer` | `#F1EFEA` | `#10191C` | `bg-drawer` | 0 |
| `surface` | `#FFFFFF` | `#142023` | `bg-surface` | 0 |
| `surface-sunken` | `#EFEDE7` | `#0F181A` | `bg-surface-sunken` | 0 |
| `surface-hover` | `#E8E5DD` | `#162427` | `hover:bg-surface-hover` | 0 |
| `surface-selected` | `#FFFFFF` | `#18292D` | `bg-surface-selected` | 0 |
| `rule` | `#E3E0D8` | `#1E2D31` | `border-rule`, `divide-rule` | 0 (`fill-rule` artefact, A4.4) |
| `rule-control` | `#D2CEC3` | `#2A3D42` | `border-rule-control` | 0 |
| `rule-strong` | `#1A1B1E` | `#C8D7D9` | `border-rule-strong`, `bg-rule-strong` | 0 |
| `text` | `#1A1B1E` | `#E6EEEF` | `text-text`, `bg-text` (expected marker) | 0 |
| `text-secondary` | `#3F4248` | `#C3D1D3` | `text-secondary` | 0 |
| `text-muted` | `#5C5F66` | `#98AEB2` | `text-muted` | 0 (unrendered vendor view, A4.4) |
| `text-faint` | `#8A8D93` | `#6D8388` | `text-faint`, `bg-text-faint`, `border-text-faint` | 0 — decorative/placeholder only (§2.3.7) |
| `accent` | `#0B6A73` | `#7ADDE4` | `text-accent` | 0 (legacy consumers isolated, A4.4) |
| `accent-hover` | `#084E55` | `#B2F4F7` | `hover:text-accent-hover` | 0 |
| `accent-soft` | `#E3EEEE` | `#12292C` | `bg-accent-soft` | 0 |
| `accent-line` | `#0B6A73` | `#19E7F2` | `bg-accent-line`, `border-accent-line` | 0 |
| `live` | `#0B8792` | `#19E7F2` | `bg-live` | 0 |
| `live-soft` | `#E4F2F2` | `#0E2629` | `bg-live-soft` | 0 |
| `live-text` | `#0B6A73` | `#19E7F2` | `text-live-text` | 0 |
| `ink` | `#1A1B1E` | `#E6EEEF` | `bg-ink` | 0 |
| `on-ink` | `#FFFFFF` | `#0D1416` | `text-on-ink` | 0 |
| `danger` | `#B42318` | `#FF8A7A` | `text-danger`, `border-danger` | **1 component** — the React error flash (A4.4); plus the `destructive` alias (A4.5) |
| `danger-soft` | `#FBEAE7` | `#2A1715` | `bg-danger-soft` | 0 |
| `warning` | `#8A5A0B` | `#F2B45A` | `text-warning` | 0 (legacy consumer isolated) |
| `warning-glyph` | `#B7791F` | `#F2B45A` | `bg-warning-glyph` | 0 |
| `warning-soft` | `#FAF0DC` | `#2A2013` | `bg-warning-soft` | 0 |
| `success` | `#2B7A4B` | `#6FD39A` | `text-success` | 0 (legacy consumer isolated) |
| `success-glyph` | `#2E8B57` | `#4CCB86` | `bg-success-glyph` | 0 |
| `progress-fill` | `#1A1B1E` | `#C8D7D9` | `bg-progress-fill` | 0 |
| `progress-track` | `#E4E1D9` | `#1E2D31` | `bg-progress-track` | 0 |
| `stage-future` | `#C9C5BA` | `#34494E` | `border-stage-future` | 0 |
| `focus` | `#0B6A73` | `#19E7F2` | `ring-focus`, `outline-focus` | 0 direct; the `ring` alias (A4.5) |
| `scrim` | `rgba(26,27,30,.18)` | `rgba(0,0,0,.45)` | `bg-scrim` | 0 |
| `shadow-card` | `0 1px 2px rgba(26,27,30,.05), 0 0 0 1px #E3E0D8` | `0 0 0 1px #213136` | `shadow-card` | 0 |
| `shadow-overlay` | `0 14px 36px rgba(26,27,30,.16), 0 0 0 1px #E3E0D8` | `0 14px 36px rgba(0,0,0,.55), 0 0 0 1px #2A3D42` | `shadow-overlay` | 0 |
| `motion-fast/base/panel/sheet` | 120 / 160 / 200 / 240 ms (theme-invariant) | — | `duration-motion-{fast,base,panel,sheet}` | 0 |
| motion easing (enter / exit) | `cubic-bezier(.2,0,0,1)` / `cubic-bezier(.4,0,1,1)` | — | `ease-motion`, `ease-motion-exit` | 0 |

**Deliberately not added:** radius, spacing and the §3.2 type scale (the contract lists them as usage tables, not as token-level foundation values assigned to this slice), a `progress` *expected-marker* token (§11.3 specifies it as the existing `text` colour, available as `bg-text`), `info`-family tokens (Direction D uses `accent` for informational status), and fonts (WP1d).

### A4.3 Theme architecture

```
@import 'tailwindcss'; @source …                       unchanged
@theme { fonts, --color-brand-* }                       unchanged (fonts are WP1d)
@theme inline { Direction D → Tailwind }                NEW  --color-<token>: var(--ds-<token>) …
@theme inline { shadcn + legacy aliases }               kept; accent/success/warning moved to legacy-*
:root { --ds-motion-* }                                 NEW  theme-invariant
:root, [data-theme="light"] { --ds-* ; aliases ; raws } Direction D light values first, then the legacy layer
[data-theme="dark"]          { --ds-* ; aliases ; raws } Direction D dark values first, then the legacy layer
html/body base defaults                                 unchanged (still legacy --bg-base / --text-primary)
@layer utilities { .legacy-* }                          unchanged (WP1a)
```

- **One source.** The raw Direction D hex values exist only in the two theme blocks of `app.css`; nothing else in the repository defines or references them (verified by search; the only other files containing any of the palette hexes are the two canonical brand SVGs, untouched).
- **The `--ds-` custom-property namespace is required, not stylistic.** Seven canonical names are already live legacy raw variables with different values: `--accent`, `--accent-hover`, `--danger`, `--success`, `--warning`, `--text-secondary`, `--text-muted`, together backing ~900 Blade `var()` sites. Defining Direction D under its bare names would be exactly the flag-day swap §8.1 rejected, one level down (finding **F3**, A4.4). The prefix is confined to the theme layer: components consume the utilities, which carry the canonical names (`bg-surface`, `text-muted`), not the variables. §8.1's Option B rejection (prefixed *utilities*) is therefore not reopened. Because `@theme inline` variables are not emitted at runtime (verified in the compiled CSS), a later component that genuinely needs a raw variable in an arbitrary value (e.g. the live-dot halo) references `var(--ds-live-soft)`; when the legacy raws retire, dropping the prefix is a local edit.
- **The theme mechanism is unchanged.** Same selectors (`:root, [data-theme="light"]` / `[data-theme="dark"]`), no third theme, no change to either root view's bootstrap script, `useAppearance`, or the Blade nav toggle.
- **The page base is not adopted.** `html`/`body` stay on legacy `--bg-base`/`--text-primary`; no page, layout or primitive references a Direction D utility.

### A4.4 Collision audit

Searched: every `.blade.php`/`.tsx`/`.ts`/`.js` under `src/resources` (token-level scan with variant prefixes and `!`/opacity modifiers stripped), `app.css`, inline `<style>` blocks (only the self-contained `welcome.blade.php`, excluded as in A1.2), the vendor pagination views and compiled views that `@source` also scans, runtime `classList`/`className` assignments in Blade scripts, and dynamically built class names (`` `text-${…}` ``). Then proved against the compiled output of a throwaway probe build containing every Direction D utility.

| # | Finding | Evidence | Resolution |
|---|---|---|---|
| **F3** | **Custom-property collision.** 7 canonical names are live legacy raw variables (A4.3) | `app.css` + ~900 `var()` sites | `--ds-*` namespace for the canonical layer; legacy raws untouched |
| **F4** | **Tailwind-token collision.** `accent`, `success`, `warning` were already registered as shadcn/legacy colour tokens (`--color-accent: var(--accent)` etc.) with live consumers: `accent-accent` × 7 (Blade checkboxes: `operator/tickets/{index ×2,show}`, `tickets/show`, `admin/roles/{create,edit}`, `admin/users/show`) and `border-/text-success`, `border-/text-warning` (React `FlashRegion`, its only consumer) | census + compiled CSS | **Isolated, the WP1a pattern:** the legacy meanings move to `--color-legacy-{accent,success,warning}` with **identical** `var()` targets; the 9 consumer sites are renamed (`accent-legacy-accent`, `border-/text-legacy-success`, `border-/text-legacy-warning`); the canonical names are then free for Direction D. Compiled declarations are byte-identical (`.accent-legacy-accent{accent-color:var(--accent)}` ≡ the old `.accent-accent`), tailwind-merge resolves the new names exactly as the old ones, and all 60+ checkboxes in the matrix compute the same `accent-color` in both themes before and after |
| F5 | **Latent dead utility.** `FlashRegion`'s `error` variant has always carried `border-danger text-danger`, but no `danger` token existed, so the error flash rendered in inherited ink with a `currentColor` border (tailwind-merge had already dropped `border-border`) | compiled CSS (no rule), Chromium | **Activated deliberately**, the A2.5 sites 8–10 precedent: intent is unambiguous (the other three variants are coloured), one reviewed component, and it now resolves to Direction D `danger` at **6.57:1** light / **6.40:1** dark on the Alert's card. The only Direction D adoption in the slice; see A4.9 and A4.19 finding 2 |
| — | `text-muted` / `text-secondary` vs shadcn `muted` / `secondary` | Tailwind 4.3.1 resolves `text-*` from `--text-color-*` before `--color-*` (verified in `tailwindcss/dist/lib.js`) | `text-muted`/`text-secondary` → Direction D; `bg-muted`/`bg-secondary` stay shadcn. Neither text form had a consumer (A2.8) |
| — | `fill-rule` | 12 Blade SVG **attributes** (`fill-rule="evenodd"`) are scanned as a candidate, so `.fill-rule{fill:var(--ds-rule)}` is generated | Benign: the selector is a class no element carries. Recorded, not changed |
| — | `text-muted` (Bootstrap's own class) | Laravel's `bootstrap-5` pagination view is in the `@source` glob | Benign: the app never selects the Bootstrap paginator |
| — | `bg-surface`, `hover:bg-surface`, `hover:bg-surface-hover`, `accent-accent` in stale compiled views | `storage/framework/views` holds pre-WP1a compiles and an orphan of the deleted `time/allocation.blade.php` | Generates dead rules in a dev-tree build only; no rendered markup can carry them. A4.19 finding 3 |
| — | `hover:bg-surface` (WP1a's A2.3 hazard) | 0 live occurrences | Stays neutralised — WP1a's rename held |
| — | Every other canonical name (`canvas`, `rail`, `drawer`, `surface-*`, `rule-*`, `text`, `text-faint`, `accent-*`, `live*`, `ink`, `on-ink`, `*-soft`, `*-glyph`, `progress-*`, `stage-future`, `focus`, `scrim`, `shadow-card`, `shadow-overlay`) | 0 occurrences anywhere; no dynamic construction or runtime toggling | Free |

**Result:** in the clean post-WP1c build exactly four generated rules read a `--ds-*` variable — `text-danger`/`border-danger` (F5, deliberate), `fill-rule` and `text-muted` (both benign, never rendered). No existing live markup silently picks up a Direction D value.

### A4.5 Compatibility alias decisions (the §24.2 alias register, opened)

**Rule applied:** an alias is remapped only if, on every background it is actually rendered on, the measured contrast is **not reduced** in either theme and no control boundary weakens. §8.3's "stays AA" criterion is necessary but not sufficient ([Direction D §2.4.3](../design/direction-d-design-system.md#24-migration-compatibility-implementation-constraint): *"Where a mapping would reduce contrast … keep the legacy value"*). Measured in Chromium from the compiled stylesheet, on the legacy `background` / `card` / `muted` surfaces.

| Alias | Consumers (Tailwind) | Prior target | Final target | Changed? | Evidence / rationale |
|---|---:|---|---|---|---|
| `ring` | 9 (`focus-visible:ring-ring` — Button, Input, Textarea, NativeSelect, links) | `var(--accent)` indigo | **`var(--ds-focus)`** | **Yes** | Light 6.29 → **6.32** (equal/better); dark 4.51 / **3.28** / 3.97 → **13.19 / 9.61 / 11.62**. Fixes a near-threshold dark focus ring on cards. Focus-only, no layout effect. React only (Blade focus rings use `currentColor`) |
| `destructive` | 8 (`text-destructive` × 7 inline errors, `bg-destructive` × 1 Button variant) | `var(--danger)` red-600 / red-400 | **`var(--ds-danger)`** | **Yes** | Text light 4.77 / 4.56 → **6.57 / 6.29**; dark 6.97 / 5.08 / 6.14 → **8.79 / 6.40 / 7.75**; destructive fill with its unchanged foreground 4.77 → **6.57** light, 6.97 → **8.79** dark. Also keeps React's reds consistent with the F5 error flash |
| `destructive-foreground` | 1 | white / gray-950 | unchanged | No | Still correct on the new fill (above) |
| `primary`, `primary-foreground` | 24 / 3 | indigo / white | unchanged | **No — held** | A4.6 |
| `foreground`, `card-foreground` | 27 / 6 | `--text-primary` | unchanged | No | `text` would **reduce** contrast: light 17.75 → 17.22, dark 19.27 → 17.11 (−2.2), and would split React text from inherited body text and Blade text |
| `muted-foreground` | 98 | `--text-secondary` gray-600 / gray-400 | unchanged | No | `text-muted` **reduces** light contrast 7.56 → 6.40 (−1.16) on white, 7.23 → 6.12 on `muted`; dark would improve. Mixed → hold |
| `border`, `input` | 65 / 9 | `--border-base` gray-300 / gray-700 | unchanged | No | `rule-control` **weakens dark control boundaries**: 1.42 → 1.29 on cards, 1.95 → 1.77 on the page (light would gain +0.1). Mixed → hold |
| `background` | 13 (incl. Input/Textarea/Select/outline Button fills) | `--bg-base` white / gray-950 | unchanged | No | Too broad: a whole-surface swap to warm paper / teal-black under every React form control, while `html`/`body` and all Blade pages stay legacy — a cross-renderer seam. Owned by WP2 (controls) / WP4 (shell) |
| `card` | 10 | `--bg-elevated` | unchanged | No | Identical in light, but every React card in dark would move `#1e2939 → #142023` against held borders — broad. WP4/WP7 |
| `muted`, `secondary`, `secondary-foreground`, `accent-foreground`, `info` | 15 / 1 / 3 / 0 / 2 | legacy | unchanged | No | No Direction D equivalent is safe or needed yet; deferred to the slice migrating their consumers (§8.3) |
| `accent`, `success`, `warning` (Tailwind names) | 7 / 2 / 2 | legacy indigo / green-600 / amber-500 | **names reassigned to Direction D**; legacy meanings kept as `legacy-accent` / `legacy-success` / `legacy-warning` with identical values | Names only — rendered values unchanged | F4 isolation (A4.4). Retire each `legacy-*` token when its consumer migrates: the 7 checkboxes with their Blade pages, the two flash variants with WP2's Alert restyle |
| Raw `--bg-*`, `--text-*`, `--border-*`, `--surface-*`, status families, `--accent*`, `--danger/success/warning/info`, `--shadow-*`, `--color-brand-*` | ~900+ Blade `var()` sites | — | unchanged | No | Frozen until Blade page bodies migrate (§8.3) |
| WP1b six orphans | 51 | A3.3 | unchanged | No | A4.15 |
| WP1a `legacy-*` utilities | 34 base + 32 variant | A2.4 | unchanged | No | A4.14 |

Net: **110 of 114 non-canonical theme declarations are byte-identical** in the compiled CSS; the four that differ are exactly `--ring` and `--destructive` in each theme.

### A4.6 `primary` hold

`--primary: var(--accent)` (indigo `#4f46e5` / `#6366f1`) is unchanged in both themes and pinned by a test (A4.16). No Button, badge or link was restyled; the matrix shows `bg-primary` buttons, `border-primary`/`text-primary` active tabs and the two `accent-primary` checkboxes computing the same colours before and after. WP2 flips `primary` to `ink` together with the Button restyle and updates that test deliberately.

### A4.7 Canonical contrast audit (measured)

Measured from the compiled post-WP1c stylesheet in Chromium, every canonical pair both themes; **0 failures**. Minimum observed per group (thresholds: 4.5 text, 3.0 non-text):

| Pair | Light min | Dark min | Backgrounds covered |
|---|---:|---:|---|
| `text` | 13.68 (surface-hover) | 12.80 (surface-selected) | canvas, surface, drawer, rail, sunken, hover, selected |
| `text-secondary` | 8.00 | 9.60 | same seven |
| `text-muted` (information-bearing) | **5.08** (surface-hover) | 6.48 | same seven — the contract's "4.5:1 on canvas and surface" holds (5.86 / 6.40 light) |
| `accent` link/info | 5.02 (surface-hover) | 9.64 | canvas, surface, accent-soft, drawer, hover |
| `accent-hover` | 8.63 | 13.63 | canvas, surface |
| `live-text` | 5.50 (live-soft) | 10.36 | canvas, surface, live-soft — contract claims 5.2:1+, confirmed |
| `live` glyph (non-text) | 3.73 (live-soft) | 10.36 | matches the contract's own 4.29 / 3.73 figures, which is why text uses `live-text` |
| `on-ink` on `ink` | 17.22 | 15.81 | — |
| `danger` text | 5.64 (danger-soft) | 7.27 | canvas, surface, danger-soft |
| `warning` text | 5.23 (warning-soft) | 8.71 | canvas, surface, warning-soft |
| `warning-glyph` (non-text) | 3.34 | 9.08 | canvas, surface |
| `success` text | **4.83** (canvas) | 9.09 | canvas, surface — the narrowest canonical text pair |
| `success-glyph` (non-text) | 3.89 | 8.09 | canvas, surface |
| `focus` ring (non-text) | 5.02 | 9.87 | all seven surfaces + accent-soft |
| `progress-fill` on `progress-track` | 13.18 | 9.61 | — |
| `rule-strong` | 15.79 | 11.25 | canvas, surface |
| `text-faint` | 3.05 / 3.33 | 4.65 / 4.17 | **Decorative/placeholder only** by contract (§2.3.7); not an information-bearing pair |
| `rule-control`, `rule` | 1.44–1.57 / 1.21–1.32 | 1.46–1.63 / 1.17–1.31 | Reported, not a contract claim — see A4.19 finding 5 |

### A4.8 Known legacy contrast debts (unchanged; not Direction D)

Kept separate from A4.7: these are legacy compatibility pairings that no Direction D token touches. Re-measured post-WP1c, identical to A3.7:

| Debt | Light | Dark | Owner |
|---|---:|---:|---|
| `--surface-muted` / `--text-muted` — invoice `draft`/`cancelled` badges | 2.49 | 2.35 | Finance `Status` adoption (WP2 provides `Status`) |
| `--surface-accent` / `--accent` — `open` ticket, `Built-in` role chips | 5.78 | **3.28** | Helpdesk/System `Status` adoption |

Neither badge was rewritten; the canonical layer is AA-clean, and these two remain legacy debt.

### A4.9 Legacy compatibility matrix

[§25.4](#254-legacy-compatibility-screen-matrix)'s ten screens plus the CMS surfaces and eight representative extras, each in **light and dark**, as `operator@intechral.test` (guest for auth), 1360 × 900, on the `base` and `post` servers. Per route: element-by-element computed text colour and composited background (with contrast), control border/fill/`accent-color`/ring, orphan-variable resolution, focus-probe ring, overflow, `data-theme`, and a rest-state full-page pixel diff (a base-vs-base run established the noise floor: session-list growth on `/profile`, a relative timestamp on `/operator/cms`).

| Route | Renderer | Light | Dark | Classification |
|---|---|---|---|---|
| `projects.create` | React | ring only | ring only | compatible normalization (focus ring) |
| `tasks.index` | React | 0 px | 0 px | unchanged |
| `projects.board` (#468) | React | 0 px | 0 px | unchanged |
| `profile.show` | React | ring only (+ session rows) | ring only | compatible normalization |
| `tickets.show` (#4) | Blade | 0 px | 0 px | unchanged |
| `operator.tickets.index` | Blade | 0 px | 0 px | unchanged (checkbox `accent-color` identical) |
| `roles.edit` (#2) | Blade | 0 px | 0 px | unchanged (41 checkboxes identical) |
| `billing.invoices.edit` (fixture) | Blade | 0 px | 0 px | unchanged |
| `billing.invoices.show` (fixture) | Blade | 0 px | 0 px | unchanged |
| `billing.invoices.index` (fixture, draft badge) | Blade | 0 px | 0 px | unchanged (debt A4.8 unchanged) |
| `crm.companies.show` (fixture) | Blade | 0 px | 0 px | unchanged |
| `login` | React | ring on the auto-focused field | same | compatible normalization |
| `cms.show` (fixture page) | Blade | 0 px | 0 px | unchanged |
| `operator.cms.index` | Blade | noise (54 px, = base-vs-base) | timestamp only | unchanged |
| `dashboard` | React | ring only | ring only | compatible normalization |
| `time.index` | React | ring only | ring only | compatible normalization |
| `operator.tickets.show` (#4) | Blade | 0 px | 0 px | unchanged |
| `users.show` (#1) | Blade | 0 px | 0 px | unchanged |
| `forgot-password` | React | ring on the auto-focused field | same | compatible normalization |
| error flash → `profile.show` (real `SocialiteController` context failure) | React | ink → `danger` 6.57:1 | ink → `danger` 6.40:1 | **deliberate Direction D-adjacent change** (F5) |
| error flash → `login` (guest) | React | same | same | **deliberate Direction D-adjacent change** (F5) |

**Regressions: none.** Every route returned 200 with the correct `data-theme`; zero horizontal overflow; zero text element's contrast decreased except the F5 flash text (a dead-utility defect resolving to its intended colour, still ≥ 6.4:1); zero control boundary changed; zero F2/WP1b orphan resolved differently; no transparent surface introduced. Every Blade route is pixel-identical at rest.

### A4.10 Fixtures

The dev database held 0 invoices, 0 CRM companies and 0 CMS pages (A1.15/C13). Created through the existing factories, pinned to existing users so no user was created, and deleted after the matrix:

| Fixture | Mechanism | Purpose | Cleanup |
|---|---|---|---|
| Invoice #2 `INV-WP1C` (draft; client user 2, created by operator 1) + 2 line items | `Invoice::factory()->draft()`, `InvoiceItem::factory()->count(2)` | Screens 8 (edit, show, index; `--surface-muted` badge) | Deleted; counts back to 0 |
| CRM company #1 + contact #1 | `CrmCompany::factory()`, `CrmContact::factory()` | Screen 9 | Deleted; counts back to 0 |
| CMS page #1 `wp1c-fixture-page` (published) | `CmsPage::factory()->published()` | `cms.show`, `operator.cms.index` | Deleted; count back to 0 |

Baseline and final counts: users 3, invoices 0, invoice_items 0, crm_companies 0, crm_contacts 0, cms_pages 0, tickets 2, projects 4, tasks 12, roles 2, organizations 0, time_entries 3. Pre-existing residue left untouched: user #38 (`daren.dach@example.net`, operator) and the E2E projects noted in A1.17.

### A4.11 Theme first paint

Real Chromium, `post` build, 20 cold document loads: stored preference **and** OS preference × light/dark × {auth `login`, React `/dashboard`, Blade `/operator/tickets`, React → Blade via a real link, Blade → React via a real link}. On the **first animation frame** `data-theme` was the expected value, the `<html>` background was already the theme's `--bg-base` (`rgb(255,255,255)` / `oklch(0.13 0.028 261.692)`), the stylesheet was loaded, and a `MutationObserver` recorded **no subsequent flip**. **20/20 pass.** WP1c adds only custom properties inside the existing theme blocks; the bootstrap scripts, their placement and `localStorage['theme']` are untouched.

### A4.12 Generated-CSS verification

From a throwaway probe build (every Direction D utility plus the legacy families, container-only, deleted):

- Each Direction D utility compiles to one rule on its `--ds-*` variable: `.bg-canvas{background-color:var(--ds-canvas)}`, `.bg-surface`, `.bg-drawer`, `.hover\:bg-surface-hover:hover`, `.text-text{color:var(--ds-text)}`, `.text-secondary{color:var(--ds-text-secondary)}`, `.text-muted{color:var(--ds-text-muted)}`, `.text-faint`, `.border-rule`, `.border-rule-control`, `.divide-rule` (child selector), `.ring-focus{--tw-ring-color:var(--ds-focus)}`, `.outline-focus`, `.text-accent{color:var(--ds-accent)}`, `.bg-live`, `.text-live-text`, `.bg-ink`/`.text-on-ink`, `.text-danger`, `.text-warning`, `.text-success`, `.shadow-card{--tw-shadow:var(--ds-shadow-card);…}` (a shadow, not a colour), `.duration-motion-panel`, `.ease-motion`.
- Legacy utilities remain separate: `.bg-muted{…var(--muted)}`, `.bg-secondary`, `.text-muted-foreground`, `.bg-primary`/`.text-primary{…var(--primary)}`, `.bg-background`, `.bg-card`, `.border-border`, `.ring-ring{…var(--ring)}`, `.accent-legacy-accent{accent-color:var(--accent)}`, `.text-legacy-success{color:var(--success)}`, and all thirteen `.legacy-*` hand-written utilities.
- Each of the 43 `--ds-*` variables is declared exactly where intended — once per theme block (37) or once in `:root` (6 motion) — with no later override. Light and dark values match A4.2.

### A4.13 CSS / bundle size

| Asset | Before | After | Delta |
|---|---:|---:|---:|
| `app.css`, dev-tree view cache (= live, A3.11) | 78,770 B raw / 15,262 B gzip-9 | 81,130 / 16,056 | **+2,360 / +794** |
| `app.css`, clean view cache (source-only) | 77,545 / 15,127 | 79,728 / 15,871 | **+2,183 / +744** |
| `app.js` (Blade entry) | 49,294 | 49,294 | 0 (same hash) |
| `app.tsx` entry | 350,487 | 350,487 | 0 (hash changed only through chunk-name references) |
| `app-layout` (shell chunk) | 101,956 | 101,956 | 0 (same cascade) |
| `wayfinder` (shared chunk holding `FlashRegion`) | 39,306 | 39,334 | **+28** — exactly the four lengthened `legacy-*` class strings |
| `board`, `allocation` | 61,326 / 234,321 | same | 0 |

The ~2.2 KB is 74 colour/shadow declarations plus ~60 `@theme inline` registrations. Since `@theme inline` emits nothing until used, the growth is the theme blocks themselves, so later utilities cost bytes only when adopted. Gzip is `gzip -9` throughout (A3.11 used Vite's reported gzip, hence its slightly different 15,346).

### A4.14 WP1a regression check

- The thirteen `.legacy-*` rules in the compiled CSS are byte-identical before and after (md5 match); `@layer utilities` is untouched in source.
- The WP1a namespace is still vacated: no unprefixed base or variant consumer of `bg-surface`, `text-primary` (outside the three deliberate active-tab sites and the `hover:`/`group-hover:` accent sites A2.5/A2.6 kept), `text-secondary`, `text-muted`, `bg-base`, `bg-elevated`, `border-subtle`, `border-base`, `text-inverse`, `btn-accent` or `shadow-theme-*` exists in live source; `hover:bg-surface` has 0 live sites.
- The ten React `text-primary` decisions are untouched: the seven `legacy-text-primary` sites and the three active tabs (`tasks/index.tsx`, `time/index.tsx`, `time/allocation.tsx`) show zero diff, and the matrix shows identical computed colours on `/tasks`, `/time`, `/dashboard`, `login`.

### A4.15 WP1b regression check

- All six orphans are defined once per theme block with their A3.3 mappings, including `--surface-elevated: var(--bg-surface)` and `--accent-success: var(--success)` (compiled-CSS check and the A4.16 test).
- No orphan element resolved differently in any matrix route (0 changes across all `[style*="--surface-"]`, `[style*="--border-muted"]` and `accent-success` elements).
- No compatibility variable became undefined: 114 non-canonical declarations compared, 110 byte-identical, 4 intended (A4.5).

### A4.16 Tests / static guards

New: `tests/Unit/Configuration/DirectionDThemeContractTest.php` (39 cases). It asserts **names and wiring, never colour values**:

- every one of the 35 canonical colour tokens is declared exactly once in each theme block and exposed as `--color-x: var(--ds-x)`, so a missing dark value, which would otherwise silently inherit the light value, fails;
- the text short forms, both shadows and the motion tokens are exposed;
- the full compatibility layer (shadcn aliases, raw families, WP1b orphans, the three `legacy-*` isolation tokens) stays defined in both themes;
- `primary` stays `var(--accent)` until WP2 changes it deliberately;
- the six WP1b mappings hold.

Mutation-checked in the container copy: deleting one dark token, flipping `primary`, and reverting `--surface-elevated` each failed their test (3 failed / 36 passed); restored byte-identically. No snapshot of the stylesheet, no compiled-CSS parser.

### A4.17 Validation results

- **Post-WP1c tree** (container copy with a real `vendor`): Pint `--test` pass (244 files); `npm run check` pass (Wayfinder, `tsc`, ESLint `--max-warnings=0`, Prettier, **378/378** Vitest, production build); Pest **972 passed / 4000 assertions** (933 + 39 new).
- **`./dev check` from the worktree:** `bash dev check` was run from the worktree (the launcher still lacks `+x`, A2.16). **Its container steps cannot see a worktree**: `portal_app` bind-mounts the main checkout's `src`, so Pint, `npm run check` and Pest ran against the main checkout, whose source equals `15fa334` (Pest's 933 tests, not 972, confirm it). Results:

- `git diff --check`: pass (worktree).
- Pint: pass.
- Pest: **933 passed / 3749 assertions**.
- **CLI self-tests fail only because `dev` is tracked `100644` with `core.fileMode=false`.** With a temporary `+x` on the worktree's copy they pass (196/196 assertions); the mode was restored and nothing was recorded.
- **`npm run check` passes Wayfinder, `tsc`, ESLint, Prettier and 378/378 Vitest, then `vite build` fails** with `EACCES … unlink public/build/assets/allocation-Dw-pDb0S.js`. The main checkout's `public/build/assets` and `manifest.json` have been **root-owned since 09:45 local**, before this session, so no uid-1000 build can replace them; the failed build wrote nothing.

Both failures are environment conditions outside the WP1c diff. The equivalent full gate against the WP1c tree (above) is green and is the evidence for this slice. Fixing either condition (tracking `dev` as `100755`; a `chown` of the main checkout's `public/build`) is left to the repository owner.
- `git diff --check` clean.

### A4.18 Cleanup / ownership

- Fixtures deleted, counts verified back to baseline (A4.10). The 30 database sessions the harness logins created (all `127.0.0.1` via `artisan serve`, 17:14–17:26 UTC) were deleted; sessions arriving through nginx were left alone.
- Both `artisan serve` processes stopped; `/tmp/wp1c` (copies, probe build, Playwright scripts, screenshots) removed from the container. WP1c's own verification wrote nothing into the main checkout. The only main-checkout effects are those `./dev check` always has, from the gate run and one diagnostic re-run of its `npm run check` step: `route:clear`, and Wayfinder regenerating its ignored TypeScript from unchanged routes. The build step failed before writing (A4.17), so the served `public/build` is unchanged (still `app-Cil4pvXJ.css`, 78,770 B).
- No scratch CSS, proof component, font file, package or manifest change; no tracked generated asset; no root-owned file in the worktree.

### A4.19 Deviations / findings

1. **WP1a and WP1b share one commit.** Amendment 3 records WP1b starting at `8af256c` (WP1a). That commit exists but is on no branch; the feature branch has a single `15fa334` "refactor: isolate legacy theme utilities" containing both slices (WP1a + the twelve WP1b lines + Amendments 2 and 3). Content is complete and correct; only the per-slice revertibility promised by [§24.3](#243-reversibility) is coarser than planned for those two slices. Not changed here.
2. **F5 is the only Direction D adoption, and it is a choice.** If the reviewer prefers WP1c to change nothing visible, the one-line alternative is `border-current` in place of `border-danger text-danger`, which freezes today's ink rendering until WP2's Alert restyle. The activation was chosen because it follows the A2.5 precedent and is strictly more legible.
3. **Stale compiled views feed the Tailwind build.** `@source '../../storage/framework/views/*.php'` scans every compiled template, including pre-WP1a compiles and an orphan of the deleted `time/allocation.blade.php`. In a dev-tree build this generates dead rules (after WP1c, `.hover\:bg-surface:hover`, `.hover\:bg-surface-hover:hover` and `.accent-accent` against Direction D values). No rendered markup can carry them, and a clean-checkout build (CI) has none, but the dev CSS size depends on local residue. Owner: the CI handoff ([§30](#30-ci-handoff)), which should build from a clean view cache, or a later decision to drop that `@source` line.
4. **§8.3's alias table was partly optimistic.** `foreground`, `muted-foreground`, `border`/`input`, `background` and `card` were listed as safe; measured, each reduces contrast or control-boundary strength somewhere, or swaps whole surfaces, so all are held. Only `ring` and `destructive` pass. The §8.3 plan also did not anticipate F3/F4.
5. **Control and hairline rules are below WCAG 1.4.11's 3:1** in both themes by design (`rule-control` 1.44–1.63). The contract makes no contrast claim for them, and the held legacy `border` has the same property (1.42–1.95). This does not block the token layer, but WP2's Input/Button restyle must make control identity not depend on the border alone (fill, label, focus ring), and should record how it satisfies 1.4.11.
6. `text-faint` measures 3.05–4.65 and stays decorative/placeholder-only. `success` text on `canvas` (4.83) is the narrowest canonical text pair; WP2's `Status` should not place `success` text on anything darker than `canvas`.

### A4.20 Files changed

`src/resources/css/app.css` · `src/resources/js/components/feedback/flash-region.tsx` · `src/resources/views/admin/roles/{create,edit}.blade.php` · `src/resources/views/admin/users/show.blade.php` · `src/resources/views/operator/tickets/{index,show}.blade.php` · `src/resources/views/tickets/show.blade.php` · `src/tests/Unit/Configuration/DirectionDThemeContractTest.php` (new) · this document.

### A4.21 WP1d handoff

WP1d may start. Its committed scope ([§28 WP1](#wp1--semantic-tokens-and-typography), G1/[A1.4](#a14-g1--self-hosted-woff2-confirmed-with-the-budget-arithmetic-corrected), [§9.3](#93-recommendation)) is:

- self-host IBM Plex Sans 400/500/600, IBM Plex Mono 400/500 and **static** Newsreader 400/500 as latin WOFF2 subsets under `src/resources/fonts/` (7 files, 143.4 KB against the 180 KB budget; Plex Sans latin-ext declared conditionally by `unicode-range`, uncounted);
- add the `@font-face` block to `app.css` with `font-display: swap`, and replace `--font-sans`/`--font-mono` (adding the Newsreader display family) in the existing `@theme` block;
- delete the fonts.bunny.net `<link>` and `preconnect` from **both** root views, and add `<link rel="preload">` for Plex Sans 400 and 500 only;
- run the density gate on `/admin/roles/{id}/edit` and `/operator/tickets` (C7) and the matrix again.

WP1d does **not** own the shared theme bootstrap (WP4/WP5, A1.7), BrandMark (WP4, C9), or any primitive. WP1c leaves it an unobstructed `@theme` block (fonts untouched) and a theme layer whose Direction D values need no change for typography.
