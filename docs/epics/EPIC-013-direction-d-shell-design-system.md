# EPIC-013: Direction D Application Shell and Design System Foundation

**Status:** Planned
**Class:** UX foundation (Product Roadmap [NEXT — Product/UX foundation](../product/product-roadmap.md#next--productux-foundation): *New application shell* + *Design system*)
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md) (canonical, approved 2026-09-25, revision 2)
**Product direction:** [Platform Product & UX Direction](../product/platform-product-ux-direction.md) · [Information Architecture](../product/information-architecture.md) · [Product Roadmap](../product/product-roadmap.md)
**Decision record:** [ADR-007: Inertia/React Frontend](../architecture/adr/ADR-007-inertia-react-frontend.md) · [ADR-002: Tailwind v4](../architecture/adr/ADR-002-tailwind-v4.md) · [ADR-003: Spatie Permission](../architecture/adr/ADR-003-spatie-permission.md)
**Prerequisites:** [EPIC-011A](./EPIC-011A-react-foundation-coexistence.md) (Implemented), [EPIC-011B](./EPIC-011B-dashboard-profile.md) (Implemented), [EPIC-011C](./EPIC-011C-authentication-invitations.md) (Verified), [EPIC-011D](./EPIC-011D-time-tracking-timer.md) (Verified), [EPIC-011E](./EPIC-011E-projects-kanban.md) (Verified), [EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md) (Verified)
**Brand prerequisite:** Satisfied — canonical owner-supplied SVGs are committed (see [§10](#10-brand-asset-consumption) for the one path discrepancy to reconcile)
**Planning baseline:** `main` @ `6ea4135`, working tree clean, verified 2026-09-25

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

- `src/resources/images/intechral-logo.svg` — full logo
- `src/resources/images/intechral-logo-compact.svg` — compact mark

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

### WP1 — Semantic tokens and typography

Four independently revertible slices.

- **WP1a** — rename the eight legacy utilities to a `legacy-` prefix across 45 Blade views; values unchanged. Resolves F1.
- **WP1b** — define the five orphan variables. Resolves F2.
- **WP1c** — add the Direction D token blocks (light + dark) via `@theme inline` on the existing `data-theme` contract; apply the safe alias remappings; **hold `primary`**.
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

| Gate | Question | Recommendation | Blocks |
|---|---|---|---|
| **G1** | Font delivery: self-host vs package vs external | **Self-host `woff2` subsets**, ≤ 180 KB latin budget ([§9](#9-typography-and-font-delivery)) | WP1d only |
| **G2** | Brand assets live at `src/resources/images/`; the contract says `…/images/brand/` | **`git mv` into `brand/`** to match the canonical contract ([§10.1](#101-canonical-assets-and-one-path-discrepancy)) | WP4 only |
| **G3** | Where viewer-facing CMS Pages (`cms.view`) lives under the eight-workspace IA | **Transitional ninth rail item**, gated `cms.view AND NOT cms.edit`, retired by the Knowledge/CMS epic ([§11.3](#113-the-viewer-facing-cms-problem)) | WP3 only |
| **S1** | Drawer persistence mechanism | **Server-stamped workspace + localStorage + shared pre-paint bootstrap**; cookie is the fallback ([§15](#15-drawer-state-and-persistence)) | WP4 |
| **S2** | Focus target after an Inertia visit | **Provisionally: no focus move + polite announcement**; decided by evidence ([§22.2](#222-q2--focus-on-inertia-navigation)) | WP4 |

None of these blocks the start of the epic. WP0 exists to close them.

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
