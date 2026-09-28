# EPIC-013: Direction D Application Shell and Design System Foundation

**Status:** Planned
**Class:** UX foundation (Product Roadmap [NEXT — Product/UX foundation](../product/product-roadmap.md#next--productux-foundation): *New application shell* + *Design system*)
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md) (canonical, approved 2026-09-25, revision 2)
**Product direction:** [Platform Product & UX Direction](../product/platform-product-ux-direction.md) · [Information Architecture](../product/information-architecture.md) · [Product Roadmap](../product/product-roadmap.md)
**Decision record:** [ADR-007: Inertia/React Frontend](../architecture/adr/ADR-007-inertia-react-frontend.md) · [ADR-002: Tailwind v4](../architecture/adr/ADR-002-tailwind-v4.md) · [ADR-003: Spatie Permission](../architecture/adr/ADR-003-spatie-permission.md)
**Prerequisites:** [EPIC-011A](./EPIC-011A-react-foundation-coexistence.md) (Implemented), [EPIC-011B](./EPIC-011B-dashboard-profile.md) (Implemented), [EPIC-011C](./EPIC-011C-authentication-invitations.md) (Verified), [EPIC-011D](./EPIC-011D-time-tracking-timer.md) (Verified), [EPIC-011E](./EPIC-011E-projects-kanban.md) (Verified), [EPIC-010D](./EPIC-010D-helpdesk-security-hardening.md) (Verified)
**Brand prerequisite:** Satisfied — canonical owner-supplied SVGs are committed at `src/resources/images/brand/` (path reconciled in WP0, gate G2)
**Planning baseline:** `main` @ `6ea4135`, working tree clean, verified 2026-09-25
**Amendments:** [Amendment 1 (2026-09-25)](#amendment-1-wp0-results-2026-09-25): WP0 results — G1 font delivery locked with measured payloads, G2 brand assets moved and consumption proven, G3 confirmed across seven actor profiles, S1 confirmed, S2 overturned in part, token and navigation baselines, fourteen plan corrections, WP1a handoff · [Amendment 2 (2026-09-25)](#amendment-2-wp1a-results-2026-09-25): WP1a results — legacy-namespace rename landed (13 utilities, 59+ call sites), the F1 census corrected again (a live `hover:bg-surface` collision-in-waiting found and neutralized, undercounted by the Amendment 1 methodology), all ten React `text-primary` sites individually and deliberately resolved, `./dev check` green · [Amendment 3 (2026-09-25)](#amendment-3-wp1b-results-2026-09-25): WP1b results — F2 census reproduced exactly (50+1 sites), six compatibility variables defined, `--surface-elevated → var(--bg-surface)` correction verified in Chromium, `--accent-success → var(--success)` verified, a second (previously undocumented) contrast debt found on the `--surface-accent`/`--accent` dark-mode pairing alongside the known `--surface-muted`/`--text-muted` one, both handed forward undisguised, `./dev check` green · [Amendment 4 (2026-09-25)](#amendment-4-wp1c-results-2026-09-25): WP1c results — the Direction D semantic layer lands (35 colour + 2 shadow + 6 motion tokens, light and dark, `--ds-*` custom properties exposed through `@theme inline`); a custom-property collision (F3) and a Tailwind-token collision (F4) found and resolved inside the slice; `ring → focus` and `destructive → danger` remapped on measured evidence, every other alias held, `primary` held; the ten-screen matrix plus CMS run in both themes on seeded fixtures with no regression; full gate green on the WP1c tree (the literal `./dev check` blocked only by two pre-existing environment conditions, A4.17) · [Amendment 5 (2026-09-25)](#amendment-5-wp1d-results-2026-09-25): WP1d results — IBM Plex Sans/Mono and static Newsreader self-hosted as ten WOFF2 faces (latin 143.3 KiB of the 180 KB budget, Plex Sans latin-ext conditional), OFL licences committed, fonts.bunny.net removed from both root views, Plex Sans 400/500 preloaded through one shared partial; zero external font requests, density gate 0 px and the matrix regression-free in both themes, no metric adjustment, WP1a–WP1c layers byte-identical, `./dev check` green · [Amendment 6 (2026-09-25)](#amendment-6-wp2-results-2026-09-25): WP2 results — `primary` flipped from indigo to Direction D ink after all 13 accent-meaning consumer groups were made explicit, Button/Input/Textarea/NativeSelect on one explicit control-height scale (36 px, font-independent), dialog/menu/alert/pagination restyled on the scrim/overlay/motion tokens with reduced-motion handled in the token layer, canonical `Status` (glyph + label + tone) and `Avatar` created and adopted by their live consumers, `Progress` reworked in place, `Section`/`PageHeader` reworked, `Tabs`/`Tooltip`/`Popover`/`Tag`/`Skeleton`/`EmptyState`/`ErrorState` deferred for want of a live consumer (no dependency added), the 21-route matrix regression-free (Blade pixel-identical), `./dev check` green (with a post-review remediation, A6.25: a dedicated `control-edge` token gives interactive control boundaries ≥ 3:1; the owner confirmed the narrowed component scope) · [Amendment 7 (2026-09-25)](#amendment-7-wp3-results-2026-09-25): WP3 results — `NavigationBuilder` reshaped to the presentation-neutral workspace contract (nine workspaces including the transitional G3 `resources` item, `context`/`presentation` split, `ContextKind`/`PanelDefault` enums, server-computed active state with explicit route names and most-specific-wins resolution, the three A1.10 collisions fixed and pinned), the "Manage" grouping retired from the payload and from both renderers with all six destinations preserved, `shell` + `auth.user.avatar` shared props added and the Blade `ShellComposer` replacing the `@php` builder call in the view layer, one temporary `LegacyShellNavigation` seam so WP3 ships before the shell exists, the R9 leakage and collision tests written **before** the reshape and then rewritten as the new contract's assertions (NavigationBuilderTest 3 → 60 cases, plus ShellContractTest and InitialsTest), no cookie added (S1 stands), `./dev check` green. **WP4 is not started: no Direction D shell exists** · [Amendment 8 (2026-09-26)](#amendment-8-wp4-results-2026-09-26): WP4 results — the Direction D operator shell is live for every Inertia page (`AppShell` as the single presentation boundary, `Rail`, `Drawer`, `UtilityBar`, `Breadcrumb`, `ViewSwitcher`, `NavSheet`, `AccountMenu`, `SkipLink`, `BrandMark`, `ShellLink`), all 12 `Page.layout` lines flipped and the pre-WP4 header, `NavigationLink` and the 0-byte favicon deleted; the panel's state, persistence and L pin land in `localStorage` with no cookie (S1 stands) and twelve malformed-storage cases asserted; the account menu is personal-only with server-derived initials and Appearance absorbing the theme toggle; width classes XL/L/M/S are CSS-only, with docked-vs-overlay decided by CSS alone; the S2 announce-and-repair focus policy implemented. **Real-browser validation earned its keep: six defects passed a green jsdom suite and were caught only in Chromium** (A8.12) — initial focus stolen from the first Tab, every width class inert because the shell CSS sat in `@layer components`, a docked panel dismissed by content clicks (breaking board drags), an unbidden 248px overlay covering the canvas at L/M, a duplicated heading outline, and an invented `+ ` label prefix. `navigationLegacy` left the Inertia payload and survives only for the Blade partial WP5 replaces. `./dev check` green. Post-review remediation (A8.18): the browser suite was found to be structurally overrunning Fortify's login limiter — 114 `POST /login` per run, 45 refused, 13 of them added by WP4's own spec — and now authenticates once per persona and reuses the cookies, taking logins to 10 per run with 1 refusal; the full suite remains at **50 passed / 13 failed** in six older specs whose root cause is unresolved, plus one unexplained HTTP 429 in 10 logins — recorded as outstanding follow-up (A8.18), not as a passing gate. **WP5 is not started: no Blade shell partials exist** · [Amendment 9 (2026-09-27)](#amendment-9-post-wp4-e2e-hardening-2026-09-27): POST-WP4 E2E hardening (not WP4, not WP5) — diagnosed why the six-spec failure group from A8.18 passed individually but failed together: parallel Playwright workers shared one Laravel session per persona, so one worker's flash/validation state could land in another's page, and a separate defect let a "fresh" manager context inherit the project's own operator auth. Remediated with a session minted per worker per persona (`support/auth.ts`), cookies-only and never written to disk; an explicit `contextFor('anonymous')` for contexts that must start signed out; and an explicit `workers: 3` cap, because a worker mints a persona's session at most once for its whole lifetime, so the cap bounds that persona's total real logins for the run regardless of spec-file count or machine core count. Three dedicated seeded fixtures (`e2e-login-flow`, `e2e-profile-mutation`, `e2e-signout`, via `DevSeeder`) moved authentication-subject flows off the reusable operator/member personas' own Fortify buckets. Full suite now 64/64 at normal parallel configuration (3 workers, ~3.3 min), 12 `POST /login` all 302, 0 `429`/`419`/5xx. `BrowserAuthContractTest` rewritten for the new architecture; login budget and identity table recorded in `docs/testing/e2e-browser-suite.md`. A separately reported dev-environment HTTP 400 was traced to the owner's browser sending a Cookie header nginx's default header-buffer limit rejects — unrelated to this work, no repository change · [Amendment 10 (2026-09-27)](#amendment-10-wp5-results-2026-09-27): WP5 results — Blade renders the canonical WP3 navigation model through Direction D shell partials (rail, docked-or-hidden panel, utility bar with breadcrumb, personal account menu, native-dialog nav sheet, brand mark) using the same `data-shell-*` hooks as React, so one unlayered geometry block lays out both renderers; `ShellComposer` bound once to `layouts.app`; one shared pre-paint bootstrap (`resources/js/shell/bootstrap.js`) inlined first in both roots, parity-tested against React's `resolvePanel` over 144 cases, 0 CLS measured; `LegacyShellNavigation`, `navigationLegacy`, the old nav bar, theme toggle and footer deleted; React→Blade→React with history, authorization, Resources, keyboard, XL/L/M/S and 200% reflow verified in Chromium; one Blade stacking defect and one vacuous WP4 seam guard found and fixed; full Playwright 74/74 on 3 workers, 12 logins all 302, 0 × 429/419/5xx

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
- [Amendment 5: WP1d Results (2026-09-25)](#amendment-5-wp1d-results-2026-09-25)
- [Amendment 6: WP2 Results (2026-09-25)](#amendment-6-wp2-results-2026-09-25)
- [Amendment 7: WP3 Results (2026-09-25)](#amendment-7-wp3-results-2026-09-25)
- [Amendment 8: WP4 Results (2026-09-26)](#amendment-8-wp4-results-2026-09-26)
- [Amendment 9: POST-WP4 E2E Hardening (2026-09-27)](#amendment-9-post-wp4-e2e-hardening-2026-09-27)
- [Amendment 10: WP5 Results (2026-09-27)](#amendment-10-wp5-results-2026-09-27)
- [Amendment 11: WP6 Results (2026-09-28)](#amendment-11-wp6-results-2026-09-28)

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
| `primary` | **Hold at legacy indigo initially** | **No** — Direction D primary is ink (L7). Repointing `primary` to `ink` turns every `bg-primary` button into a near-black slab and every `text-primary` link into body-coloured text across all 12 React pages at once. Flip in WP2 together with the Button restyle, not in WP1. **Done in WP2 — see [Amendment 6, A6.3](#a63-the-primary-alias-flipped-to-ink-after-every-consumer-was-resolved)** |
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

*Outcome of WP2 against this table: [Amendment 6](#amendment-6-wp2-results-2026-09-25). `Status` and `Avatar` were created; `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip` and `Popover` (and their three Radix dependencies) were deferred to the packages that own their first consumer (A6.10); `Badge` is retained as the tag primitive.*

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
- **WP1d — Complete (2026-09-25) — results in [Amendment 5](#amendment-5-wp1d-results-2026-09-25).** Self-host the three families per G1; delete the bunny.net `<link>` and `preconnect` from both root views; add the `@font-face` block and preloads.

**Exit:** `./dev check` green after each slice; the ten-screen compatibility matrix passes in both themes; no Direction D component exists yet.

### WP2 — Shared primitives

- Restyle `Button` (ink primary) **together with** the `primary`-alias flip, as one reviewable change with its own visual pass.
- Restyle `Input`, `Textarea`, `Label`, `DropdownMenu`, `Dialog`/`DialogShell`, `ConfirmationDialog`, `FormDialog`, `Alert`, `Pagination`.
- Rework `Progress` → `ProgressBar`/`Meter`; `SectionPanel` → `Section`; `PageHeader`.
- Add `Avatar`, `Status`, `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip`, `Popover` (three new Radix deps).
- Unit tests for semantics: roles, `aria-current`, `aria-valuetext`, glyph+label never colour-alone.
- **No page migrations for aesthetics.**

- **Complete (2026-09-25) — results in [Amendment 6](#amendment-6-wp2-results-2026-09-25).** Executed with one deliberate narrowing: of the additions, only `Status` and `Avatar` were built (live consumers); `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip`, `Popover` and the three Radix dependencies are deferred with named first consumers (A6.10).

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

- **Complete (2026-09-27) — results in [Amendment 10](#amendment-10-wp5-results-2026-09-27).** Blade renders the canonical WP3 model through the Direction D shell partials; one shared pre-paint bootstrap serves both roots; `LegacyShellNavigation`, `navigationLegacy`, `partials/nav.blade.php` and the authenticated footer are deleted. The timer pill remains WP6.

### WP6 — Global timer shell integration

- `TimerPill`, `TimerTray` (Radix Popover), `TimerControl`; delete `RunningTimerBar` after tests pass.
- Rework `timer-overlay.js` into the Blade pill/tray; delete the decorative `PALETTE`; preserve the `timerStarted`/`timerStopped` events.
- `TimerProvider` **unchanged**; no backend change.
- The full timer matrix from [§25.2](#252-vitest--rtl) plus Playwright #9, including the tick-localisation guard.

**Exit:** zero/one/many states correct on both renderers; reconciliation behaviour unchanged; no NEXT feature present.

- **Complete (2026-09-28) — results in [Amendment 11](#amendment-11-wp6-results-2026-09-28).** `TimerPill`, `TimerTray` (Radix Popover) and `TimerControl` render the Foundation scope on both renderers from one shared derivation module (`lib/timer-state.ts`), which also closes a pre-WP6 divergence: only React applied the clock offset. `RunningTimerBar`, `timer-overlay.js`, `timer-overlay.blade.php` and the decorative `PALETTE` are deleted; the `timerStarted`/`timerStopped` contract is preserved; `TimerProvider` reconciliation and the backend are untouched. One deviation: `TimerControl`'s **unavailable** state is deferred ([§31](#31-deferred-follow-on-work), A11.7). Implemented on the WP5 tree (`e10f2ab`); not yet committed.

### WP7 — Page frames and Home

- `PageFrame` (canvas/grid/reading), `EntityHeader`, `Strata`.
- Dashboard → **Home**: label, Direction D surface language, honest data only, no new props, route unchanged.
- Convert `projects/board` to `PageFrame width="canvas"` (a genuine wide-canvas win).
- Leave the other ten pages' containers alone.

**Exit:** Home is a real Direction D surface; nothing fabricated; other pages still work.

- **Complete (2026-09-28) — results in [Amendment 12](#amendment-12-wp7-results-2026-09-28).** `PageFrame` exists with all three width classes and owns page geometry, so `<main>` stays unpadded and a canvas page can reclaim the viewport; `EntityHeader` states a record and is the only thing that draws `Strata`, which Direction D §17 allows under entity headers alone. Home is a `grid` frame with its four metrics as a figure row rather than cards, built from the four existing props with the route, their shape and every `visit` mode unchanged, and nothing fabricated. `projects/board` moved onto `canvas` and gained the entity header, which retired the second breadcrumb it used to draw. `EmptyState` arrived with the consumer A6.10 named for it. The other ten pages' containers are untouched. One deviation: running timers on Home are not implemented, because WP6's pill now occupies that role (A12.6). Implemented on the WP6 tree (`8f2fc34`); not yet committed.

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
| `TimerControl`'s **unavailable** state (§12.3: disabled, with the reason given). No WP6 surface reaches it — its callers gate the whole control on `time.log`, and the billing lock is a server rejection surfaced after the click, not a precondition known before it. WP6's authoritative scope ([§18.1](#181-in-and-out), Direction D §12.0), its Exit, [§29](#29-exit-criteria) criterion 9 and the [§25.2](#252-vitest--rtl) matrix none of them require it | The package that first ships a surface which can reach the state — or the package that owns the disabled-reason treatment generally, whichever comes first |
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
| R1 | Token layer degrades unmigrated Blade screens (567 utility sites + 900 raw-variable sites) | Medium | High | F1 resolved by a mechanical rename before any token lands; F2 orphans defined; `primary` held until WP2 (flipped in WP2, Amendment 6); ten-screen matrix in both themes | Revert the single WP1c commit; WP1a/WP1b stand alone |
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
| `primary`, `primary-foreground` | 24 / 3 | indigo / white | unchanged | **No — held** | A4.6. **Superseded: flipped to ink in WP2, [A6.3](#a63-the-primary-alias-flipped-to-ink-after-every-consumer-was-resolved)** |
| `foreground`, `card-foreground` | 27 / 6 | `--text-primary` | unchanged | No | `text` would **reduce** contrast: light 17.75 → 17.22, dark 19.27 → 17.11 (−2.2), and would split React text from inherited body text and Blade text |
| `muted-foreground` | 98 | `--text-secondary` gray-600 / gray-400 | unchanged | No | `text-muted` **reduces** light contrast 7.56 → 6.40 (−1.16) on white, 7.23 → 6.12 on `muted`; dark would improve. Mixed → hold |
| `border`, `input` | 65 / 9 | `--border-base` gray-300 / gray-700 | unchanged | No | `rule-control` **weakens dark control boundaries**: 1.42 → 1.29 on cards, 1.95 → 1.77 on the page (light would gain +0.1). Mixed → hold |
| `background` | 13 (incl. Input/Textarea/Select/outline Button fills) | `--bg-base` white / gray-950 | unchanged | No | Too broad: a whole-surface swap to warm paper / teal-black under every React form control, while `html`/`body` and all Blade pages stay legacy — a cross-renderer seam. Owned by WP2 (controls) / WP4 (shell) |
| `card` | 10 | `--bg-elevated` | unchanged | No | Identical in light, but every React card in dark would move `#1e2939 → #142023` against held borders — broad. WP4/WP7 |
| `muted`, `secondary`, `secondary-foreground`, `accent-foreground`, `info` | 15 / 1 / 3 / 0 / 2 | legacy | unchanged | No | No Direction D equivalent is safe or needed yet; deferred to the slice migrating their consumers (§8.3) |
| `accent`, `success`, `warning` (Tailwind names) | 7 / 2 / 2 | legacy indigo / green-600 / amber-500 | **names reassigned to Direction D**; legacy meanings kept as `legacy-accent` / `legacy-success` / `legacy-warning` with identical values | Names only — rendered values unchanged | F4 isolation (A4.4). Retire each `legacy-*` token when its consumer migrates: the 7 checkboxes with their Blade pages, the two flash variants with WP2's Alert restyle *(retired in WP2, [A6.7](#a67-alert-flashregion-pagination))* |
| Raw `--bg-*`, `--text-*`, `--border-*`, `--surface-*`, status families, `--accent*`, `--danger/success/warning/info`, `--shadow-*`, `--color-brand-*` | ~900+ Blade `var()` sites | — | unchanged | No | Frozen until Blade page bodies migrate (§8.3) |
| WP1b six orphans | 51 | A3.3 | unchanged | No | A4.15 |
| WP1a `legacy-*` utilities | 34 base + 32 variant | A2.4 | unchanged | No | A4.14 |

Net: **110 of 114 non-canonical theme declarations are byte-identical** in the compiled CSS; the four that differ are exactly `--ring` and `--destructive` in each theme.

### A4.6 `primary` hold

`--primary: var(--accent)` (indigo `#4f46e5` / `#6366f1`) is unchanged in both themes and pinned by a test (A4.16). No Button, badge or link was restyled; the matrix shows `bg-primary` buttons, `border-primary`/`text-primary` active tabs and the two `accent-primary` checkboxes computing the same colours before and after. WP2 flips `primary` to `ink` together with the Button restyle and updates that test deliberately. *(Done: [A6.3](#a63-the-primary-alias-flipped-to-ink-after-every-consumer-was-resolved).)*

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
5. **Control and hairline rules are below WCAG 1.4.11's 3:1** in both themes by design (`rule-control` 1.44–1.63). The contract makes no contrast claim for them, and the held legacy `border` has the same property (1.42–1.95). This does not block the token layer, but WP2's Input/Button restyle must make control identity not depend on the border alone (fill, label, focus ring), and should record how it satisfies 1.4.11. *(Resolved in WP2: a dedicated `control-edge` token now draws the interactive boundary at ≥ 3:1, and `rule-control` stays a structural hairline. See [A6.25](#a625-post-review-remediation-resting-control-boundary-and-scope-confirmation).)*
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

---

## Amendment 5: WP1d Results (2026-09-25)

WP1d ran on `feature/epic-013-direction-d-shell` at `707a560` (WP1c), working tree clean at start. **WP1d is now Complete.** The production change is the Direction D font delivery: ten self-hosted WOFF2 faces with their SIL OFL licences under `src/resources/fonts/`, an `@font-face` block and three font roles in `app.css`, the fonts.bunny.net `<link>` and `preconnect` removed from both root views, and one shared preload partial. No route, migration, permission, dependency, package manifest, colour token, primitive, shell, brand or bootstrap file changed. `./dev check` is green from the main checkout. **Where this amendment conflicts with the body or with prior amendments, this amendment wins for WP1d-scoped facts.** EPIC-013 remains **Planned**.

### A5.1 Committed scope, as executed

The scope is exactly [A4.21](#a421-wp1d-handoff), which restates [§28 WP1](#wp1--semantic-tokens-and-typography) as corrected by G1/[A1.4](#a14-g1--self-hosted-woff2-confirmed-with-the-budget-arithmetic-corrected) (C5, C6, C7):

| Committed item | Result |
|---|---|
| Self-host Plex Sans 400/500/600, Plex Mono 400/500, **static** Newsreader 400/500 as latin WOFF2 under `src/resources/fonts/` | Done, 7 files, **146,724 B (143.3 KiB)** |
| Plex Sans latin-ext declared by `unicode-range`, not counted against the budget | Done, 3 files (48,884 B), fetched only when a latin-ext glyph renders (A5.6) |
| `@font-face` block in `app.css`, `font-display: swap`; replace `--font-sans`/`--font-mono`, add the display family in `@theme` | Done (A5.3) |
| Delete the bunny.net `<link>` and `preconnect` from **both** root views; preload Plex Sans 400 and 500 only | Done (A5.4) |
| Density gate on `/admin/roles/{id}/edit` and `/operator/tickets` (C7), then the matrix again | Done: 0 px (A5.8, A5.9) |

Not owned, and not touched: the shared theme bootstrap (WP4/WP5, A1.7), BrandMark (WP4, C9), every primitive (WP2), and any adoption of Newsreader or of mono on existing screens.

### A5.2 Font assets, source and licences

**Source.** The same Fontsource builds WP0 measured ([A1.4](#a14-g1--self-hosted-woff2-confirmed-with-the-budget-arithmetic-corrected)): `@fontsource/ibm-plex-sans@5.3.0` (Google Fonts `v23`), `@fontsource/ibm-plex-mono@5.3.0` (`v20`) and `@fontsource/newsreader@5.3.0` (`v26`), each package `license: OFL-1.1`, fetched as registry tarballs into the session scratchpad (npm `sha512` integrity verified) and the needed files copied unmodified. **No package was added**; `package.json`, `package-lock.json` and `composer.json` are untouched. The files are byte-identical to WP0's measurement: every size matches A1.4, and Vite emits the Plex Sans 400 face as `ibm-plex-sans-latin-400-normal-CDDApCn2.woff2` — the same content hash as WP0's spike output.

| File (`src/resources/fonts/`) | Family | Weight | Subset | Bytes | Preloaded | sha256 |
|---|---|---:|---|---:|---|---|
| `ibm-plex-sans-latin-400-normal.woff2` | IBM Plex Sans | 400 | latin | 22,588 | **yes** | `3b646991d30055a93a4ecc499713d4347953a74a947ecab435ab72070cbdab0e` |
| `ibm-plex-sans-latin-500-normal.woff2` | IBM Plex Sans | 500 | latin | 24,184 | **yes** | `0717336fb31fcdcde4b8deb3675bb4a0f7f6d484864afcd6751ac29975962203` |
| `ibm-plex-sans-latin-600-normal.woff2` | IBM Plex Sans | 600 | latin | 24,252 | no | `8960851d691c054ed38e259bdcf1a6190d157b4203ed5bb32c632a863fb8ec2f` |
| `ibm-plex-mono-latin-400-normal.woff2` | IBM Plex Mono | 400 | latin | 14,708 | no | `08949f728dc52d528e69b1667d15c89a5686a4ee9a296ff90983985f99c380f7` |
| `ibm-plex-mono-latin-500-normal.woff2` | IBM Plex Mono | 500 | latin | 14,888 | no | `01d285447409c8a588692162439a038b8cbd7871309ee20267b0d2d91c6e8e22` |
| `newsreader-latin-400-normal.woff2` | Newsreader | 400 | latin | 22,480 | no | `e66067814f1c672d33a457e4f4d102c818b481420e2234cf685ebdbf2f443904` |
| `newsreader-latin-500-normal.woff2` | Newsreader | 500 | latin | 23,624 | no | `5613e2fc8377392c02e8ac9d55014689fb5320a5f2a7be55e8088a314728ac2c` |
| `ibm-plex-sans-latin-ext-400-normal.woff2` | IBM Plex Sans | 400 | latin-ext | 15,980 | no | `c93d2a12aaa280f68b9ab7b726ff8dfedda67c99ef9abed047c1847a1cc6d583` |
| `ibm-plex-sans-latin-ext-500-normal.woff2` | IBM Plex Sans | 500 | latin-ext | 16,456 | no | `2846035d85100f84c79393f80f1442d4ee720129ab8b3ffa8969aae281db8c6c` |
| `ibm-plex-sans-latin-ext-600-normal.woff2` | IBM Plex Sans | 600 | latin-ext | 16,448 | no | `b25dfd4f979e442ae1e25cd0894463434cf01ba21ac1a35d39f4a82bd4cc060e` |

**Licences.** Both families are SIL Open Font License 1.1, which permits bundling and redistribution with software provided each copy carries the copyright notice and the licence (OFL §2). The repository carries the **upstream** licence texts from `google/fonts` (`main` @ `23e54b51`), not Fontsource's generated ones, because Fontsource's header omits the Plex Reserved Font Name line:

| File | Upstream | Upstream sha256 | Committed sha256 |
|---|---|---|---|
| `OFL-IBM-Plex.txt` (covers Sans and Mono; the two upstream files are byte-identical) | `ofl/ibmplexsans/OFL.txt` — "Copyright © 2017 IBM Corp. with Reserved Font Name "Plex"" | `7e6b2818…adb07da` | `d741e57d…c9fc5fb4f` |
| `OFL-Newsreader.txt` | `ofl/newsreader/OFL.txt` — "Copyright 2020 The Newsreader Project Authors" | `fdfad381…d4ded8` | `865f0949…d40adc25` |

The only difference from upstream is whitespace (CRLF → LF, one trailing space removed, final newline added) so the files pass `git diff --check` and the repository's `eol=lf`; the wording is unchanged. Nothing else from the packages (CSS, `.woff`, other subsets/weights, metadata) was copied.

**Reserved Font Name note.** The OFL FAQ (2.6–2.8) treats a subsetted webfont as a Modified Version, which may keep a Reserved Font Name only where it preserves *functional equivalence* or the provider has the author's agreement. These are Google Fonts' own unicode-range subsets of IBM Plex (the family IBM publishes on Google Fonts), redistributed unmodified; IBM itself ships Plex in the same unicode-range split form under the Plex name (`IBM/plex`, `packages/plex-sans/fonts/split/`). The repository does not modify or re-subset the files. Recorded so a future re-subset (e.g. a custom glyph set) is recognised as a naming question, not done silently.

### A5.3 Tailwind / type wiring

```css
@font-face { font-family: 'IBM Plex Sans'; font-weight: 400; font-display: swap;
             src: url('../fonts/ibm-plex-sans-latin-400-normal.woff2') format('woff2');
             unicode-range: U+0000-00FF, …; }                     /* ×10 faces */
@theme {
  --font-sans:    'IBM Plex Sans', system-ui, sans-serif, <emoji families>;
  --font-mono:    'IBM Plex Mono', ui-monospace, monospace;
  --font-display: 'Newsreader', Georgia, serif;
  …brand palette unchanged
}
```

- **Three roles, one utility each:** `font-sans` (UI; the page default, because `body` and Tailwind's preflight both read `--font-sans`), `font-mono` (data; also the preflight default for `code`/`kbd`/`samp`/`pre`), `font-display` (Newsreader; entity names and customer voice only, Direction D §3.3). Fallbacks are exactly Direction D §3.1's. The four emoji families that the previous stack carried are kept after the generic family because they only extend glyph coverage; removing them was not WP1d's business. JetBrains Mono's extra `'Cascadia Code'` fallback is dropped, since the contract's mono fallback is `ui-monospace, monospace`.
- **No type scale, no tracking tokens, no typography utility framework.** Sizes, line heights and weights are untouched everywhere.
- **Tabular numerals.** Plex Mono is a fixed-pitch face, so its figures are tabular by construction; no `font-feature-settings` was added. Components that need `tabular-nums` on proportional text keep using the utility.
- `font-sans` and `font-mono` compile to `var(--font-sans|mono)` as before. `font-display` compiles on first use to `.font-display{font-family:var(--font-display)}` (verified with a throwaway probe build). In this tree it is already emitted, because Tailwind's automatic source detection also scans the new contract test, which names it — a benign artefact of the kind A4.4 recorded for `fill-rule` (≈70 bytes, no consumer).
- **No adoption.** No page, layout or primitive uses `font-display`; existing `font-mono` sites (22 in 18 files, all pre-existing) now render Plex Mono instead of the never-loaded JetBrains Mono fallback chain. No ID, timestamp or money field was converted.

### A5.4 External-font removal and the preload seam

Both root views replace the two bunny.net lines with one include, at the same position (before `@vite`), so neither theme script moved:

```blade
@include('layouts.partials.font-preloads')
```

`resources/views/layouts/partials/font-preloads.blade.php` holds the only preloads:

```blade
<link rel="preload" href="{{ Vite::asset('resources/fonts/ibm-plex-sans-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ Vite::asset('resources/fonts/ibm-plex-sans-latin-500-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
```

`Vite::asset()` resolves the fingerprinted file from the manifest (Vite registers every CSS-referenced font there), so the preload URL is the exact URL the `@font-face` rule requests, and `crossorigin` matches the CORS mode of a font fetch — the two conditions for the browser to reuse the preload instead of fetching twice. One partial means React/Inertia, Blade and the auth pages cannot drift. The partial is not the WP4 `partials/shell/bootstrap.blade.php` and does not pre-empt it.

**Remaining third-party font references in `src/`:** none rendered. `welcome.blade.php` (the Laravel starter page, unrouted, excluded since A1.2) still links bunny.net for Instrument Sans; no route renders it. Left for the owner to delete rather than edited here.

### A5.5 Browser / network evidence

Real Chromium 1243 (Playwright 1.63, container-installed as in WP0–WP1c), cold contexts, CDP network log. Run on the `base` (`707a560`) and `post` copies served by `php artisan serve` (A4.1 method), and again on the **live stack** (`nginx`, the main checkout's final build after `./dev check`).

| Scenario (live stack) | Font requests (URL · status · initiator) | Bytes on the wire | External hosts |
|---|---|---:|---|
| Guest cold `login` | Sans 400 · 200 · preload; Sans 500 · 200 · preload; Sans 600 · 200 · `app.css` | 72,071 | **0** |
| Guest cold `forgot-password` | same three | 72,071 | **0** |
| React cold `/dashboard` | same three | 72,071 | **0** |
| Blade cold `/operator/tickets` | same three | 72,071 | **0** |
| Blade cold `/crm/companies/2` (contact "Łukasz Wójcik") | the three + **Sans latin-ext 500** · `app.css` | 88,876 | **0** |
| React → Blade → React by real links (`/dashboard` → `/billing/invoices` → `/dashboard`) | first document: the three from network; Blade and return documents: every face from cache (0 B); Plex Mono 500 fetched once for the invoice number | 87,308 total | **0** |

- **Each face is requested exactly once per document**; the two preloads are consumed by the `@font-face` rules (no second request, and no "preloaded but not used" console warning after a 3.5 s wait). **No 404** and no failed font request anywhere.
- **Cross-renderer reuse:** nginx serves the fonts `Cache-Control: public, immutable, max-age=31536000` with `Content-Type: font/woff2`, so React and Blade documents share one cached copy. (Under `php artisan serve`, which sends no validators, every document re-fetched — an artefact of that server, not of the change.)
- **Base, for comparison:** 1 preconnect + 1 render-blocking CSS request + 3–4 Inter files to `fonts.bunny.net` on every page (76,031 B React/auth, 101,102 B Blade, where Inter 700 was also fetched).
- **Plex Mono and Newsreader load on first use only.** `/billing/invoices` and `/billing/invoices/3` fetch Mono 500, `/time` Mono 400; no audited page fetches Newsreader. Injecting a Newsreader element in the browser fetched exactly Newsreader 400 and 500, and `document.fonts.check()` confirmed both.
- **latin-ext is conditional:** no ordinary page fetched a latin-ext face; the CRM fixture fetched only the latin-ext weight its name renders in, and injecting "Łódź Škoda Dvořák" into `/dashboard` fetched latin-ext 400 and nothing else.

### A5.6 Payload budget

| | WP0 (A1.4) | WP1d final |
|---|---:|---:|
| Plex Sans 400/500/600 latin | 69.4 KB | 71,024 B = **69.4 KiB** |
| Plex Mono 400/500 latin | 28.9 KB | 29,596 B = **28.9 KiB** |
| Newsreader static 400/500 latin | 45.1 KB | 46,104 B = **45.0 KiB** |
| **Total latin (budget ≤ 180 KB)** | 143.4 KB | **146,724 B = 143.3 KiB** |
| Preload (Sans 400 + 500) | 45.7 KB | **46,772 B = 45.7 KiB** |
| Plex Sans latin-ext 400/500/600 (conditional, uncounted) | 47.8 KB | 48,884 B = 47.7 KiB |
| Typical first document (Sans 400/500/600, measured transfer) | — | **72,071 B**, vs 76,031 (React/auth) / 101,102 (Blade) before |

WP0's "KB" figures are KiB; the files are identical to WP0's.

### A5.7 Fallback, swap and metric behaviour

- `font-display: swap` on every face. With every font request **aborted**, all 21 routes × 2 themes render in the fallback stack (DejaVu Sans is the container's `system-ui`) with **0 clipped elements and 0 horizontal overflow**, and every page height matches `base`'s own fallback render (42/42 route-theme pairs, Δ 0). The largest fallback→loaded reflow is on `/profile` (146 px shorter once Plex arrives), because DejaVu is much wider than Plex; `base` had the same reflow to Inter.
- **CLS with every font response delayed 800 ms** (base → post, live stack identical to post): login 0.0000 → 0.0002, dashboard 0.0003 → 0.0004, tasks 0.0006 → 0.0057, profile 0.0003 → 0.0004, operator/tickets 0.0037 → 0.0088, roles edit 0.0003 → 0.0004, invoice edit 0.0005 → 0.0006. All far below the 0.1 "good" threshold; not chased.
- **No metric adjustment was needed.** No `size-adjust`/`ascent-override`, no line-height or spacing change. The one metric-driven difference is that native `<select>` elements, whose height Chromium derives from the font's own ascent/descent, grow **37 → 38 px** (Plex's 1.30 em content area vs Inter's 1.21 em); it moves nothing but the content under those selects by 1 px (A5.9). Recorded as a compatible normalisation; WP2's `NativeSelect`/control restyle owns control heights.
- **Weights.** Direction D ships 400/500/600 only. Everything that previously resolved to Inter 700 — browser-default bold `<th>` headers (`/tasks`, `/time`), `<strong>`, and the two `font-bold` sites (`billing/payment/show`, `errors/403`) — now renders Plex 600, the nearest face, without synthetic bolding. Deliberate, per the contract; no header height changed.

### A5.8 Dense-screen gate (C7)

`base` vs `post`, element-by-element (every rendered element's box, matched by DOM path), both themes, 1360 px:

| Screen | Theme | Page height Δ | Elements with changed height | Wrap changes | New clipping | Overflow |
|---|---|---:|---:|---:|---|---|
| `/admin/roles/1/edit` (190 elements, 41 checkboxes) | light / dark | **0 / 0** | 0 / 0 | 0 | none | none |
| `/admin/roles/2/edit` | light / dark | **0 / 0** | 0 / 0 | 0 | none | none |
| `/operator/tickets` | light / dark | **0 / 0** | 2 inline boxes (+1 px, no layout effect) | 0 | none | none |

Row heights, nav height (65 px), buttons (−4 px width, same height), inline controls and the long ticket/assignee values are unchanged. At 768 px the queue's cramped cells wrap *less* (two rows 95 → 85 and 134.5 → 118.5 px), because Plex sets narrower than Inter here. **Gate passes.** The single sr-only `View` label is the same pre-existing one WP0 recorded.

### A5.9 Legacy compatibility matrix

A4.9's route set plus both role pages, `/operator/tickets`, each in light and dark at 1360 px, `operator@intechral.test` (guest for auth), `base` vs `post`, on the fixtures in A5.12. Every route returned 200 with the correct `data-theme`; **0 horizontal overflow, 0 clipped elements, 0 increased row heights, 0 new wraps** on every route and theme.

| Route | Renderer | Result (both themes) | Classification |
|---|---|---|---|
| `projects.create` | React | page Δ 0; button widths −4…−5 px | compatible normalisation |
| `tasks.index` | React | page Δ 0; bold `<th>` 700 → 600 | deliberate Direction D change |
| `projects.board` (#468) | React | 0 height changes | unchanged geometry |
| `profile.show` | React | page Δ 0 | compatible normalisation |
| `dashboard` | React | 0 height changes | unchanged geometry |
| `time.index` | React | page Δ 0; bold `<th>`/`<strong>` 700 → 600 | deliberate Direction D change |
| `tickets.show` (#4) | Blade | description 2 → 1 lines (narrower face), page Δ 0 | compatible normalisation |
| `operator.tickets.index` | Blade | A5.8 | unchanged geometry |
| `operator.tickets.show` (#4) | Blade | two native selects 37 → 38 px, page Δ 0 | compatible normalisation |
| `roles.edit` (#1, #2) | Blade | A5.8 | unchanged geometry |
| `billing.invoices.index` (fixture, draft badge) | Blade | page Δ 0; invoice number now Plex Mono | compatible normalisation (debt A4.8 unchanged) |
| `billing.invoices.edit` (fixture) | Blade | three native selects 37 → 38 px, page +1 px | compatible normalisation |
| `billing.invoices.show` (fixture) | Blade | page Δ 0; mono heading now Plex Mono | compatible normalisation |
| `crm.companies.show` (fixture, long name, latin-ext contact) | Blade | page Δ 0; latin-ext 500 loads for the contact | compatible normalisation |
| `cms.show` (fixture) | Blade | body 8 → 7 lines, page Δ 0 | compatible normalisation |
| `cms.index`, `operator.cms.index`, `users.show` (#1) | Blade | 0 height changes | unchanged geometry |
| `login`, `forgot-password` | React (guest) | page Δ 0; wordmark inline box +3 px, no layout effect | compatible normalisation |

Every route's face changed from Inter to Plex Sans by design; "unchanged geometry" means no element box height changed. **Regressions: none.**

### A5.10 Responsive and accessibility

- **Widths 1280, 1024, 768, 390** (dashboard, tasks, time, profile, operator/tickets, roles edit, invoice edit, tickets/4, CRM company, login), both themes: no new overflow, clipping or wrap anywhere. **The pre-existing 768 px React overflow (771 > 768, A1.13) disappears** on `/dashboard`, `/tasks`, `/time` and `/profile` — the narrower face lets the desktop nav fit. Not a fix WP1d claims: WP4 must still confirm the shell has no such overflow on its own.
- **320 CSS px reflow (≈ 400 % of 1280) / 200 % zoom spot check** on dashboard, tasks, operator/tickets, roles edit, invoice edit, login, forgot-password, profile: no horizontal overflow before or after, no clipped label or truncated button.
- **Focus:** the WP1c focus ring is byte-identical before and after (`rgb(11,106,115) 0 0 0 2px` on the login field); Blade links keep the UA `auto` outline. Fonts cannot affect either.
- Text is legible in both the fallback and loaded states (A5.7); no content depends on Newsreader (unused); mono only replaces an already-mono presentation. Contrast is a colour property and is unchanged: the two A4.8 legacy debts measure the same.

### A5.11 First paint and theme bootstrap

Real Chromium, 20 cold loads per server — stored preference and OS preference × light/dark × {auth `login`, React `/dashboard`, Blade `/operator/tickets`, React → Blade by a real link (`/dashboard` → `/billing/invoices`), Blade → React by a real link}. On the first animation frame `data-theme` and the `<html>` background were already correct and no later flip occurred: **base 20/20, post 20/20, live stack 20/20.** The only difference is that the Blade root's theme script no longer waits for a third-party stylesheet (A1.7 item 2): `document.styleSheets` on the first frame is 1 (our `app.css`) where it was 2. **Neither theme script was edited or moved**; consolidation remains WP4/WP5.

### A5.12 Fixtures and data

The dev database again held 0 invoices, 0 CRM companies and 0 CMS pages. Created through the existing factories, pinned to existing users, deleted after the runs: invoice #3 `INV-WP1D` (draft, client 2, created by 1) with 2 items; CRM company #2 (a long 61-character name) with contact #2 "Łukasz Wójcik" (the real-content latin-ext check); CMS page #2 `wp1d-fixture-page` (published). Baseline and final counts: users 3, invoices 0, invoice_items 0, crm_companies 0, crm_contacts 0, cms_pages 0, tickets 2, projects 4, tasks 12, roles 2, organizations 0, time_entries 3, sessions 50 — the 126 sessions the browser harness created (`127.0.0.1` via `artisan serve`, `172.22.0.5` via nginx) and the 8 that the two `./dev check` runs' host `curl` probes create were deleted, leaving exactly the baseline session ids. Pre-existing residue untouched, as in A4.10.

### A5.13 Build and bundle

Clean view cache, same procedure for both trees (A4.1):

| Asset | Before (`707a560`) | After | Delta |
|---|---:|---:|---:|
| `app.css` | 80,294 B / 15,956 gzip-9 | 83,672 / 16,507 | **+3,378 / +551** — the ten `@font-face` rules (mostly their `unicode-range` lists) plus the three family variables |
| `app.tsx` entry, `app-layout` (shell), `app.js` (Blade), `wayfinder`, `board`, `allocation` | — | — | **0 — identical file hashes** |
| Emitted font assets | 0 (third-party) | 10 files, 195,608 B | self-hosted |

The live dev-tree stylesheet (with view-cache residue, A4.19 finding 3) is 85,029 / 16,695.

### A5.14 WP1a / WP1b / WP1c regression check

The compiled stylesheets were compared rule-by-rule: the **only** differences are the ten `@font-face` rules, the `--font-*` theme variables and the `.font-display` utility.

- **WP1a:** the thirteen `.legacy-*` rules are byte-identical (md5 match); `@layer utilities` untouched.
- **WP1b:** the six orphan mappings are unchanged (source, compiled CSS and `DirectionDThemeContractTest`).
- **WP1c:** all 80 `--ds-*` declarations (37 × 2 themes + 6 motion) are byte-identical; `primary` still `var(--accent)`; `ring → var(--ds-focus)` and `destructive → var(--ds-danger)` hold; `DirectionDThemeContractTest` (39 cases) green. The two A4.8 contrast debts are unchanged and were not fixed.

### A5.15 Tests / static guards

New: `tests/Unit/Configuration/DirectionDTypographyContractTest.php` (5 cases, 69 assertions), in the style of the WP1c test — wiring, never glyphs or pixels:

- the three roles are the `@theme` font families with the contract fallbacks;
- exactly the ten committed (family, weight, subset) faces are declared, each `font-display: swap`, each with a `unicode-range`, each pointing at a committed `../fonts/*.woff2`, none remote;
- no stylesheet, root view or the preload partial references a third-party font host or `preconnect`;
- both root views include the one preload partial, which preloads exactly Plex Sans 400 and 500 latin (via `Vite::asset`, `as="font"`, `type="font/woff2"`, `crossorigin`), and each preloaded file is a file an `@font-face` requests;
- every committed `.woff2` is referenced (no stray weight), and both OFL files ship with their copyright holders.

Mutation-checked in the container copy: `swap` → `block` on one face, adding a Plex Sans 600 preload, restoring the bunny.net link in the Inertia root, dropping `crossorigin`, adding a stray Newsreader 700 file, and changing the mono stack each failed the suite; restored byte-identically. No Playwright spec was added or changed (the two existing specs that locate `span.font-mono` are unaffected: the class is unchanged).

### A5.16 Validation results

- **`./dev check` from the main checkout: all five steps green, run twice (before and after this amendment)** — CLI self-tests, `git diff --check`, Pint, `npm run check` (Wayfinder, `tsc`, ESLint, Prettier, Vitest, production build), Pest **977 passed / 4069 assertions** (972 + 5 new). The A4.17 environment blockers are gone (`dev` executable, `public/build` host-owned).
- `git diff --check`: clean.

### A5.17 Cleanup / ownership

Fixtures deleted and counts verified (A5.12); harness and gate-probe sessions deleted (A5.12). Both `artisan serve` processes stopped and `/tmp/wp1d` removed from the container. Tarballs, harness scripts and screenshots lived only in the session scratchpad. No tracked build output; `src/public/build` and every other generated path are host-owned; no package or manifest change.

### A5.18 Deviations / findings

1. **Plex Sans 600 is fetched on every audited page** (headings and nav use `font-semibold`), CSS-discovered after `app.css` parses. The committed plan preloads 400/500 only, and a first document still moves less font data than before (72 KB vs 76–101 KB), so the plan was followed. Whether to preload 600 is a measured question for WP8, not a WP1d change.
2. **Fontsource's licence files are incomplete for Plex** (no Reserved Font Name line); the upstream `google/fonts` texts were shipped instead (A5.2).
3. **`font-display` is emitted before it has a consumer** because Tailwind's automatic source detection scans the contract test (A5.3). Benign; it disappears or stays harmlessly as the test changes.
4. **Native `<select>` +1 px** (A5.7) — the only metric-driven box change; accepted, owned by WP2's control restyle.
5. **The 768 px React overflow no longer reproduces** (A5.10) — an incidental consequence of narrower glyphs, not a fix. WP4 still verifies it.
6. **Vite dev-server (HMR) mode was not exercised**; `Vite::asset()` resolves through the hot server there by design. All evidence is from production builds.
7. `welcome.blade.php` still references bunny.net but is unrouted (A5.4).

### A5.19 Files changed

`src/resources/css/app.css` · `src/resources/views/app.blade.php` · `src/resources/views/layouts/app.blade.php` · `src/resources/views/layouts/partials/font-preloads.blade.php` (new) · `src/resources/fonts/` (new: 10 `.woff2`, `OFL-IBM-Plex.txt`, `OFL-Newsreader.txt`) · `src/tests/Unit/Configuration/DirectionDTypographyContractTest.php` (new) · this document.

### A5.20 WP2 handoff

WP2 may start. It inherits:

- **Type roles ready to consume:** `font-sans` (default everywhere), `font-mono` (Plex Mono; pair with `tabular-nums` only on proportional text), `font-display` (Newsreader — entity names and customer voice only, §3.3; WP2 primitives should not use it). Available weights are 400/500/600; `font-bold` and default-bold elements resolve to 600.
- **Control metrics:** native `<select>` height tracks font metrics (A5.7); the `Input`/`NativeSelect`/`Button` restyle should fix control heights explicitly rather than inherit them.
- **Unchanged obligations:** the `primary` flip with the Button restyle (A4.6), 1.4.11 control identity (A4.19 finding 5), `success` text placement (finding 6), and the two A4.8 legacy contrast debts via `Status`.
- **Not WP2's:** the theme bootstrap consolidation (WP4/WP5), BrandMark (WP4, C9), the Plex Sans 600 preload question (WP8).

---

## Amendment 6: WP2 Results (2026-09-25)

### A6.1 Committed scope, as executed

**Owner-confirmed.** The owner explicitly approved the narrowed component scope described here: `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip` and `Popover` (and the three Radix dependencies) stay **deferred until a concrete current or foundation consumer exists**, because live inspection found none. They are not to be added to satisfy the literal §28 inventory. The narrowing below is therefore settled scope, not an open question.

WP2 ran on `feature/epic-013-direction-d-shell` at `b8c74a5` (WP1d), working tree clean at start. **WP2 is now Complete**, with one deliberate narrowing of the §28 list that this section records rather than hides.

[§28 WP2](#wp2--shared-primitives) lists the restyles, the three reworks, and nine additions (`Avatar`, `Status`, `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip`, `Popover`, with three Radix dependencies). It also carries the governing rules that the additions must have a real consumer ("each with a real consumer", §26) and that there are **no page migrations for aesthetics**. The WP2 work order applied both: **an addition was built only where a live consumer exists in the tree today**; the rest are deferred to the package that owns their first consumer (A6.10). §28's *restyle* and *rework* lists were executed in full.

| §28 item | Outcome |
|---|---|
| `Button` (ink) + `primary` flip | Done, one reviewable change with its audit (A6.3, A6.4) |
| `Input`, `Textarea`, `Label` | Restyled; `NativeSelect` (retained) pinned to the same control scale |
| `DropdownMenu`, `Dialog`/`DialogShell`, `ConfirmationDialog`, `FormDialog` | Restyled, behaviour untouched |
| `Alert` (+ `FlashRegion`), `Pagination` | Restyled |
| `Progress` → `ProgressBar`/`Meter` | Reworked in place; keeps the `Progress` export (A6.9) |
| `SectionPanel` → `Section`; `PageHeader` | `Section` created, `SectionPanel` is now a wrapper over it; `PageHeader` gained an overline |
| `Status`, `Avatar` | **Created** (real consumers: A6.2) |
| `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip`, `Popover` | **Deferred**, no live consumer (A6.10) |

No PHP under `app/`, no migration, no route, no controller changed. The only backend-tree files touched are the architecture test and one Blade partial (`nav.blade.php`, A6.3).

### A6.2 Component inventory

| Component | Prior state | Final state | Action | Current consumers |
|---|---|---|---|---|
| `Button` | cva: default/secondary/outline/ghost/destructive × default/icon/sm, `bg-primary` (indigo), `h-9`, `hover:opacity`, disabled by global 50% opacity | `primary` (= `default`), `secondary` (= `outline`), `ghost`, `destructive` × `sm` / `md` (= `default`) / `lg` / `icon`; ink fill; explicit shared control heights; 2px/2px `focus` outline; disabled = `surface-sunken` + `text-muted` (was `text-faint` in the first pass; A6.25) | **Restyle** | 74 JSX sites + 11 raw `buttonVariants` link sites (Pagination, board, create/edit, dashboard, operator time) |
| `Input`, `Textarea`, `Label` | shadcn tokens, `h-10`, ring focus | `surface` fill, `rule-control` edge, `text` ink, `aria-invalid` edge, explicit `h-9`, outline focus | **Restyle** | every React form |
| `NativeSelect` | `h-10`, shadcn tokens | Same treatment; `h-9`, `py-0`, still a native `<select>` | **Retain, normalised** | 15 sites (time, operator time, create-task, project fields, invitations …) |
| `DialogShell`, `ConfirmationDialog`, `FormDialog` | `bg-black/50`, `rounded-md border shadow-xl` | `bg-scrim`, `rounded-overlay bg-surface shadow-overlay`, token-driven enter/exit animation (reduced-motion aware) | **Restyle** | milestones, project delete, time entry delete/edit, profile |
| `DropdownMenu` | `bg-card border shadow-lg`, `focus:bg-muted` | `surface` + `shadow-overlay`, radius 8, `data-highlighted` = `surface-hover`, keyboard outline | **Restyle** | board Move menu, account menu (`app-layout`) |
| `Alert` | one bordered box, no variants | `neutral` / `info` / `success` / `warning` / `danger`, each with a glyph and an `sr-only` kind label; `danger` is `role=alert`, the rest `status` | **Restyle** | `FlashRegion`, profile, time pages |
| `Pagination` | outline buttons | `secondary` buttons, tokens, `rel=prev/next` | **Restyle** | tasks, operator time |
| `Progress` | `progressbar`, 8px, `bg-muted` / `bg-primary` | `progressbar`, 6px (`md`) / 4px (`sm`), `progress-track` / `progress-fill` | **Rework** (kept `Progress`) | project card, milestone card, checklist, operator time (new) |
| `SectionPanel` | 2-column grid, `border-border` | wrapper over `Section layout="split"`, same geometry, token colours | **Rework** | 26 |
| `Section` | did not exist | stacked (title over `rule-strong`) and split layouts, `aria-labelledby` `<section>` | **New** | via `SectionPanel` |
| `PageHeader` | title + description + actions | adds `overline`; tokens; actions wrap | **Rework** | 11 |
| `Status` | did not exist (colour-only `Badge` variants) | glyph + label + tone; `neutral` / `info` / `success` / `warning` / `danger` / `live`; 7 shapes | **New** | `TaskStatusBadge`, `ProjectStatusBadge`, dashboard ticket status, profile 2FA state, time "Running" |
| `Avatar` | two hand-rolled `bg-primary` circles | circular initials on `surface-sunken`, `role=img` + name, `decorative` | **New** | `TaskCard`, `TaskComments` |
| `Badge` | five variants | neutral moved to tokens; documented as the tag / chip primitive; status variants kept for unmigrated consumers | **Retain** | 12 sites (Billed / Locked / Billable / Owner / Current, `PriorityBadge`) |
| `control-metrics.ts` | — | the single control-height scale and the shared focus ring | **New** (helper) | Button, Input, Textarea, NativeSelect |

### A6.3 The `primary` alias: flipped to ink, after every consumer was resolved

WP1c held `--primary: var(--accent)`. It now reads **`var(--ds-ink)`** (`--primary-foreground: var(--ds-on-ink)`) in both themes. It was done in the required order: inventory every consumer, classify, make each accent-meaning consumer explicit, then remap.

**Inventory.** A raw scan of `resources/` (`bg|text|border|ring|accent|fill|stroke|outline|divide|decoration|shadow`-`primary`, with any variant prefix, in `.tsx`, `.ts`, `.blade.php`, `.js`) plus `app/`, `database/`, `config/`, `routes/` and `lang/` found **13 site groups**, and the 74 `<Button>` sites that consume `primary` implicitly through the `default` variant.

| # | Site | Was | Classification | Resolution |
|---|---|---|---|---|
| 1 | `Button` `default` variant | `bg-primary` indigo | **primary action** | `bg-ink text-on-ink` (the flip, A6.4) |
| 2–3 | `login.tsx:77`, `company-selector.tsx:40` | `accent-primary` checkbox | selected-state control | **`accent-accent`** (interactive accent; the Blade checkboxes keep `legacy-accent`) |
| 4–6 | `tasks/index.tsx` `ViewTab`, `time/index.tsx` "Entries", `time/allocation.tsx` "Allocation" | `border-primary text-primary` active tab | active / selected | **`border-ink text-text`**, the Direction D ink underline |
| 7 | `dashboard/index.tsx` card links | `hover:border-primary` | hover accent | **`hover:border-accent-line`** |
| 8 | `dashboard/index.tsx` chevron | `group-hover:text-primary` | hover accent | **`group-hover:text-accent`** |
| 9 | `operator/time/index.tsx` share bar | `bg-primary` fill | progress fill | now `<Progress>` (`progress-fill`), and gains a named `progressbar` |
| 10–11 | `task-card.tsx`, `task-comments.tsx` | `bg-primary text-primary-foreground` circle | identity mark (legacy misuse of an action colour) | **`Avatar`** |
| 12 | `progress.tsx` | `bg-primary` fill | progress fill | `bg-progress-fill` |
| 13 | `nav.blade.php` × 9 | `hover:text-primary` | link-hover accent | **`hover:text-legacy-accent`**, byte-identical rendering (indigo), so the Blade nav is untouched |

The ten A2.5 sites were already resolved by WP1a: sites 1–7 are `legacy-text-primary` (ink) and are unaffected; sites 8–10 are rows 4–6 above. The two-factor method switch used `variant="default"` as its *selected* state; it stays an ink fill (the segmented-control look) and now exposes `aria-pressed`.

**The 74 implicit `default` Buttons** were read: every one is a submit / create / save / apply / start action (auth forms, project and task forms, milestone create, timer start, quick-add), plus the create-task opener. Ink is the correct fill for all of them. No cyan is used as a fill anywhere.

**After the flip** nothing in `resources/` consumes `primary` for an accent meaning. The alias stays defined as *"the primary action"* for any future shadcn-style code. It is guarded twice (A6.19): the mapping itself, and a source scan that fails if a `text|border|ring|accent-primary` idiom reappears.

### A6.4 Button contract

`primary` = ink (`bg-ink text-on-ink`); `secondary` = `surface` + `control-edge` (A6.25); `ghost` = no resting chrome; `destructive` = the WP1c `destructive → danger` alias (fill, confirming action only). `outline` and `default` remain as aliases so no call site changed. Heights come from the shared scale (A6.5); a border is always present (transparent when the variant has no edge) so every variant is the same size for the same label. No variant repeats a class another sets, because `buttonVariants` is also used as a raw class string on links (where `twMerge` does not run); a test enforces it. Disabled controls drop their fill for `surface-sunken`, keep their label at `text-muted` (A6.25) and a `rule` edge, and keep `pointer-events-none`. Focus is the §14.1 2px/2px `focus` outline. Transitions cover colour, background and border only, **not** `outline-color`: with `transition-colors` the ring faded in from black over 120 ms (found in the browser, fixed).

Hover is `ink/90`, active `ink/80` (primary) and `surface-hover` / `surface-sunken` (secondary, ghost); contrast in A6.12.

### A6.5 Forms and control geometry

One scale, `components/ui/control-metrics.ts`: **sm 32 / md 36 / lg 40 px**, `pointer-coarse` steps to 40 / 44 / 48. `Button`, `Input` and `NativeSelect` all read it, so they align in a row. `Textarea` keeps `min-h-24` (never a fixed height). Radius is the §4.2 5 px (`rounded-control`), 7 px for `lg`; new theme tokens `--radius-control`, `-control-lg`, `-overlay`, `-tag`.

**Measured (Chromium, `/time`, `/projects/create`, `/profile`, `/operator/time`, with and without the web fonts, by aborting every `*.woff2`):** every `input`, `select` and `button` is **36 px in both conditions**. At HEAD they were 40 (inputs, selects) and 36 (buttons), and four filter selects hand-copied the primitive's classes with `h-10 … bg-background` (so they neither matched the primitive nor could be restyled). Those redundant overrides were deleted, and the raw `<input>` in `quick-add-task.tsx` was replaced with `Input`. Inputs and selects are therefore 4 px shorter on the React pages: a deliberate density change toward the 36 px row of §4.1.

**What WP1d's 37 → 38 px finding was.** The React `NativeSelect` was already height-pinned at HEAD (40 px both ways), so the metric-driven growth WP1d saw is on **Blade** selects, which have no explicit height. WP2 pins every React control; the Blade selects are WP5's and still track the font.

**WCAG 1.4.11 (non-text contrast) — resolved after review (A6.25).** The first WP2 pass drew field edges in `rule-control` (1.44–1.63:1) and could not honestly claim 1.4.11 for the resting state. The owner did not accept label, hover or focus as a substitute for a visible boundary, so WP2 now draws the resting edge of `Input`, `Textarea`, `NativeSelect` and the `secondary` `Button` in a dedicated **`control-edge`** token, **3.06–4.20:1 against every surface a control renders on, in both themes**. The values, the surfaces measured, and the Direction D spec change are in A6.25. Placeholder text uses `text-muted` (6.40 / 7.17:1, AA) rather than the contract's old `text-faint` (3.05), which fails AA for text; the spec now says so.

### A6.6 Dialog and dropdown

`DialogShell` is now `bg-scrim` over a `surface` panel with `shadow-overlay`, radius 8, `text` ink; cancel buttons are `secondary`. Enter uses `motion-sheet` (240 ms), exit 70% of it; the menu uses `motion-base`. Under `prefers-reduced-motion` the translation is removed (`--ds-shift: 0`) and durations fall to `motion-fast` (120 ms), verified in Chromium (`animation-duration: 0.12s`). Behaviour is untouched: the controlled/uncontrolled contract, opener focus return, `FormDialog` submit containment and error rendering, and the destructive confirmation copy are byte-identical, and no confirmation was added. `DropdownMenu` keeps Radix semantics; the highlighted row is `surface-hover`, plus a 2px inset `focus` outline (`:focus-visible`, so keyboard only) because a 1.2:1 fill alone is a weak focus indicator; disabled rows are `text-muted` (A6.25; the label is still information).

### A6.7 Alert, FlashRegion, Pagination

`Alert` variants carry a glyph **and** an `sr-only` kind label ("Error: …"), body text stays `text` on the soft tint, the glyph takes the semantic text colour. Only `danger` is `role="alert"`. `FlashRegion` is now a thin mapper (`success`, `status → info`, `warning`, `error → danger`) with the dismiss button in the new `action` slot; it keeps its live-region semantics and retires `legacy-success` / `legacy-warning` (their only consumer), so those two `@theme` names were removed and the guard updated. Profile's two warning panels and the allocation error panel moved to `variant="warning"` / `"danger"`. `Pagination` is `secondary` buttons with `rel=prev/next`; it has no numbered links, so there is no current-page link to mark `aria-current`, and the disabled direction stays omitted.

### A6.8 Status

`Status` is an inline mark, not a pill: a 12 px SVG glyph (`aria-hidden`) + the label as text + a tone. Tones and default shapes: `neutral` hollow circle, `info` half circle, `success` check-in-circle, `warning` triangle, `danger` square, `live` dot; a domain may override the shape (`dot`, `dashed`, …). The label uses the text-safe token, the glyph the shape token (`success` / `success-glyph`, `warning` / `warning-glyph`, `live-text` / `live`). The primitive contains no ticket, task, project or invoice vocabulary; the label is a `string` child rendered as text (React-escaped; tested with `<img onerror>` and `<script>`). A test proves the six tones have six distinct shapes.

Adopted (clearly shared-primitive, mechanical): `TaskStatusBadge`, `ProjectStatusBadge` (active `dot`, on hold `dashed`, completed `check`, archived `circle`), the dashboard's ticket status, the profile 2FA Enabled/Disabled, and the time page's "Running" (`live`). **Not** migrated, because they are attributes or tags rather than states: Billed / Locked / Billable / Non-billable, Owner, Current, and `PriorityBadge` (Priority is deferred).

### A6.9 Progress → continuous bar

`Progress` stays `role="progressbar"` with `aria-valuemin/max/now`, a required name and optional `aria-valuetext`. §11.3 assigns `progressbar` to completion and `meter` to a quantity read against a plan; every current consumer (project card, milestone card, checklist, the new operator-time share bar) is completion, so **no `meter` was built**. Geometry: 6 px (`md`, default) or 4 px (`sm`), radius half the height, `progress-track` / `progress-fill`, never cyan; the width transition uses `motion-base` and is removed under reduced motion. The expected-marker and over-100% treatments are not implemented (no consumer). Two consumers' redundant `h-1.5` overrides were removed.

### A6.10 New Radix primitives, dependencies, and deferrals

**No dependency was added.** `package.json` and `package-lock.json` are unchanged.

| Component | Decision | Reason |
|---|---|---|
| `Tabs` (Radix) | **Deferred** | The three live "tabs" (`tasks` `ViewTab`, `time` Entries / Allocation) are route links with `aria-current="page"` (each is a separate Inertia page). `role=tablist` / `tabpanel` semantics would be wrong for them. They were made explicit ink-underline instead (A6.3). First consumer: an in-page tabbed view (Projects / entity pages, WP7+) |
| `Tooltip` (Radix) | **Deferred** | The epic's consumer is disabled-reason tooltips; no live React control carries a disabled reason (`title` on a drag handle only). First consumer: billing-lock / archived disabled controls |
| `Popover` (Radix) | **Deferred** | Its consumer is `TimerTray` (WP6). No tray exists |
| `Tag` | **Deferred** | `Badge` already serves tags and chips; a second component would duplicate it. Split when source tags (`BOARD` / `TICKET`) get a consumer |
| `Skeleton`, `ErrorState` | **Deferred** | No loading region or region-level error state exists to consume them |
| `EmptyState` | **Deferred** | Three ad hoc one-line empties ("No milestones yet.", …) do not justify a component yet; Home (WP7) is the first structured consumer |
| `StagePath`, `Priority`, `FilterBar`, `DataTable`, `BulkBar`, `Card`, `Combobox`, `Chip` | **Deferred** (epic §20) | unchanged |
| `TimerPill/Tray/Control`, `Rail`, `Drawer`, `UtilityBar`, `Breadcrumb`, `AccountMenu`, `CustomerShell`, `TopNav`, `NavigationLink`, `BrandMark` | **Not WP2's** | WP3–WP6 |

Deferring `Tabs`, `Tooltip` and `Popover` changes the epic's stated "three new Radix packages … measured after WP2" (§26, §20): that measurement now applies where each package is finally added. This is the one place the executed scope is smaller than the committed §28 bullet, and it is a call for the reviewer to confirm.

### A6.11 Alias register update (§24.2)

| Alias / name | Was | Now | Note |
|---|---|---|---|
| `primary` | `var(--accent)` indigo (held) | **`var(--ds-ink)`** | A6.3 |
| `primary-foreground` | `var(--accent-text)` | **`var(--ds-on-ink)`** | A6.3 |
| `legacy-success`, `legacy-warning` | isolation names, 1 consumer (`FlashRegion`) | **retired** | A6.7; the raw `--success` / `--warning` variables stay for Blade |
| `legacy-accent` | 7 Blade checkboxes | unchanged | now also the Blade nav hover (9 sites) |
| `destructive`, `ring` | WP1c mappings | unchanged | Button / Input / Dialog focus now use `outline-focus` directly; `ring-ring` remains only on legacy consumers |
| `background`, `card`, `border`, `input`, `muted`, `muted-foreground`, `foreground`, `secondary*`, `accent-foreground`, `info` | held | held | primitives no longer read them, so they can now be retired as their remaining page consumers migrate |
| Radius, overlay animations | — | `--radius-control/-control-lg/-overlay/-tag`, `--animate-overlay/dialog/menu-in/out` | new, additive |

### A6.12 Contrast (measured in Chromium, sRGB, rendered composites, both themes)

Lab page rendered from the built primitives on `canvas` and `surface`; thresholds 4.5 text, 3.0 non-text. Light / dark.

| Pair | Light | Dark |
|---|---:|---:|
| Primary button text on ink: rest / hover / active | 17.22 / 13.16 / 9.31 | 15.81 / 12.98 / 10.30 |
| Secondary text on surface: rest / hover / active | 17.22 / 13.68 / 14.71 | 14.15 / 13.56 / 15.30 |
| Ghost text on canvas: rest / hover | 15.79 / 13.68 | 14.15 / 13.56 |
| Destructive text on danger: rest / hover / active | 6.57 / 5.69 / **4.76** | 8.79 / 7.30 / 5.99 |
| Disabled controls, first pass (`text-faint`, superseded: now `text-muted`, 5.46 / 7.75, A6.25) | 2.84–3.05 | 4.17–4.50 |
| Input text; placeholder | 17.22; 6.40 | 14.15; 7.17 |
| Input edge at rest / hover / invalid, **as first measured with `rule-control` (superseded, A6.25)** | 1.44–1.57 / 3.05 / 6.03–6.57 | 1.46–1.63 / 4.65 / 7.27–8.12 |
| Focus outline vs surround (all controls) | 5.79 | 12.18 |
| `Status` label text, canvas / surface: neutral | 5.86 / 6.40 | 8.00 / 7.17 |
| info | 5.79 / 6.32 | 11.77 / 10.54 |
| success (narrowest) | **4.83** / 5.27 | 10.15 / 9.09 |
| warning | 5.43 / 5.92 | 10.15 / 9.08 |
| danger | 6.03 / 6.57 | 8.12 / 7.27 |
| live | 5.79 / 6.32 | 12.18 / 10.91 |
| `Status` glyph (non-text): success | 3.89 / 4.25 | 9.03 / 8.09 |
| warning | 3.34 / 3.64 | 10.15 / 9.08 |
| live | 3.93 / 4.29 | 12.18 / 10.91 |
| `Alert` body text (all variants, min) | 14.54 | 12.94 |
| `Alert` glyph on its tint (min) | 5.23 | 7.44 |
| `Progress` fill on track / fill on surface | 13.18 / 15.79 | 9.61 / 12.56 |

**No failing pair.** Every text pair listed is ≥ 4.5 (`destructive` active, 4.76, is the narrowest; disabled controls are exempt and only need to stay distinguishable) and every non-text pair ≥ 3. The `Progress` track (1.20 / 1.31 against its surround) is decorative; the bar's identity is the fill. The resting-edge (1.4.11) rows above are superseded by A6.25; the current edge is `control-edge`. Across the 21-route matrix no text pair got worse than before and none newly fell below 4.5.

### A6.13 Accessibility

Native `<button>`, `<input>`, `<select>`, `<textarea>`, `<label>` throughout; `NativeSelect` stays native. Disabled is the native attribute (communicated programmatically and unfocusable). Every icon-only `Button` in the tree (14 sites) already had an accessible name; none needed a fix. `Status` never relies on colour (distinct glyph shapes + visible text). `Alert` announces its kind in text and uses `alert` only for errors. Dialogs are named and described, trap focus, close on Esc, an explicit close button and overlay click, and return focus to the opener (verified in Chromium in all four combinations of theme × reduced motion). Menus open from the keyboard, arrow-move a `menuitem` highlight with a visible outline, and Esc returns focus to the trigger. The `Avatar` exposes the name (never the email) or is hidden when the name is beside it. The two-factor method switch exposes `aria-pressed`. No tooltip exists, so nothing depends on one. The S2 navigation focus policy was not implemented.

### A6.14 Visual compatibility matrix

The 21-route WP0 set, both themes, 1360 × 900, operator (guest for auth), against a `base` server built from `b8c74a5` and the live post-WP2 tree, with seeded fixtures (invoice, CRM company + contact, CMS page, existing tickets and projects). Text colours and contrasts compared element by element; rest-state full-page pixel diff.

| Route | Class | Evidence |
|---|---|---|
| `projects.create` | **Deliberate Direction D change** | ink primary, `surface` controls, tokenised section rules; page 12 px shorter (36 px controls); 0 text pairs worse |
| `tasks.index` | **Deliberate change** | ink "New task", ink underline tab, `Status` for task state, secondary pagination |
| `projects.board` | **Deliberate change** | `Status` project state, secondary header actions, `Input` in quick-add |
| `profile` | **Deliberate change** | ink actions, `Status` for 2FA, warning `Alert`; the +120 px in the diff is three extra rows of the browser-session list (the harness's own logins) |
| `dashboard` | **Deliberate change** | `Status` for ticket state, hover accents, secondary actions |
| `time.index` | **Deliberate change** | ink underline tab, 36 px selects and inputs, `Status` live "Running" |
| `login`, `forgot-password` | **Deliberate change** | ink submit, tokenised inputs and link buttons |
| `tickets.show`, `operator.tickets.index`, `operator.tickets.show`, `roles.edit` #1 and #2, `billing.invoices` index / edit / show, `crm.companies.show`, `cms.show`, `cms.index`, `users.show` | **Unchanged** | **0 px** pixel diff, 0 text or control changes, both themes (Blade; no React primitive is used) |
| `operator.cms.index` | Unchanged | 0 text or control changes; the ~3,000 px band (y 210–267) is the relative-timestamp noise A4.9 already recorded |

**0 regressions.** 76 control geometry changes, all on the seven React routes, all the intended 40 → 36 px normalisation or Button/Input restyle. 0 route with new horizontal overflow, clipping or a text pair newly under 4.5:1. The Blade nav is unaffected by the `primary` flip (9 hover sites made explicit and verified byte-identical in the compiled CSS: `.hover\:text-legacy-accent:hover { color: var(--accent) }`). Blade never referenced `--primary` directly.

### A6.15 Real-browser results

- **Primitive interaction script, Chromium, light × dark × reduced-motion (70 / 70):** dialog radius 8 px, scrim colour (`rgba(26,27,30,.18)` / `rgba(0,0,0,.45)`), enter animation `ds-dialog-in` at 240 ms (120 ms reduced, `--ds-shift: 0px`), focus lands inside, Tab stays trapped, control outline solid 2px / 2px in the `focus` colour (`rgb(11,106,115)` / `rgb(25,231,242)`), Esc and the close button and overlay click all dismiss and return focus to the opener; Move menu and account menu open from the keyboard, arrow to a `menuitem` with a visible outline, radius 8, Esc returns focus; the error flash is `role=alert` with glyph, "Error:" label and `danger-soft` tint and dismisses.
- **Existing Playwright suite (`./dev test:e2e`, 46 specs):** see A6.20; the run that counts is the one against the final tree, recorded there.

### A6.16 Responsive and dense controls

Widths 1360 / 1280 / 1024 / 768 / 640 (200% reflow of 1280) / 390 on seven React routes plus the task detail page, 48 route-widths: **0 horizontal overflow and 0 clipped controls**, identical to `base`. Controls are 36 px at 100% with and without web fonts. The 640 and 390 px renders were inspected; the dashboard, forms and timer controls stay usable. No WP4 shell work was done.

### A6.17 Bundle and performance

Clean container copies (no stale compiled views), `vite build`, HEAD vs WP2:

| Asset | HEAD raw / gzip | WP2 raw / gzip | Δ gzip |
|---|---:|---:|---:|
| `app.css` | 69.58 / 14.96 kB | 76.09 / 16.02 kB | **+1.06 kB** |
| `app.js` (entry) | 350.48 / 110.14 kB | 350.56 / 110.17 kB | +0.03 kB |
| `app-layout.js` (shell) | 101.95 / 34.17 kB | 102.09 / 34.21 kB | +0.04 kB |
| `allocation.js` (Chart.js, lazy) | 234.32 / 79.88 kB | 234.28 / 79.88 kB | 0 |
| `board.js` (dnd-kit, lazy) | 61.32 / 19.92 kB | 61.07 / 19.86 kB | −0.06 kB |
| new chunks `status.js`, `avatar.js` | — | 2.18 + 0.74 / 0.75 + 0.51 kB | +1.26 kB |
| all JS + CSS | 312.94 kB gz | 316.29 kB gz | **+3.35 kB** |

Chart.js and dnd-kit stay in their lazy chunks; nothing moved into the entry or shell. No Radix package was added, so there is no dependency contribution to report. (The live `public/build` CSS is ~91 kB because the dev tree's `storage/framework/views` residue feeds Tailwind's `@source`, A4.19 #3; it is not comparable to a clean build.)

### A6.18 WP1 regression checks

- **WP1a:** the ten `text-primary` decisions hold (A6.3); the seven `legacy-text-primary` sites and the `legacy-*` utilities are untouched; the `hover:legacy-bg-surface` sites in Blade are untouched. No `legacy-legacy-*`.
- **WP1b:** the six orphans keep their mappings (guard test); Blade routes pixel-identical.
- **WP1c:** every canonical token and text / shadow / motion exposure still passes the 39 original assertions; `ring → focus`, `destructive → danger` and every held alias are unchanged. The `primary` guard was replaced deliberately (A6.19), not deleted. Compiled CSS carries `--primary: var(--ds-ink)`.
- **WP1d:** `DirectionDTypographyContractTest` green; fonts, preloads and `@font-face` untouched; no metric or family change.

### A6.19 Tests and static guards

Vitest **378 → 440** (54 → 62 files, +62; 436 after the first pass, +4 in A6.25): new `button`, `input` (+ `NativeSelect`/`Textarea` scale contract), `alert`, `status`, `avatar`, `section`, `page-header`, `project-status-badge`; extended `progress`, `flash-region`, `pagination`, `confirmation-dialog`, `task-status-badge`, `auth-pages`. They assert semantics (roles, names, labels, `aria-pressed`, disabled, focus return, Esc, hostile strings as text, distinct glyph shapes, `aria-valuenow`) and only these styling contracts: primary is ink and never accent / live, aliases equal their targets, no duplicate classes per property, shared control height, no pill or card on `Status` / `SectionPanel`. Pest **972 → 982** (`DirectionDThemeContractTest` 44 cases; 980 / 42 after the first pass, +2 in A6.25): the `primary` guard now pins `--primary: var(--ds-ink)` / `--primary-foreground: var(--ds-on-ink)` with the reason in the test, a new source scan fails if an accent-meaning `primary` idiom returns, the `legacy-success/-warning` retirement, and the radius and reduced-motion animation tokens. **Mutation-checked:** flipping `--primary` back and adding a `text-primary` string each failed their test; restored.

### A6.20 Validation

- `./dev check`: CLI self-tests, `git diff --check`, Pint, Wayfinder + `tsc` + ESLint `--max-warnings=0` + Prettier + Vitest + production build, Pest: all pass (first pass 436 / 980; final tree 440 / 982 with 4,119 assertions, A6.25). (One earlier run failed a `user.type` of 400 characters and, in the next run, an existing 5 s dialog test purely because a stray hung `tinker` process left over from an earlier step was saturating the container; the offender was killed and the tests, one of them mine and rewritten to `paste`, then passed.)
- **Playwright against the final tree** (after the A6.25 remediation): **46 / 46 passed** (`./dev test:e2e --workers=1`, 9.3 min, Chromium via the live nginx stack serving the final `public/build`), covering auth, projects, milestones (dialogs, focus return), board Move-menu keyboard flow, pointer drag, tasks, task detail and time. Product-data counts afterwards differed only by the one standalone task that the `tasks-migration` D3 spec leaves behind by design; it was deleted and counts verified at baseline. The runs made *before* the remediation are superseded and are not evidence for the final tree.
- **Earlier runs, stated plainly.** A first parallel run (8 workers) had 10 failures (timeouts, one rate-limited sign-in, a shared-operator interaction) and leaked a project and a task. An early serial run had two 30 s timeouts (`milestones` read-only member, `projects` edit-page safety) that passed when re-run in isolation and in the next full run. **These were not conclusively classified**: no pre-WP2 (HEAD) run of the suite was made, so it is not established whether they were environmental (the container was also saturated by a stray `tinker` process around then) or a WP2 effect. The claim made here is only that the final tree passes; nothing is claimed about the cause of the earlier failures. The `tasks-migration` D3 spec leaves one standalone task per run by design, which is deleted after each run to restore counts.

### A6.21 Cleanup / ownership

All scratch (container `/tmp/wp2`: a HEAD copy, a clean WP2 copy, harnesses, screenshots, the lab) removed; both `artisan serve` processes stopped; the harness-created sessions deleted; fixtures deleted and counts verified at baseline (users 3, invoices 0, CRM 0, CMS 0, tickets 2, projects 4, tasks 12, roles 2, time entries 3). No component was left as scratch or demo; no Storybook; no dependency; no tracked build output; `git status` shows only the files in A6.23; nothing under `src` outside `vendor` / `node_modules` is root-owned. (529 gitignored runtime files under `storage/` (`inertia-devtools/*.json`, one compiled view), written as root by the container's PHP processes during the browser runs, were chowned back to the host user.)

### A6.22 Deviations / findings

1. **Scope narrowing of §28** (A6.1, A6.10) — `Tabs`, `Tooltip`, `Popover`, `Tag`, `Skeleton`, `EmptyState`, `ErrorState` were not built, because none has a live consumer. This is the one item for the owner to confirm. It also means "the three Radix packages, measured after WP2" (§26) is now measured where they land.
2. **Resting field edges did not reach WCAG 1.4.11's 3:1** in the first pass (`rule-control`, about 1.5:1) and a design-contract token was needed. **Fixed in the A6.25 remediation** with `control-edge`; no longer open.
3. **Hand-copied primitive classes defeated the restyle.** Four selects duplicated `NativeSelect`'s classes with legacy tokens and an `h-10`, and `quick-add-task` used a raw `<input>`. All five were reduced to the primitive. Other hand-rolled controls are bare native checkboxes (time forms, checklist) that use browser defaults; a Checkbox primitive is not in WP2's list.
4. **`transition-colors` animates `outline-color`**, so the focus ring flashed from black. Found only in the browser; the shared controls now name their transitioned properties.
5. **`buttonVariants` is used as a raw class string on eleven links, where `twMerge` never runs.** The HEAD strings carried both `text-sm` and `text-xs` for `sm`, so which won depended on CSS order. The variant strings are now conflict-free and a test pins it.
6. **`aria-disabled` styling was not added.** Direction D §15.4 prefers `aria-disabled` for controls that must stay focusable; the few such controls (move-task trigger, allocation editor) style themselves. A shared `aria-disabled:` treatment is left for the first product epic that needs it.
7. `Section` has no direct page consumer: it is exercised through `SectionPanel`'s 26. Pages adopt the stacked layout when they are rebuilt.
8. The Playwright suite shares one operator and the dev database, so parallel runs interfere with each other and leak fixtures; run it serially.
9. The dev-tree stale-view CSS residue (A4.19 #3) inflates the live stylesheet to ~91 kB; clean builds are 76 kB. CI (§30) should build from a clean view cache.

### A6.23 Files changed

`src/resources/css/app.css` · `src/resources/js/components/ui/{button,input,textarea,label,native-select,dialog-shell,confirmation-dialog,form-dialog,dropdown-menu,alert,badge,progress}.tsx` · **new** `ui/{status,avatar,control-metrics}` · `components/{page-header,section-panel,pagination}.tsx` · **new** `components/section.tsx` · `components/feedback/flash-region.tsx` · `components/projects/{project-status-badge,task-status-badge,task-card,task-comments,project-card,milestone-card,company-selector,quick-add-task}.tsx` · `pages/{auth/login,auth/two-factor-challenge,dashboard/index,tasks/index,time/index,time/allocation,operator/time/index,profile/show}.tsx` · `resources/views/layouts/partials/nav.blade.php` (9 hover classes) · tests: **new** `button`, `input`, `alert`, `status`, `avatar`, `section`, `page-header`, `project-status-badge`; modified `progress`, `flash-region`, `pagination`, `confirmation-dialog`, `task-status-badge`, `auth-pages`; `tests/Unit/Configuration/DirectionDThemeContractTest.php` · this document · `docs/design/direction-d-design-system.md` (A6.25).

### A6.24 WP3 handoff

WP3 (navigation contract) may start; it touches no primitive. What it inherits:

- **`primary` means the primary action** (ink) everywhere; any shell code should use `bg-ink` / `text-on-ink`, `border-accent-line` / `text-accent` for selected and link accents, and never reintroduce a `text|border|ring|accent-primary` idiom (guarded).
- **Interactive boundaries use `control-edge`** (≥ 3:1, A6.25); `rule-control` and `rule` are for structural hairlines only, and the rail account button should use `control-edge`.
- **A control scale and focus ring** in `components/ui/control-metrics.ts`; `Button`, `Input`, `NativeSelect` line up at 32 / 36 / 40 px. Rail and drawer controls should read it.
- **Overlay tokens and animations** (`bg-scrim`, `shadow-overlay`, `rounded-overlay`, `animate-*-in/out`) are ready for the overlay drawer; reduced motion is already handled in the token layer, not per component.
- **`Status`, `Avatar`, `Alert`, `Section`, `PageHeader` (overline)** exist for the shell and Home. `AccountTrigger` (WP4) should compose `Avatar`.
- **Deferred with a named first consumer:** `Tabs` (in-page tabbed views), `Tooltip` (disabled reasons, e.g. the rail's unavailable items), `Popover` (`TimerTray`, WP6). Add the Radix package in the WP that needs it, with its consumer.
- **Open:** the Blade native-select height (WP5), the dev-tree CSS residue (CI, §30), Blade status debt (below).

**Legacy contrast debt, unchanged:** `--surface-muted` / `--text-muted` (invoice draft / cancelled badges, 2.49 / 2.35) and `--surface-accent` / `--accent` in dark (open tickets, built-in role chips, 3.28) both live in **Blade** and Blade is pixel-identical after WP2, so **neither is resolved**. `Status` is the canonical replacement and passes its own contract (A6.12); Finance, Helpdesk and System adopt it when their Blade pages migrate.

### A6.25 Post-review remediation: resting control boundary, and scope confirmation

**Scope confirmed by the owner.** The narrowed WP2 component scope (A6.1, A6.10) was explicitly approved. `Tag`, `Skeleton`, `EmptyState`, `ErrorState`, `Tabs`, `Tooltip` and `Popover` remain deferred until a concrete consumer exists, and were not added to satisfy §28's literal list. This amendment stays authoritative for WP2's scope.

**The finding.** The first WP2 pass drew every field's resting edge in `rule-control` (light `#D2CEC3`, dark `#2A3D42`), measured at 1.44–1.63:1: a control whose only resting affordance was a boundary the eye could barely find, propped up by its label, its hover state and its focus ring. The owner rejected that as the answer to WCAG 1.4.11 for form controls, and WP2 owns foundational form-control presentation.

**Root cause.** The contract gave one token, `rule-control`, two unrelated jobs: the *interactive boundary* of inputs, buttons and chips, and a *structural hairline* (table-header rule, chip borders). At about 1.5:1 it is right for the second and wrong for the first, and raising it would have made every structural rule heavy ("structure over boxes").

**Solution: a dedicated semantic token, `control-edge`** (chosen after checking the existing ones):
- `rule-strong` (15.8:1) is a section-title rule and far too heavy for a field.
- `text-faint` happens to be 3.05:1 on `canvas` (light) but falls to 2.90 on `drawer` and 2.84 on `surface-sunken`, and it is a *text* token the spec forbids for anything informative; borrowing it for a border would blur two roles.
- `rule-control` cannot be raised without repainting its structural consumers.

| Token | Light | Dark | Role |
|---|---|---|---|
| `control-edge` | `#8B877C` | `#617679` | Resting 1px boundary of interactive controls with no fill of their own: `Input`, `Textarea`, `NativeSelect`, the `secondary` `Button` (so also `outline`, `Pagination`, the dialog Cancel). Not used for table, section or drawer rules. |
| `rule-control` | `#D2CEC3` (unchanged) | `#2A3D42` (unchanged) | Structural hairlines: chip and tag borders (`Badge`, `Avatar`), neutral `Alert`, disabled control edges. |

Values are the lightest that reach 3:1 on every surface a control actually renders on, in the palette's own hues (warm gray, teal gray), and are 1px so the 32 / 36 / 40 px control heights do not move. Filled variants (`primary` ink, `destructive`) already identify themselves by fill (15.79:1 and 6.03:1 against the page) and did not change; `ghost` has no boundary by design. Hover strengthens the edge to `text-muted` (was `text-faint`, which sat too close to the new resting edge), an invalid field recolours it `danger`, and focus adds the 2px `focus` outline, so rest, hover, invalid and focus stay four distinct states.

**Two related corrections in the same change.** The disabled label of every button and disabled field value and dropdown row moved from `text-faint` to `text-muted` (it is information; 5.46:1 light / 7.75:1 dark on `surface-sunken`), and disabled field edges use `rule-control` on a `surface-sunken` fill, so a disabled control is still an identifiable control.

**Measured contrast (Chromium, rendered sRGB composites; the edge against the surface it actually sits on).** Lab render of the built primitives on each real surface, plus the real pages:

| Pair | Light | Dark |
|---|---:|---:|
| Edge on `canvas` | 3.29 | 3.88 |
| Edge on `surface` (cards, `Section`, dialogs) | 3.59 | 3.47 |
| Edge against the field's own `surface` fill | 3.59 | 3.47 |
| Edge on `drawer` | 3.12 | 3.72 |
| Edge on `surface-sunken` | 3.06 | 3.75 |
| Edge on legacy page background (`--bg-base`) | 3.59 | 4.20 |
| Edge on legacy muted panel (gray-50 / gray-900) | 3.43 | 3.70 |
| Edge on legacy card (white / gray-800) | 3.59 | **3.06** |
| Secondary button edge (all of the above) | identical | identical |
| Real page: login (inside the auth card) | 3.59 | 3.06 |
| Real page: `projects.create`, `profile`, `time.index` | 3.59 | 4.20 |
| Hover edge (`text-muted`) on `canvas` | 5.86 | 8.00 |
| Invalid edge (`danger`) on `canvas` | 6.03 | 8.12 |
| Focus outline on `canvas` (unchanged) | 5.79 | 12.18 |
| Disabled field value / disabled button label, on `surface-sunken` | 5.46 | 7.75 |
| Disabled edge (`rule-control`, exempt) | 1.44 | 1.63 |

Every pair the field can sit on is ≥ 3:1 in both themes; the narrowest are 3.06 (light on `surface-sunken`, dark on the legacy gray-800 card). The dark legacy-card row is the real constraint: raising the value further would be heavier than needed. No value was chosen against a surface a field never sits on; the seven surfaces are the ones Input/Textarea/NativeSelect render on today or will on the Direction D shell (canvas, `Section`/card/dialog `surface`, drawer, sunken, and the three legacy backgrounds).

**Visual result.** Inspected at 1360 px and at 640 px with a 2× device scale (200% reflow equivalent), light and dark, on the login card, `projects.create`, and a lab of every state on every surface: the edge reads as a quiet mid-gray line, not a black box; rest, hover, invalid (red), focus (teal / cyan ring) and disabled (sunken fill, muted text) are all distinguishable. Control heights re-measured after the change: **36 px** for every `input`, `select` and `button` with and without web fonts on `/time`, `/projects/create`, `/profile`, `/operator/time`; 32 / 36 / 40 px for `sm` / `md` / `lg`; the textarea is unchanged. 48 route-widths from 1360 to 390 px: 0 horizontal overflow, 0 clipped controls. The Chromium interaction script (dialog focus, Esc, overlay dismiss, focus return, menu keyboard, flash) passed 70 / 70 again. 0 text pair got worse than before the remediation and none newly fell below 4.5:1.

**Blade.** The 13 Blade routes are pixel-identical (0 px) in both themes against a build of the same tree without the token, on the same fixtures (`operator/cms` shows only its relative-timestamp noise, 38–45 px). Nothing in Blade uses `control-edge` or the changed React classes, so the new token cannot reach it. The React routes changed only where a field, select, textarea or secondary button is drawn.

**Direction D spec change: yes, the canonical contract changed.** `docs/design/direction-d-design-system.md` (the markdown spec is canonical) was edited:
- §2.2 colour table: **added** `control-edge` (`#8B877C` / `#617679`, with its role and the "never for table, section or drawer rules" limit); **narrowed** `rule-control` to structural hairlines and stated it is *not* an interactive boundary; **redefined** `text-faint` as decorative only, including "not placeholders, not the label or value of a disabled control".
- §2.3 rule 5: secondary actions are `surface` + `control-edge`. Rule 7 extended (placeholders and disabled labels use `text-muted`). **New rule 8:** interactive boundaries use `control-edge`, with the hover / invalid / focus behaviour (later rule renumbered to 9).
- §4.3 rules table: `rule-control` row narrowed; **new** "Control edge" row.
- §13.1: the rail account button uses a `control-edge` ring.
- §15.4: disabled controls keep their label or value at `text-muted`, never `text-faint`.

`docs/design/direction-d-tokens.css` was **not** touched: the spec declares it a reference artifact only, not the production stylesheet and not kept in sync (spec header). Its mockup-era values are not a source for any production token.

**Implementation** follows the WP1c pattern: `--ds-control-edge` in both theme blocks of `app.css`, `--color-control-edge: var(--ds-control-edge)` in `@theme inline`, and `control-edge` added to the `DirectionDThemeContractTest` canonical list (defined once per theme, exposed to Tailwind). A new test in the same file computes the contrast of both `--ds-control-edge` values against the enumerated surfaces and asserts ≥ 3:1, and that `rule-control` is still under 2:1 against `canvas` so it cannot quietly be raised. This is the one colour-value assertion in the suite, deliberate because a wiring-only test could not stop the edge being made faint again. **Mutation-checked:** changing the light value to `#B0ACA0` failed it (2.08:1 on `canvas`); restored. Every other WP1a–WP1d and WP2 guard is unchanged and green. No dependency was added.

**Files (remediation only):** `src/resources/css/app.css`; `components/ui/{input,textarea,native-select,button,dropdown-menu}.tsx`; tests `ui/{input,button}.test.tsx`, `pagination.test.tsx`, `tests/Unit/Configuration/DirectionDThemeContractTest.php`; `docs/design/direction-d-design-system.md`; this document.

**Final validation of the tree after this remediation:** `./dev check` passes in full on the final tree (CLI self-tests, `git diff --check`, Pint, Wayfinder, `tsc`, ESLint `--max-warnings=0`, Prettier, Vitest **440 / 440** in 62 files, production build, Pest **982 passed / 4,119 assertions**), plus the serial Playwright run in A6.20.

---

## Amendment 7: WP3 Results (2026-09-25)

**Status:** WP3 (navigation contract) implemented on top of the WP2 tree. **WP4 is not started: no Direction D shell, rail, drawer or utility bar exists.** No route, no permission, no policy and no dependency changed.

### A7.1 Method

Tests first, in the order [R9](#32-risks-and-rollback) requires. `NavigationBuilderTest` was first extended **against the pre-WP3 builder** with a recursive whole-payload leakage helper, two leakage cases and the three [A1.10](#a110-presentation-neutral-contract--challenged-against-live-data-one-defect-found) collision characterizations; that suite ran green on the unreshaped tree (14 passed, 167 assertions) and only then was the builder reshaped. The characterizations were then rewritten as the post-reshape assertions the same three routes must now satisfy, so the collisions are pinned from both sides.

Every actor in the matrix is built from the real authorization model — the two seeded roles, or a real Spatie role synced to an explicit [`PermissionCatalogue`](../../src/app/Shared/Permissions/PermissionCatalogue.php) subset. No test grants capability by any path the application does not use.

### A7.2 The canonical contract, as implemented

`NavigationBuilder::build(Request): array` returns one object, matching [§12.2](#122-target-shape):

```php
[
  'currentWorkspace' => 'helpdesk',            // null when the route belongs to no workspace
  'workspaces' => [
    [
      'key' => 'helpdesk', 'label' => 'Helpdesk', 'icon' => 'life-buoy',
      'href' => '/tickets', 'visit' => 'document', 'isActive' => true,
      'context' => [
        ['key' => 'views', 'label' => 'Views', 'kind' => 'views', 'items' => [
          ['key' => 'helpdesk.requests', 'label' => 'My requests', 'href' => '/tickets',
           'visit' => 'document', 'isActive' => true, 'count' => null],
          ['key' => 'helpdesk.queue', 'label' => 'Queue', 'href' => '/operator/tickets',
           'visit' => 'document', 'isActive' => false, 'count' => null],
          ['key' => 'helpdesk.reports', 'label' => 'Reports', 'href' => '/operator/tickets/reports',
           'visit' => 'document', 'isActive' => false, 'count' => null],
        ]],
      ],
      'presentation' => ['operational' => ['panel' => 'open']],
    ],
    // …
  ],
]
```

Guests receive `['currentWorkspace' => null, 'workspaces' => []]` rather than the pre-WP3 `[]`: the shape is now stable for every actor, so no consumer branches on it.

**Semantic boundaries held.** `context` is content and names no shell concept — asserted by walking every key under `context` for the absence of `drawer`, `rail` and `panel`. `presentation` carries hints only: stripping the whole key leaves `currentWorkspace`, every workspace `key`/`href`/`visit`/`isActive` and every `context` section and item byte-identical, asserted for an operator and a `user`-role actor. Presentation is not a function of capability: for the same route, the two actors receive identical `presentation` for every workspace both can see.

**Three fields deleted, not carried forward.** `method`, `activePatterns` and `children` were all dead ([A1.9](#a19-navigation-and-renderer-baseline)) and are gone from both the canonical model and the compatibility shape. Route-name patterns are now matching input only and are never serialized — a static assertion checks that no key named `patterns`, `activePatterns`, `method` or `children` appears anywhere in the payload.

**Two enums make the closed sets explicit** rather than leaving them as loose strings: `ContextKind` (`views`, `queues`, `entities`, `saved`, `actions`) and `PanelDefault` (`open`, `collapsed`; "no panel" is the absence of a default, expressed as a null hint beside an empty `context`, never a third case). Both serialize to their string values, so the wire format is exactly §12.2.

### A7.3 Workspace map

Declared order is rendered order, independent of capability — asserted across four actor profiles.

| # | Key | Label | Icon | Destination | Visit | Panel default | Context (`views`) | Gate |
|---|---|---|---|---|---|---|---|---|
| 1 | `home` | Home | `house` | `dashboard` | inertia | **null** | **none** | authenticated |
| 2 | `projects` | Projects | `folder-kanban` | `projects.index` | inertia | open | `projects.all` · *(`actions`: `projects.create`)* | `projects.view`; action on `projects.manage` |
| 3 | `tasks` | Tasks | `list-checks` | `tasks.index` | inertia | collapsed | `tasks.mine`, `tasks.org` | authenticated; org view on `tasks.view_org` |
| 4 | `helpdesk` | Helpdesk | `life-buoy` | `tickets.index` **or** `operator.tickets.index` | document | open | `helpdesk.requests`, `helpdesk.queue`, `helpdesk.reports` | `tickets.view` or `tickets.assign`; queue/reports on `tickets.assign` |
| 5 | `time` | Time | `clock` | `time.index` **or** `operator.time.index` | inertia | collapsed | `time.mine`, `time.allocation`, `time.reports` | `time.log` or `time.view_all`; reports on `time.view_all` |
| 6 | `directory` | Directory | `contact` | `crm.contacts.index` | document | open | `directory.people`, `directory.organizations`, `directory.portal-access` | `crm.manage` |
| 7 | `finance` | Finance | `receipt` | `billing.invoices.index` **or** `billing.client.invoices.index` | document | open | `finance.invoices` **or** `finance.my-invoices` | `billing.manage` → operator; else `billing.view` → own |
| 8 | `system` | System | `settings` | first permitted of Users / Roles / Pages | document | open | `system.users`, `system.roles`, `system.pages` | any of `users.view`, `roles.view`, `cms.edit` |
| 9 | `resources` | Resources | `library` | `cms.index` | document | **null** | **none** | `cms.view` **and not** `cms.edit` (G3, transitional) |

**Nothing future-only is emitted.** No Incidents, Knowledge, Relationships, Retainers, Rates, Budgets, Settings, Integrations, saved views, watch lists, recent entities or All-Tasks split. `entities` and `saved` have no emitter, and neither does `queues`: `count` is reserved and always null, so a queue section would render a count slot nothing can fill. Helpdesk Queue is recorded as `queues`' named first consumer once an endpoint provides counts — a `kind` change on an existing section, not a new concept. This narrows §12.2's "`entities` and `saved` are never emitted in this epic" to three unemitted kinds, and is asserted (`entities` and `saved` absent; every emitted `kind` in the enum).

**One deviation from the eight-workspace target, and it is required.** `resources` is the ninth, transitional item: decision gate **G3**, confirmed in [A1.6](#a16-g3--the-transitional-pages-item-is-confirmed-across-seven-actor-profiles). The built-in `user` role holds `cms.view` by default and `/pages` is a read-only published-page viewer, so omitting it would remove every customer's access to published content. It is labelled **Resources** to match the surface's own heading, per A1.6's label correction; the route name is untouched.

### A7.4 Capability filtering, pruning and defaults

Filtering happens entirely server-side, through `$user->can()` on catalogue permissions, before anything reaches a client. Nothing is sent for React to hide.

The structure prunes upward, at three levels:

1. **Item** — omitted when its capability is absent.
2. **Section** — omitted when every item was omitted, so no empty section survives (asserted: no emitted section has an empty `items`).
3. **Workspace** — omitted entirely when it has no permitted destination at all, never rendered disabled or empty (§12.3 rule 7). A workspace with no contextual navigation at all is a different case and is emitted with its own single surface: `context => []` and `panel => null` agree, and neither is inferred from the other.

**A default destination cannot point at a filtered-out item, by construction.** A workspace's `href` is not declared independently; it *is* the first surviving item of its first non-`actions` section, or its own surface when it has none. Asserted across seven actor profiles: for every emitted workspace, `href` equals its first surviving contextual destination. An `actions` section can never supply it, so "New project" cannot become the Projects destination. This is also what makes the two single-entry cases correct without special-casing: `tickets.assign` without `tickets.view` lands on the queue, `time.view_all` without `time.log` lands on reports — both reachable before WP3 through the "Manage" group, both still reachable.

**Recursive leakage prevention.** `navigationValuesDeep()` flattens every `href`, `key` or named field from the whole nested payload at any depth, so an assertion cannot pass by inspecting one convenient path. For a `user`-role actor, across six routes, the complete payload contains no `/admin/`, `/operator/`, `/crm/` or `/organizations` href and none of twelve forbidden keys. The same helper is pointed at the compatibility projection, so the seam cannot leak what the canonical model withholds.

### A7.5 G3 Pages — inventory and preservation

| | Before WP3 | After WP3 |
|---|---|---|
| Who receives it | any actor with `cms.view` — including every `user`-role actor, and operators | `cms.view` **and not** `cms.edit` |
| Where it appears | top-level `primary` item, label "Pages" | top-level workspace `resources`, label "Resources" |
| Capability | `cms.view` | `cms.view` (unchanged permission; the `NOT cms.edit` term is new) |
| Navigation mode | document | document (unchanged) |
| Destination | `cms.index` | `cms.index` (unchanged) |
| Operator page management | `cms-pages` in the "Manage" group (`cms.edit`) | `system.pages` inside System (`cms.edit`, unchanged) |

Seven actor profiles are asserted as a matrix: the four `cms.view`-without-`cms.edit` profiles keep `cms.index` and the `resources` workspace; the two `cms.edit` profiles get `operator.cms.index` and **not** `cms.index`; an actor with neither gets no page surface. The one behaviour change is the deliberate G3 consequence A1.6 already recorded and accepted: an editor loses the duplicate *viewer* entry, and reaches the same published pages through the editor index, which renders `cms.show` for every row. Active state is asserted too: on `/pages` exactly `resources` is active and nothing else.

### A7.6 Active-state contract

Server-computed, via `$request->routeIs()`, as before — the client derives nothing.

- **Workspaces** may declare broad patterns. Exactly one is active: when several match, the most specific pattern wins (literal dot-segments, ignoring the wildcard) and declaration order breaks a tie. `currentWorkspace` is that key, or null.
- **Contextual items** declare **explicit route names only, no wildcards**, and are resolved **only inside the active workspace** — which is what structurally guarantees "at most one active item" rather than leaving it to pattern hygiene.
- **Most-specific-wins** applies at item level too. Where two items legitimately share one route name and differ only by query parameter, the item with more satisfied constraints wins; ties fall to declaration order.
- **`kind: 'actions'` items are never active.** They declare no routes at all, so an action cannot carry a selected strip by construction, not by pattern tuning.
- **Nested and related routes** keep their owning item active: `projects.all` on board, edit, create, milestones and task detail; `helpdesk.requests` on ticket show and create; `system.roles` on role create and edit.

The three A1.10 collisions are fixed and pinned, each as a named dataset case asserting the *complete* set of active keys:

| Route | Before | After |
|---|---|---|
| `/operator/tickets/reports` | Queue **and** Reports | `helpdesk` + `helpdesk.reports` only |
| `/time/allocation` | My time **and** Allocation | `time` + `time.allocation` only |
| `/projects/create` | All projects **and** New project | `projects` + `projects.all` only |

**One case is deliberately query-derived, on the server.** The two Tasks views are the same route (`tasks.index`) differentiated by `?view=`, so `tasks.org` declares an exact `view=org` constraint and `tasks.mine` declares none — mirroring `TaskController`, which clamps any unrecognized value to `mine`. `/tasks`, `/tasks?view=org` and `/tasks?view=nonsense` are each asserted. **Nothing is left client-derived**: no consumer performs URL matching, and the twenty-case dataset asserts the exact active set on twenty routes including one (`profile.show`) that belongs to no workspace and correctly activates nothing.

**One suppression deliberately not moved into navigation.** `TaskController` also hides its org tab when the actor's organization has no company link — data, not capability. Reproducing it here would make the builder issue an organization query on every authenticated request, and the destination is authorized and safe (the org view can only widen the list with rows the actor may already open). The gate in navigation is the real capability, `tasks.view_org`.

### A7.7 Visit / rendering-mode contract

`visit` is per destination and unchanged in meaning: `inertia` for the client visit path, `document` where a full page load is required. It is carried on workspaces **and** on every contextual item, because the two can differ — Time is `inertia` and so are its three views, while Helpdesk is `document` throughout. No parallel mechanism was introduced.

Asserted explicitly: the eight workspace modes (Home/Projects/Tasks/Time inertia; Helpdesk/Directory/Finance/System document), `resources` document, the item-level modes that differ in kind from a sibling (`time.reports` and `time.allocation` inertia, `helpdesk.queue` document, `projects.create` inertia, `system.users` document), and that every `visit` anywhere in the payload is one of the two values.

### A7.8 Drawer-state contract

**The server half only, which is all WP3 owns.** Per workspace, `presentation.operational.panel` carries the Direction D §5.3 default as `open`, `collapsed` or `null`, and `currentWorkspace` says which workspace that default applies to right now. Together those are exactly the two inputs the [§15.3](#153-recommended-architecture) pre-paint bootstrap needs in order to stamp `data-workspace` and fall back to a server default, and they are asserted bounded (`panel ∈ {open, collapsed, null}`, `presentation` containing only the `operational` key).

**No cookie was added, deliberately.** [Spike S1](#a17-s1--drawer-persistence-confirmed-unchanged-the-cookie-is-not-needed) settled this with measurement: `inertia({ ssr: false })` means nothing of the React shell paints before JavaScript runs, the pre-paint attribute technique is already proven for the theme, and every case recorded 0 CLS. §15.4's cookie fallback was explicitly not taken, and §15.3 keeps the remembered state in `localStorage['shell.operational.panel']` — client-owned, namespaced to the Operational family so a later Focused shell cannot inherit it. There is therefore **no untrusted persisted value reaching the server at all**, which is the strongest available answer to "validate rather than trust": the server never reads it. The safe default when no valid stored value exists is the per-workspace `panel` hint above, and the parse/normalize/`try-catch` rules for the stored map are [§15.3](#153-recommended-architecture) and [§15.5](#155-storage-shape), implemented with the bootstrap in **WP4/WP5**. **No visual drawer was built.**

### A7.9 Manage-group retirement

The payload has no group list at all, so a "Manage" group is now structurally impossible rather than merely absent. Its six destinations moved as [§11.2](#112-what-this-changes-and-what-it-deliberately-does-not) specifies, and all six are asserted present for an operator and absent for a `user`-role actor:

| Pre-WP3 "Manage" entry | Now |
|---|---|
| Ticket Queue | Helpdesk → `helpdesk.queue` |
| Time Reports | Time → `time.reports` |
| Organizations | Directory → `directory.portal-access` |
| CMS Pages | System → `system.pages` |
| Users | System → `system.users` |
| Roles | System → `system.roles` |

The heading is gone from both renderers now, not in WP4/WP5: `DropdownMenuLabel>Manage` is deleted from `app-layout.tsx` and the `Manage` heading from `nav.blade.php`. Nothing that used to live under it was dropped, and `operator.tickets.reports` and `time.allocation` — authorized before but never offered in navigation — are now reachable, which is the EPIC's intent rather than a widening of authorization.

### A7.10 Backward compatibility: the pre-WP4 shell

WP3 must be shippable before WP4 exists, and the two current shells have no drawer to project a workspace's `context` into. One temporary seam, `App\Shared\Navigation\LegacyShellNavigation`, flattens the canonical model into the shape they already render:

- `primary` — one entry per workspace, canonical order.
- `overflow` — the contextual destinations not already reachable as a workspace entry, labelled `"{Workspace} {Item}"` so two workspaces' "Reports" stay distinguishable in one flat list.

`overflow` is **not "Manage" renamed.** It is not derived from a management concept, carries no label or heading, and is not audience-scoped — it is purely the remainder a drawerless shell cannot otherwise reach. It is reached through one shared prop, `navigationLegacy`, and one composer key of the same name, both marked temporary at their definition; `navigation` itself is the canonical model, so the direction of travel owns the canonical name. The projection also drops the three dead fields, so the compatibility item shape is a strict subset of the old one.

**Why a seam at all, rather than two builders:** the alternative was flattening everything into one `primary` group, which puts sixteen links in a `max-w-7xl` horizontal bar and breaks the layout for real; or leaving `navigation` in the old shape and adding the new model beside it, which gives the compatibility shape the canonical name. Both were rejected. The projection lives in PHP, once, and is shared with both renderers rather than reimplemented in TypeScript.

**Deleted in WP4/WP5, as a pure removal:** `LegacyShellNavigation`, the `navigationLegacy` prop and composer key, `types/navigation-legacy.ts`, `NavigationLink`, and the `app-layout.tsx` header. Nothing in the canonical contract changes when they go.

### A7.11 Operational metadata — decision

**Kept out of the navigation item contract.** The pre-WP3 payload carried no counts, badges or status indicators, and no consumer read any; nothing was found to migrate. The single reserved slot `count` is emitted as `null` on every item, per §12.3 rule 8, so a later epic adds data rather than a field. Nothing else operational was admitted: the timer, flash messages and permission list stay on their own shared props, and `queues` — the one `kind` whose whole purpose is countable lists — is left unemitted rather than shipping a count slot nothing can fill. Navigation semantics and operational metadata therefore remain separate, and no generic item type absorbs both.

### A7.12 Shared props and types

`HandleInertiaRequests::share` gains `shell => ['presentation' => 'operational']`, `auth.user.avatar => ['initials' => …, 'url' => null]` and the temporary `navigationLegacy`; `navigation` is reshaped. Initials are derived server-side by `App\Support\Initials`, whose rule mirrors `initialsOf()` in `ui/avatar.tsx` exactly and is asserted against that component's own seven-case table, so the two renderers cannot disagree about one person. `auth.permissions` is neither widened nor narrowed. No roles, organizations or model serialization were added — asserted with `missing()`.

**One builder invocation per request** ([§12.3](#123-contract-rules) rule 11), even when both the canonical and the compatibility prop are evaluated: the middleware memoizes the payload keyed on the request, so a reused instance cannot serve another request's navigation. Blade's entry point is the new `App\View\Composers\ShellComposer`, bound to `layouts.partials.nav`; the `@php` block that instantiated `NavigationBuilder` inside the view is gone, and a static assertion keeps it gone. A parity test builds the payload both ways for the same actor and route and asserts they are identical.

TypeScript mirrors the split: `types/navigation.ts` holds the canonical `Navigation`, `Workspace`, `ContextSection`, `ContextItem`, `ContextKind`, `VisitMode`, `WorkspacePresentation` and `ShellProps`; `types/navigation-legacy.ts` holds the compatibility shape and says at the top that it is deleted in WP4. No `any`, no broad dictionaries, and `SharedPageProps` names both props with the temporary one commented as such.

### A7.13 Tests added

`tests/Feature/NavigationBuilderTest.php` — rewritten, **60 cases / 1,016 assertions** (from 3 / 24). Helpers: `navigationValuesDeep()`, `navigationActiveKeysDeep()`, `navigationKeyNamesDeep()` (recursive, whole-payload), `withoutPresentation()`, and actor builders that go through real roles and real catalogue permissions.

| Group | Cases |
|---|---|
| Shape and ordering | canonical keys at all three levels; order stable across four actor profiles; dead fields and route patterns absent; guests |
| Presentation neutrality (L17) | no shell vocabulary under `context`; `kind` in the enum and never `entities`/`saved`; stripping `presentation` changes nothing; hints family-namespaced and bounded; hints do not vary by capability; emptiness semantic on Home and non-empty on Projects |
| **Leakage (R9)** | recursive: no `/admin/`, `/operator/`, `/crm/`, `/organizations` href and none of twelve forbidden keys anywhere, over six routes; Directory withheld from three actor profiles; the same assertion against the compatibility seam |
| Capability matrix | operator vs `user` workspace sets; sub-capability filtering for Helpdesk, Time and Projects; six-case System dataset; empty workspace omitted with no empty section surviving; default destination valid for seven profiles; Finance branch verbatim; single-entry routing for `tickets.assign`-only and `time.view_all`-only |
| **Active state** | 20-case dataset asserting the complete active set, including the three A1.10 collisions, the three Tasks query cases and one no-workspace route; nested-route cases; client Finance branch |
| Visit mode | eight workspace modes, item-level modes, transitional surface, every value in the enum |
| G3 Pages | seven-profile matrix on viewer vs System destination; operator has no duplicate; `/pages` active state |
| Account-menu boundary | no profile or logout href; no group-shaped key |
| Compatibility seam | flat shape and item keys; overflow labels exact; actions excluded; no group named `management`; every pre-WP3 destination still reachable for both roles; no leakage; empty input yields no groups |

`tests/Feature/ShellContractTest.php` — **new, 7 cases**: canonical prop contents and the presentation discriminator; server-derived initials with `missing()` on roles, organizations and secrets; the retired grouping absent from the payload; Blade-composer parity with the Inertia prop from one builder; a static guard that the view layer no longer instantiates the builder; the Blade shell rendering all six ex-"Manage" destinations with **no** "Manage" heading; and the same Blade page withholding from a `user`-role actor exactly what the canonical model withholds.

`tests/Unit/Support/InitialsTest.php` — **new**: the seven-case table shared with `ui/avatar.test.tsx`.

Modified: `InertiaFoundationTest` (new shared-prop keys; its Blade-document coexistence assertions unchanged and green), `app-layout.test.tsx` (canonical + compatibility props; asserts "Manage" is not in the account menu), `auth-layout.test.tsx`, `navigation-link.test.tsx` (dead fields dropped).

### A7.14 Deviations from the written WP3 plan, and why

| # | Plan text | What was done | Why |
|---|---|---|---|
| 1 | §11.1: eight workspaces plus a transitional Pages item | Emitted as nine, key `resources`, label **Resources** | G3 confirmed in A1.6, including the label correction; omitting it would remove every customer's access to published content |
| 2 | §12.2: "`entities` and `saved` are never emitted in this epic" | `queues` is also unemitted | `count` is reserved and always null (rule 8), so a queue section would render a count slot nothing can fill. §11.1's Helpdesk row lists Queue as a **view**. Recorded with its named first consumer |
| 3 | §12.2 shows `kind` and `panel` as bare strings | Backed by `ContextKind` and `PanelDefault` enums | Closed sets made explicit; both serialize to exactly the §12.2 wire format |
| 4 | §25.1: "at most one `context` item `isActive`" | Guaranteed structurally: items are resolved only inside the active workspace, and `actions` items declare no routes | A property of the contract, not of pattern hygiene |
| 5 | A1.10 requirement 1: explicit route names, most-specific-wins | Implemented, **plus** an exact query-parameter constraint | The two Tasks views are one route differentiated by `?view=`; without it they could not be distinguished server-side, and client URL matching was not acceptable |
| 6 | §14.4 assigns `ShellComposer` to WP5 | Landed in WP3, bound to `layouts.partials.nav` | §28 WP3 assigns "add the Blade view composer; remove the `@php` builder call". WP5 rebinds it to the shell partials without changing the payload |
| 7 | §17.2: the "Manage" section is removed from both renderers (WP4/WP5) | Removed from both renderers now | WP3 retires the grouping from the payload; leaving a heading over an unlabelled bucket would have been dishonest. The destinations all survive |
| 8 | §12.5: "`share` gains exactly two keys and changes one" | Also gains the temporary `navigationLegacy` | WP3 must ship before WP4; see [A7.10](#a710-backward-compatibility-the-pre-wp4-shell). Deleted with the old shell |

**Not done, deliberately:** no rail, drawer, utility bar, breadcrumb, view switcher, account menu redesign, skip link, brand mark, pre-paint bootstrap, `data-drawer` attribute, width classes or `AppShell`. `Page.layout` lines are untouched. No page was migrated or restyled, no Direction D primitive changed, and no dependency added.

### A7.15 Regression check

- **Authorized destinations reachable.** For an operator, all twelve pre-WP3 destinations and, for a `user`-role actor, all six, are asserted present in the compatibility projection the current shell renders.
- **Unauthorized destinations absent.** Recursively, in both the canonical payload and the seam.
- **G3 Pages** — unchanged capability, destination and visit mode; the seven-profile matrix pins it.
- **Blade/document destinations** keep `visit => 'document'`; **Inertia destinations** keep `visit => 'inertia'`; asserted per workspace and per item.
- **The current shell still renders** from the compatibility contract: `app-layout.tsx` and `nav.blade.php` both read `navigationLegacy`, their tests pass, and the `inertia-coexistence.spec.ts` account-menu path still resolves ("Time Reports" is the overflow label).
- **No WP4 shell code, no primitive regression, no dependency change** — `package.json` and `composer.json` untouched.

### A7.16 Files changed

**New:** `src/app/Shared/Navigation/{ContextKind,PanelDefault,LegacyShellNavigation}.php` · `src/app/View/Composers/ShellComposer.php` · `src/app/Support/Initials.php` · `src/resources/js/types/{navigation,navigation-legacy}.ts` · `src/tests/Feature/ShellContractTest.php` · `src/tests/Unit/Support/InitialsTest.php`

**Modified:** `src/app/Shared/Navigation/NavigationBuilder.php` · `src/app/Http/Middleware/HandleInertiaRequests.php` · `src/app/Providers/AppServiceProvider.php` · `src/resources/views/layouts/partials/nav.blade.php` · `src/resources/js/layouts/app-layout.tsx` · `src/resources/js/components/navigation/navigation-link.tsx` · `src/resources/js/types/{shared,index}.ts` · tests `NavigationBuilderTest.php`, `InertiaFoundationTest.php`, `app-layout.test.tsx`, `auth-layout.test.tsx`, `navigation-link.test.tsx` · this document

### A7.17 Validation results

`./dev check` on the final tree — **all checks passed, exit code 0**:

| Step | Result |
|---|---|
| CLI self-tests (host bash, stubbed docker) | PASS |
| `git diff --check` | PASS |
| Pint (style, no changes written) | PASS |
| Frontend: `npm run check` (Wayfinder → `tsc --noEmit` → ESLint `--max-warnings=0` → Prettier → Vitest → production build) | PASS |
| PHP tests: Pest (testing DB) | PASS — **1,054 passed / 5,211 assertions**, 318.3s |

Run independently so a failure would be easy to place:

| Focused run | Result |
|---|---|
| `NavigationBuilderTest` + `ShellContractTest` + `InitialsTest` | **75 passed / 1,108 assertions**, 29.3s |
| Vitest, full | **440 / 440** in 62 files |
| Playwright `inertia-coexistence.spec.ts` | **3 / 3** — including the account-menu → Time Reports path, which the compatibility seam had to keep working, and the 390px mobile nav. Product-data counts unchanged |

**Two honest notes on the run.** Vitest produced transient `userEvent` 5,000ms timeouts on two heavily-loaded interleaved runs (a different two files each time: profile/create, then milestones/quick-add-task); each passed in isolation and the gate's own run was 440/440, so these are environment load flakes, not WP3 regressions — none of the affected files touches navigation, shared props or the changed types. Separately, one earlier full-suite run reported a single `LoginTest` failure, which was a **real and expected** consequence of the reshape: the guest payload changed from `[]` to the canonical empty shape. That assertion was updated to the new contract rather than the contract bent back, and a `navigationLegacy`/`shell.presentation` assertion was added beside it.

No Playwright remediation was attempted beyond the one navigation-relevant spec.

### A7.18 WP4 handoff

WP4 (React operator shell) may start. What it inherits:

- **One payload, both renderers.** Read `navigation` (canonical) and `shell.presentation`. `navigationLegacy`, `LegacyShellNavigation`, `types/navigation-legacy.ts` and `NavigationLink` are deleted as the header goes; that removal touches nothing canonical.
- **`icon` is a key, not markup.** Nine keys, all real `lucide-react` names: `house`, `folder-kanban`, `list-checks`, `life-buoy`, `clock`, `contact`, `receipt`, `settings`, `library`. An unknown key must render a documented neutral fallback, never throw.
- **`Drawer` projects `context`; it does not own it.** Sections arrive ordered with a `kind`; an unknown `kind` renders as a plain section. `context => []` means no drawer and the rail item links straight to the surface.
- **The panel default is `presentation.operational.panel`** for `currentWorkspace`; `null` means no panel. The remembered state, the pre-paint bootstrap, the `data-drawer` attribute and the width-class rules are WP4/WP5 work per §15.3 and A1.7's three implementation requirements.
- **Active state is given, never derived.** Exactly one workspace and at most one item carry `isActive`; do not URL-match in React.
- **`count` is null everywhere.** Render no count slot in this epic.
- **The account menu is personal-only** and receives nothing from this payload; `auth.user.avatar.initials` is ready for `AccountTrigger` to compose `Avatar`.
- **Open for WP5:** rebind `ShellComposer` to the `layouts.partials.shell.*` partials, and fix the Blade landmark divergence A1.9 records (`nav aria-label="Primary navigation"` currently wraps the whole sticky bar).

---

## Amendment 8: WP4 Results (2026-09-26)

**Status:** WP4 (React operator shell) implemented on the WP3 tree. **WP5 is not started: no Blade shell partials exist, `layouts/partials/nav.blade.php` still renders the pre-Direction-D bar, and no Blade page body was touched.** No route, permission, policy or dependency changed, and no WP2 primitive was modified.

### A8.1 Method

Inventory first, then the shell, then tests, then real Chromium — and Chromium is what earned its place here. Six defects survived a green jsdom suite and were caught only in the browser ([A8.12](#a812-defects-found-by-real-browser-validation)); four of them were in the shell, not in the tests. The jsdom suite was extended to pin each one afterwards, so the same class of bug now fails twice.

### A8.2 Pre-WP4 architecture, as found

| Part | State before WP4 |
|---|---|
| React shell | `layouts/app-layout.tsx`, a 200-line sticky header: `max-w-7xl` container, brand text link, horizontal primary nav, standalone theme toggle, Radix account menu **containing the flattened `overflow` group**, Radix mobile nav sheet, `RunningTimerBar`, `main`, global footer |
| Page coupling | 12 identical `Page.layout = (page) => <AppLayout>{page}</AppLayout>` lines; no page imported a nav component or read a permission for chrome |
| Navigation link | `components/navigation/navigation-link.tsx`, owning both the visit-mode decision **and** its active styling, typed on the compatibility shape |
| Blade shell | `layouts/app.blade.php` + `partials/nav.blade.php`, fed by `ShellComposer` since WP3 |
| Compatibility | `navigationLegacy` shared on **both** renderers; `LegacyShellNavigation` projecting workspaces + `overflow` |
| Accessibility | No skip link anywhere, no `aria-current` on any nav link, no focus management on Inertia visits, `#main-content` targeted by nothing |
| Responsive | One `md:` breakpoint (Tailwind 768) between the desktop bar and the mobile sheet |
| Favicon | `public/favicon.ico`, **0 bytes**, referenced by no `<link>` in either root view |

### A8.3 Shell component architecture

All new, under `resources/js/components/shell/`:

| Component | Responsibility |
|---|---|
| `AppShell` | The **single** shell-resolution boundary. Reads `shell.presentation` and delegates; nothing else in the application reads it (asserted) |
| `OperatorShell` | The Operational presentation: grid, skip link, rail, drawer, utility bar, `main`, live region, `TimerProvider`, panel state, `Esc`/outside-click/`Ctrl+\` behaviour |
| `Rail` · `RailItem` | 64px rail; `nav "Workspaces"`; one plain `<a href>` per workspace, no strip on the selected tile |
| `Drawer` · `DrawerSection` · `DrawerItem` | 248px panel; **projects** `context`, owning none of it; the only 2px `accent-line` strip |
| `UtilityBar` | 48px `header`; carries the breadcrumb and never workspace navigation |
| `Breadcrumb` | Workspace → active contextual item, from the server's truth |
| `ViewSwitcher` | The current view as a Radix menu, only while the panel is collapsed |
| `NavSheet` | The S-width sheet: workspaces + the current workspace's views |
| `AccountMenu` (+ its rail trigger) | Personal-only menu; 40×40 rounded-square tile containing a 28px circular `Avatar`; Appearance absorbs the old theme toggle |
| `SkipLink` | First focusable element, targets `#main-content` |
| `BrandMark` | Inlines and instance-scopes the canonical SVG |
| `ShellLink` | The one place a server destination becomes a link, owning **only** the visit-mode decision |
| `WorkspaceIcon` | Explicit, exhaustive icon-key → component map with a neutral fallback |
| `hooks/use-panel-state.ts` | Panel resolution and persistence |
| `hooks/use-page-announcement.ts` | The S2 announce-and-repair policy |

Boundaries held: shell components own layout and shell interaction; the WP3 payload owns navigation semantics; WP2 primitives own control behaviour (`Avatar`, `focusRing`, `DropdownMenu`, `Alert` via `FlashRegion`); pages own their content. `ShellLink` deliberately carries no styling — a rail tile and a drawer row look nothing alike — which is what let `NavigationLink`'s conflation go.

### A8.4 Workspace rail

Renders `navigation.workspaces` in the order received. It does not filter, sort, infer a permission, or compute active state, and it hard-codes no workspace: all nine identities render through the same path, including `resources`, which is styled **identically** to every other item (asserted) — G3 is a server authorization decision, not a visual caveat. `href` and `visit` come from the payload; `aria-current="page"` comes from `isActive`, and exactly one item carries it. Icons are client presentation metadata keyed by the stable `key` (never derived from the label); an unknown key renders a documented neutral fallback rather than throwing.

### A8.5 Contextual drawer

Sections and items render in server order with their server labels, already capability-filtered and already pruned of empty groups — the drawer adds no pruning of its own, so an empty group cannot appear because none arrives. `kind: 'actions'` renders as an action (a `Plus` glyph, the accent colour, no strip) and **never** carries `aria-current`, so an action cannot masquerade as selected even if the server ever regressed. An unknown `kind` falls through to a plain section. `count` is reserved and always null, so no count element renders at all; `presentation` is read for nothing except the open/collapsed default, and the drawer renders every section and item with the key removed entirely (asserted). There is no "Manage" concept anywhere in it: the drawer is contextual to one workspace, and administrative destinations live inside System's own `context`.

### A8.6 Drawer state, defaults and persistence

| Concern | Behaviour |
|---|---|
| Default | `presentation.operational.panel` for `currentWorkspace`; `null` means the workspace has no panel |
| Remembered | `localStorage['shell.operational.panel']`, a `{workspace: 'open'\|'collapsed'}` map |
| L pin | `localStorage['shell.operational.pin']`, a `{workspace: true}` map — **a WP4 addition** to §15.5, which enumerated only the panel map. §5.3 rule 2 needs an L pin distinct from the XL open/collapsed state |
| Resolution | Remembered → server default → collapsed. **Below XL, collapsed unless pinned** ([A8.12](#a812-defects-found-by-real-browser-validation) #4) |
| Timing | Resolved during the first render, and re-resolved during the render of a new workspace rather than in a post-navigate listener (A1.7 requirement 1) |
| Attribute | A layout effect (pre-paint) stamps `html[data-drawer]` and `html[data-workspace]`, the contract WP5's Blade bootstrap will share |
| Malformed state | Every read is wrapped: a throwing store, unparseable JSON, a JSON array/string/`null`, an unknown workspace key and a non-enum value all fall through to the server default. Every write is wrapped, so an unstorable preference cannot fail an interaction. Twelve cases asserted |
| Cookie | **None.** S1 measured 0 CLS because `ssr: false` means nothing paints before JS, so no persisted value reaches the server and there is nothing server-side to validate |

**Two things the EPIC assigns here that WP4 does not do, both recorded rather than quietly skipped.** The **per-surface override** (§15.5's `<workspace>:<surface>` key) is not implemented: the WP3 contract carries no surface identity, so there is no key to write, and inventing one would put a navigation concept in the client. The **shared `partials/shell/bootstrap.blade.php`** is left to WP5: on an Inertia page nothing paints before React, so the pre-paint script is the Blade renderer's need, and §14.4 assigns both it and the `layouts/app.blade.php` rewrite to WP5.

### A8.7 Responsive behaviour

Width classes are CSS, keyed off `html[data-drawer]` plus `data-panel`/`data-pinned` on the shell. **Docked versus overlay is entirely a CSS decision** — the drawer defaults to floating and the width rules un-float it where it may dock — so no JavaScript asks how wide the viewport is for geometry.

| Class | Behaviour, measured in Chromium |
|---|---|
| **XL** ≥ 1360 | Rail 64px + drawer 248px docked per state; canvas starts at 312px; utility bar 48px |
| **L** 1024–1359 | Rail; the panel starts collapsed and opens as an overlay at x=64 with no scrim; **Pin** docks it and is remembered (canvas moves to 312px) |
| **M** 768–1023 | Overlay only; the pin affordance is hidden in CSS |
| **S** < 768 | Rail becomes a 56px top bar; the workspace list, the drawer and the rail toggle are `display: none` — removed from the accessibility tree, so the sheet is the *single* set of workspace links, not a duplicate; sheet rows ≥ 44px |

Verified at 1440, 1200, 390 and at 640×450 (the CSS equivalent of 200% zoom at 1280): no horizontal overflow at any width, and at the narrow widths the sheet trigger and account control are both reachable.

### A8.8 Account trigger and avatar

The trigger is a 40×40 rounded-square `<button>` (radius 6, `surface` + `control-edge` ring) containing a 28px circular WP2 `Avatar`, with `aria-haspopup="menu"`, `aria-expanded` and the name "Account menu: {name}" (L9, §13.1). The avatar text is `auth.user.avatar.initials` — the shell derives nothing, so Blade and React cannot disagree about one person. Contents: Profile, Security & MFA, Connected accounts, Sessions (2–4 anchoring into the existing `profile/show` sections per §17.2), an Appearance `radiogroup` with Light and Dark only, a disabled Notifications row carrying its reason, and Sign out (a `router.post`). Meta values ("MFA on", "N active") are deliberately absent: no shared prop carries the truth, and shipping "Not set up" to someone with MFA on would be worse than shipping nothing. **The retired "Manage" grouping cannot return**: this component receives no navigation payload at all, and its absence is asserted in Vitest and in Chromium against an operator who holds every permission.

### A8.9 Navigation correctness

Active state is rendered, never computed: no `window.location`, no pathname matching, no label or key heuristics anywhere in the shell. Chromium confirms exactly one rail item carries `aria-current="page"` across Home, Projects, Tasks and Time, and the breadcrumb follows. The three WP3 collision routes are asserted to render a single active contextual item — `helpdesk.reports` on `/operator/tickets/reports`, `tasks.org` on `/tasks?view=org`, and `projects.all` (never the action) on `/projects/create`.

Visit mode is honoured per destination in all three places the shell renders a link — rail, drawer and sheet. Chromium proves the crossing in both directions: Helpdesk unmounts React and the Blade shell renders; the Blade shell's own navigation returns to `/dashboard` and remounts the Direction D shell; and `resources` (`/pages`) behaves the same way. Time Reports, which moved from the account menu into Time's `context`, is reached and used through the drawer.

### A8.10 Accessibility

Landmarks: `nav "Workspaces"`, `nav "{Workspace} views"`, `header`, `main`, plus `nav "Breadcrumb"`. The skip link is the first Tab stop, visible on focus, and lands focus in `main` (measured). `aria-current="page"` marks the selected rail item and the selected drawer item, and nothing else. Rail and drawer are plain `<a href>` in normal Tab order — no roving tabindex, no arrow-key handlers, asserted both in jsdom and in the browser — while the two genuine composite widgets (account menu, view switcher) are Radix menus with correct semantics. The focus ring is the WP2 `focusRing`, measured at 2px in Chromium. `Ctrl+\` toggles the panel and is inert while a text field has focus. The overlay panel returns focus to the rail toggle on `Esc`; the sheet is a Radix Dialog, so `Esc` and focus return come from it. No state is conveyed by colour alone: the selected rail tile carries weight and a ring, the selected drawer row carries the strip and a ring, and both carry `aria-current`.

**Two heading-outline decisions.** The drawer's workspace name and its section labels are **not** headings: an `<h2>Projects</h2>` above the page's own `<h1>Projects</h1>` put a duplicate in the heading outline. Section labels use `role="group"` + `aria-labelledby`, which conveys the grouping without polluting the outline.

**Focus on Inertia visits** implements the S2 decision exactly: announce every page change politely, and move focus to `#main-content` **only** when `document.activeElement` is `body`/`documentElement` — meaning the activating control was inside the replaced subtree. The live region is cleared before it is re-set so identical consecutive headings still announce, and it carries `data-shell-announcer` so it is distinguishable from a page's own live region.

### A8.11 Blade/React after WP4, and legacy cleanup

| Renderer | Navigation input after WP4 |
|---|---|
| React | `navigation` (canonical) + `shell.presentation`, through `AppShell` |
| Blade | Unchanged: `ShellComposer` → `navigationLegacy` → `partials/nav.blade.php`. WP5 replaces it |

WP4 touched Blade in exactly two ways, both shell-level and neither a page body: a `<link rel="icon">` in both root views, and nothing else. `layouts/app.blade.php`'s rewrite, the shell partials and the shared pre-paint bootstrap all remain WP5's.

**Deleted**, because WP4 replaces them outright rather than leaving two shells alive: `layouts/app-layout.tsx` and its test, `components/navigation/navigation-link.tsx` and its test, `types/navigation-legacy.ts`, and `public/favicon.ico` (0 bytes, referenced by nothing).

**`navigationLegacy` / `LegacyShellNavigation`: kept, narrowed, still explicitly temporary.** The React side no longer needs the flattened shape, so `HandleInertiaRequests` stopped sharing it and the TypeScript type is gone. `ShellComposer` still supplies it to `partials/nav.blade.php`, which is its one remaining consumer and is WP5's to delete. Removing it globally now would break the Blade shell; keeping it on the Inertia payload would have shipped bytes nothing reads.

**The favicon** is generated from `intechral-logo-compact.svg` as `public/favicon.svg`, changing only presentation attributes — the gradient fade is flattened to full opacity so the cyan reads on a light tab strip. A true multi-size `.ico` needs a raster toolchain, which would be a dependency; the SVG icon is what WP4 ships and the broken 0-byte file is gone.

### A8.12 Defects found by real browser validation

Every one of these passed jsdom. This is the section that justifies §25.3 existing.

| # | Defect | Cause | Fix |
|---|---|---|---|
| 1 | **Initial focus landed in `<main>`**, so the user's first Tab skipped the skip link and the whole shell | Inertia fires `navigate` for the first page too, and the S2 repair treated "focus at document start" as "focus destroyed" | Ignore the first `navigate`; nothing was destroyed on load |
| 2 | **Every width class was inert** — the rail list, the sheet trigger and the pin stayed visible at all widths | The shell CSS sat in `@layer components`, so `display: none` lost to `.flex` from Tailwind's utilities layer | Shell geometry is unlayered, and scoped under `[data-shell='operational']` |
| 3 | **Clicking into page content collapsed a docked panel**, shifting a grid column under the cursor; board drags and form clicks landed in the wrong place | `Esc` and outside-click dismissed the panel at every width | Both are overlay-only affordances (§5.4, §14.3). The shell asks the CSS whether the panel floats rather than re-deriving breakpoints, so geometry stays in one place |
| 4 | **A 248px overlay covered the canvas on load** at L and M, intercepting clicks on the left of every page | The workspace default of `open` was honoured at every width | Below XL the panel starts collapsed unless pinned (§5.2). One `matchMedia` read per resolution, no listener, so no resize race |
| 5 | The drawer's `<h2>` **duplicated the page `<h1>`** in the heading outline | The workspace name was marked up as a heading | Not a heading; section labels use `role="group"` + `aria-labelledby` |
| 6 | The drawer action's accessible name was **"+ New project"** | An invented `+ ` text prefix rewrote a server-owned label | The affordance is an `aria-hidden` icon; the label is the server's own words |

Three existing specs needed updating for legitimate new facts, not to accommodate a defect: two asserted on `[aria-live="polite"].sr-only`, which the shell's own announcer now also matches (narrowed with `:not([data-shell-announcer])`); and `projects-migration` matched both the page's primary button and the drawer's workspace action for `/projects/create`, so it is scoped to `main`.

### A8.13 Tests

**Vitest, new — 70 cases across 8 files.** `rail` (7): server order, conditional `resources` styled identically, single `aria-current`, per-destination visit mode, the L11 guard (no `tabindex`, no `menu`/`tablist` role), toggle only while collapsed, unknown icon key. `drawer` (11): section and item order, active item, action never selected, renders with `presentation` removed, visit modes, collapse disclosure state, pin, focus-in on a deliberate open, no scrim, no count element. `account-menu` (7): named trigger with a circular avatar, initials taken from the server, the personal items, **absence** of eight administrative labels and "Manage", profile anchors, Appearance radiogroup flipping `data-theme`, sign-out posting. `app-shell` (22): landmarks, authorized workspaces only, absent workspaces absent, no panel for an empty `context`, both server defaults, remembered collapse, malformed state, toggle + persistence, `Esc` on a floating panel with focus return, **docked panel left alone** by `Esc` and by a content click, floating panel dismissed by a content click, `Ctrl+\` including the text-field guard, breadcrumb, view switcher, nav sheet, visit modes in all three renderers, the two collision routes, the announce/repair policy including the initial-load case, below-XL collapse. `use-panel-state` (16): resolution order, the attribute contract, per-workspace persistence, re-resolution on an Inertia workspace change, the L pin, six malformed-storage shapes, a throwing store, an unstorable write, below-XL collapse, pin honoured below XL, unavailable `matchMedia`. `brand-mark` (6): the `brand-mark` class, distinct ids and self-resolving gradients for two marks, stripped `title`/`desc`, removed `vector-effect`, class scoping, label escaping. `skip-link` (2).

**Pest, new — `tests/Unit/Configuration/ShellSeamContractTest.php`, 17 cases.** Static assertions over the source tree, because "no page knows the shell exists" cannot be observed by rendering one page: the page tree is non-empty (so nothing is vacuous), all 12 `Page.layout` lines name `AppShell`, no page references any of 12 shell identifiers, no page reads `shell.presentation`, exactly one component reads it, and the pre-WP4 shell files are gone.

**Playwright, new — `tests/Browser/shell.spec.ts`, 14 flows** covering §25.3: measured geometry in both themes, rail navigation with `aria-current` and the breadcrumb, the React→Blade→React crossing, per-workspace persistence across a reload, corrupt-state fallback, the L overlay and pin, `Esc` with focus return, the personal-only account menu and sign-out, the skip link, plain-Tab reachability with a measured 2px focus ring, capability filtering plus a direct 403 (visibility ≠ authorization), the G3 Resources document destination, the narrow shell and the wide canvas, and 200% reflow. Plus `support/shell.ts` helpers.

**Modified:** `inertia-coexistence.spec.ts` (updated to the new semantics as §16 requires, not deleted — the mobile assertion became a nav-sheet assertion including capability filtering and a measured 44px target), `auth-migration` and `time-migration` (account trigger renamed; Tickets became the Helpdesk workspace; the brand mark is no longer a link), `board-migration` and `board-drag` (live-region selector narrowed), `projects-migration` (action scoped to `main`), `test/inertia.tsx` (the `Link` double marks Inertia visits so visit mode is observable, and `router.on` is supported so the announcement policy is testable), `auth-layout.test.tsx`, `ShellContractTest`, `LoginTest`.

### A8.14 Deviations from the written WP4 plan

| # | Plan | What was done | Why |
|---|---|---|---|
| 1 | §28: "Width classes XL/L/M/S in CSS", §16: preference ignored "in CSS, not in JS" | Geometry is entirely CSS; the **initial state** below XL uses one `matchMedia` read | §5.2 says the drawer is "collapsed by default" at L — a statement about state, not geometry. Pure CSS could suppress the overlay but then could not keep the toggle reachable. No listener, so §16's resize-race concern does not arise |
| 2 | §15.5 storage shape | Added `shell.operational.pin` | §5.3 rule 2 needs an L pin distinct from the XL panel state; §15.5 enumerated only the panel map |
| 3 | §15.5 per-surface override | **Not implemented** | The WP3 contract carries no surface identity, so there is no key to write |
| 4 | §14.4/§15.3 shared pre-paint bootstrap | Left to WP5 | Nothing paints before React on an Inertia page (S1), so it is the Blade renderer's need, and §14.4 is the WP5 file table |
| 5 | §13.1 composition shows `<SearchTrigger />` and `<TimerPill />` | Neither rendered; `RunningTimerBar` still renders below the utility bar | Search is NEXT; the timer pill is WP6, which also deletes `RunningTimerBar` (§13.3) |
| 6 | §13.2: the breadcrumb takes "a page-supplied trail prop" | Trail is workspace → active view | No page supplies one, and page bodies are untouched in WP4. The trail still ends with the current page |
| 7 | §8: "at most one strip per screen" | Also enforced in presentation | WP3 guarantees it server-side; the drawer refuses `aria-current` on an action regardless, so a regression in either layer cannot show two |
| 8 | Not in the plan | Inertia progress bar recoloured from `#4f46e5` to the Direction D accent | It is shell chrome carrying a hard-coded legacy indigo (§4.3). A JS literal cannot read a custom property, so the value is duplicated deliberately |
| 9 | §10.3: "Favicon **generated** from the compact asset" | `public/favicon.svg`, not `.ico` | A multi-size `.ico` needs a raster toolchain, i.e. a dependency. The 0-byte `.ico` is deleted |

**Not done, deliberately:** no Blade shell partials, no `layouts/app.blade.php` rewrite, no Blade page body touched, no `PageFrame` or content-width work (WP7), no timer pill or tray (WP6), no Home adoption (WP7), no `Tabs`/`Tooltip`/`Popover` dependency, no page content redesigned, no WP2 primitive changed, no token added or altered beyond the shell geometry block and the `brand-mark` stop override §10.3 specifies.

### A8.15 Validation results

`./dev check` on the final tree — **all checks passed, exit code 0**:

| Step | Result |
|---|---|
| CLI self-tests (host bash, stubbed docker) | PASS |
| `git diff --check` | PASS |
| Pint (style, no changes written) | PASS |
| Frontend: `npm run check` | PASS — Wayfinder, `tsc --noEmit`, ESLint `--max-warnings=0`, Prettier, **Vitest 505 / 505 in 67 files**, production build |
| PHP tests: Pest (testing DB) | PASS — **1,071 passed / 5,631 assertions**, 294.8s |

Focused runs, each on the final tree:

| Run | Result |
|---|---|
| Vitest, shell + hooks (8 files) | **70 / 70** |
| Pest `ShellSeamContractTest` | **17 / 17** |
| Playwright `shell.spec.ts` | **15 / 15** (2.3m) |
| Playwright `inertia-coexistence.spec.ts` | **3 / 3** |
| Playwright, full suite | **58 passed, 3 failed** — every one of the three passes in isolation on this tree (below) |

**The three full-suite failures, as understood when this section was written — superseded in part by [A8.18](#a818-post-review-remediation-the-browser-suites-authentication-architecture).** Only the `auth-migration` diagnosis below survived a controlled re-run; the contention reading of the other two did not. The suite runs `fullyParallel: false` on one worker in a WSL2 container, and the whole run takes ~10 minutes.

- `auth-migration.spec.ts:6` — **Fortify's rate limiter**, not a shell defect. It is five attempts per minute per email, and this test deliberately submits a wrong password *and* drives the login form directly instead of through the 429-aware `signIn` helper, so it consumes two of the five. Reproduced and proved: it fails when run immediately after another suite has spent the window, and passes on its own after waiting the window out (4/4). No change made — weakening it, or routing it through the helper, would remove the point of a test that asserts the invalid-credentials path.
- `board-drag.spec.ts:125` and `time-migration.spec.ts:200` — both pass individually on this tree (7.7s and 7.0s). A *different* board-drag case failed in the previous full run and passes now, which was read at the time as timing sensitivity under load. **That reading was not proof and is withdrawn:** a failing case moving between runs does not establish that a real regression is impossible, and the controlled re-run in A8.18 found the suite was structurally overrunning Fortify's login limiter. The cause of these two specific failures remains unresolved.

The Vitest suite shows the same load sensitivity: at default parallelism a different arbitrary subset of `userEvent` tests times out at 5,000ms on a loaded machine (14 in one run, 1 in the next, 0 in the gate's own run), while `--maxWorkers=2 --testTimeout=20000` passes 499/499 and every individual file passes alone. The mechanism is visible in Vitest's own summary: 67 jsdom environments, 46% of tracked time in environment creation. **No test timeout was raised and no assertion was weakened** to accommodate it; it is recorded here as an environment property for the CI handoff ([§30](#30-ci-handoff)) to size runners for.

### A8.16 Regression check

- **Capability filtering is still entirely server-side.** The shell has no filter, no permission read for chrome, and no hierarchy construction; an absent workspace is absent from the payload. Chromium confirms a `user`-role actor sees no System and no Directory, and `admin/users` still answers 403.
- **G3 `resources` is correct**: visible for the `cms.view AND NOT cms.edit` actor, styled like every other workspace, `document` mode, no panel.
- **All nine workspace identities** flow through one path; none is special-cased.
- **The WP3 collision fixes render intact**, asserted per route.
- **Document vs Inertia** is honoured in rail, drawer and sheet, and proven across both boundary crossings.
- **No "Manage" anywhere**: not in the payload, not in the account menu, not in the drawer.
- **Account actions work**: profile anchors, Appearance, Sign out (posts).
- **Initials remain server-derived**; the shell derives none.
- **Page content is unchanged** — no page file was edited except its one `Page.layout` line and import.
- **WP2 primitive contracts intact**: `Avatar`, `focusRing`, `controlHeight`, `DropdownMenu`, `Alert` are composed, not modified.
- **No unauthorized destination enters the DOM** through shell transformation: the shell adds no destination of its own.
- **No dependency changed** (`package.json`/`composer.json` untouched).
- **WP5 has not started.**

### A8.18 Post-review remediation: the browser suite's authentication architecture

**This was evidence-driven, not planned.** WP4 was reported as ready with the full Playwright suite at
**58 passed / 3 failed**, and the three failures explained as resource contention because each passed
in isolation. The owner declined to accept "the failing case moved" as proof and asked for a
controlled re-run. That was the right call, and it found something different.

**The controlled run made it worse, which refuted the contention explanation.** On a genuinely idle
machine (load 0.35, no other test process, a cleared limiter window, `./dev db:backup` taken first)
the suite came back **53 passed / 8 failed** — worse than under load. Measuring `POST /login` in nginx's
access log during that run explained why:

| Window | Logins | 302 | **429** |
|---|---:|---:|---:|
| Full suite, before remediation | 114 | 69 | **45** |
| Six isolation runs, before remediation | 6 | 6 | **0** |

Per minute during the suite: 9 logins in the first minute, then **11, 8, 6, 6, 4, 4, 3, 2, 1** refusals
in minutes 2–10. **The suite was structurally overrunning Fortify's five-per-minute limiter** — 39% of
its login attempts were rejected — and two tests failed with the helper's own
`stayed rate limited` after exhausting all three back-off attempts. There were no 419s and no 5xx: the
limiter was the only server-side anomaly.

**Cause, stated plainly.** Every spec submitted the real login form once per test: 64 call sites, 53 of
them as the operator. **WP4's own `shell.spec.ts` added 13 of those**, taking the operator from ~40 to
53 and pushing an already-marginal suite from "usually absorbed by back-off" to "regularly exhausted".
The architecture was fragile before WP4; WP4 is what broke it.

**The remediation.** A Playwright `setup` project authenticates **once per persona** through the real
login form and saves the resulting **cookies only**; ordinary feature specs start from that state and
never touch the login form. The state is deliberately not a full `storageState`: a captured
`localStorage` would bake this shell's own appearance theme and any drawer/pin state into the baseline
every spec inherits, so the drawer-persistence tests could no longer establish their own initial
conditions. Artifacts land in `tests/Browser/.auth/` and are gitignored — they are live session
material. Nothing forges a cookie, no limiter or production authentication changed, and no back-off was
added to duck the limit.

**Two personas, kept distinct.** `operator` and `member` (the built-in `user` role). They are not
interchangeable: the capability-filtering, read-only-board and non-owner specs assert what *that* actor
may reach, so collapsing them onto the operator would have deleted authorization coverage to save
logins.

**Four flows intentionally keep the real form**, one each:

| Spec | Why |
|---|---|
| `auth-migration` | Authentication is its subject — invalid credentials, the logout transition, and password confirmation where a fresh authentication event is the point |
| `shell.spec.ts` (sign-out) | It signs out. Sessions are **database-backed**, so it destroys the session row it presents; on the shared cookie every later spec would run unauthenticated |
| `inertia-coexistence` (profile) | It edits the operator's own name and email; a failure part-way would leave the baseline describing a different person |
| `projects-migration` (manager) | Creates its own actor with a per-run email, so there is no reusable state to mint |

**One isolation defect the remediation itself introduced, found and fixed.** Sharing a cookie means
sharing the Laravel **session**, which carries flash data: the first run after the refactor had a test
asserting on `getByRole('status')` resolve to a stale *"Success: Project deleted."* left by the previous
test's teardown. `E2eCleanup` now drains the flash bag with one throwaway read after its deletions, so
a teardown's leftovers cannot surface in the next test.

**Login traffic, after:**

| Measure | Before | After |
|---|---:|---:|
| `POST /login` per full suite | 114 | **10** |
| 302 | 69 | 9 |
| **429** | **45** | **1** |

The suite still produced **one HTTP 429 in 10 `POST /login` requests, and that is not resolved.** It
was not traced to a specific request, so its cause is unknown; a plausible source is the four
intentional real logins landing close to the two setup logins, but that was not verified. The
run's failures were not attributed to it, but that too was not established request by request. It is
carried as browser-suite follow-up below rather than explained away, and no production authentication,
rate limit or back-off was changed in response. Suite wall time fell from **9.7m to 2.9m**, and
`board-drag.spec.ts` alone from 5.5m to 1.5m.

**What this did not fix, honestly.** The full suite is still **50 passed / 13 failed**. The
authentication failures are gone, but the *pre-existing* ones grew:

| | Before remediation (53/8) | After (50/13) |
|---|---|---|
| Rate-limit failures | 2 (`auth-migration`, `board-migration`) | **0** |
| `board-drag`, `board-migration`, `milestones`, `projects-migration`, `task-detail`, `tasks-migration` | 6 | 13 |

The same specs were already failing before this pass. Every one passes in isolation; the failing set
moves between runs; and the four worst specs fail **as a group** (9 of 19) while each passes alone. The
symptoms are `toHaveURL`/`toContainText` timeouts and abandoned requests (`499`), with **no 4xx or 5xx
from the application** — `POST /projects` returns 302 and the browser is still mid-visit when a
five-second expectation expires. The plausible mechanism is that removing roughly seven minutes of
limiter back-off removed accidental pacing, so latent fixture-isolation and timing weaknesses in those
six specs surface far more often at 3.4× the request rate.

**That mechanism is a hypothesis, not a finding, and the root cause of these failures is unresolved.** It
is also not shown to be WP4's product code: `./dev check` is fully green on this tree, every shell flow
passes, and **no reproducible WP4 product defect — shell interaction, layout, focus, authentication or
authorization — was found.** That is an absence of a reproduction, not proof the failures are harmless:
they were not diagnosed to a cause, and the same specs failed in the controlled run before the
authentication change.
Closing it means work on E2E fixture isolation and suite pacing — deliberately out of this pass's scope,
and recorded here as the outstanding item rather than hidden by a raised timeout or a weakened
assertion. **No test timeout was increased and no assertion was weakened anywhere in this pass.** The
one test change beyond the authentication refactor was to WP4's own tab-order test, which now waits for
the panel to render before walking Tab stops — it still has to reach the drawer by keyboard.

**Known follow-up, recorded and deliberately not investigated here:**

1. The full Playwright suite is at **50 passed / 13 failed** and is **not** a passing gate. The commit
   this amendment ships in was approved on the strength of `./dev check` and the WP4 shell-specific
   browser coverage, not on a green full suite.
2. The 13 failures are in `board-drag`, `board-migration`, `milestones`, `projects-migration`,
   `task-detail` and `tasks-migration`. Each passes individually so far; the four worst also fail as a
   group. Their root cause is unresolved.
3. The final suite still produced 1 HTTP 429 from 10 `POST /login` requests, unresolved.
4. Older E2E fixture, timing and isolation behaviour needs its own investigation. Sharing a session
   already surfaced one such interaction (flash data leaking between tests, fixed in `E2eCleanup`),
   which suggests there may be others.
5. `E2eCleanup` cannot remove standalone tasks through any supported application endpoint.

**The standalone-task cleanup gap** ([A8.12](#a812-defects-found-by-real-browser-validation) neighbours)
was left alone as instructed and is now documented in `E2eCleanup`'s own docblock: `tasks` exposes only
`index`/`store`, so a task with no project has no delete route and the teardown has no supported
endpoint to call. Eleven such rows predate WP4. Nothing was deleted by raw SQL.

### A8.19 WP5 handoff

WP5 (Blade parity) may start. **It has not started: no file under `resources/views/layouts/partials/shell/` exists, `partials/nav.blade.php` is unchanged apart from its WP3 rewiring, and no Blade page body was touched.** What it inherits:

- **One payload, already shared.** `ShellComposer` supplies `navigation` (canonical), `shell`, `shellUser` and the temporary `navigationLegacy` to `partials/nav.blade.php`. WP5 builds the shell partials against `navigation` and deletes `navigationLegacy`, `LegacyShellNavigation` and the composer key together — a pure removal that changes nothing canonical.
- **The attribute contract is live.** React already stamps `html[data-drawer]` and `html[data-workspace]`. The width classes, the docked/overlay rules and the S reshape are all CSS keyed off those plus `data-panel`/`data-pinned` on the shell element, so the Blade shell gets the whole geometry by rendering the same attributes and hooks.
- **`partials/shell/bootstrap.blade.php` is WP5's**, per §14.4 and §15.3: one inline IIFE before `@vite` in both roots (A1.7 note 2 — the Blade root's theme script currently sits *after* `@vite`), resolving `data-theme` and then `data-drawer` from `localStorage['shell.operational.panel']` with `data-drawer-default` as the fallback, wrapped in `try/catch`. **Two rules it must copy from WP4 or it will reintroduce defects the browser suite already caught:** below XL the panel starts collapsed unless `shell.operational.pin` holds that workspace ([A8.12](#a812-defects-found-by-real-browser-validation) #4), and dismissal is overlay-only ([A8.12](#a812-defects-found-by-real-browser-validation) #3).
- **Unlayered shell CSS.** The geometry block in `app.css` is deliberately outside `@layer` so utilities cannot beat it ([A8.12](#a812-defects-found-by-real-browser-validation) #2). Blade partials reuse the same `data-shell-*` hooks rather than adding a parallel set.
- **`Initials::from()` exists** — Blade must not re-derive initials; `$shellUser['avatar']['initials']` is already computed.
- **The brand mark transform** must match `BrandMark` exactly: strip `<title>`/`<desc>` and `aria-labelledby`, prefix every id and its `url(#…)` references, scope `.s`, remove `vector-effect`, add `class="brand-mark"` and an `aria-label`. `scopeBrandSvg()` in `brand-mark.tsx` is the reference implementation and is unit-tested.
- **A landmark divergence to fix**, recorded in A1.9 and still true: `partials/nav.blade.php` puts `aria-label="Primary navigation"` on the whole sticky bar, so the brand link and account menu sit inside the navigation landmark. Direction D's `nav "Workspaces"` must contain workspace navigation only.
- **Still open for later packages:** the timer pill and tray plus deleting `RunningTimerBar` (WP6), page frames and Home (WP7), and the two Blade contrast debts A6.24 records, which are unchanged because Blade page bodies are unchanged.

## Amendment 9: POST-WP4 E2E Hardening (2026-09-27)

**Status:** Browser-test reliability follow-up, done after WP4 was committed at `27f151a`. **This is
not WP4 and not WP5.** No production application, authentication, permission or shell behaviour
changed. Only `tests/Browser/**`, `playwright.config.ts`, `database/seeders/DevSeeder.php`,
`tests/Unit/Configuration/BrowserAuthContractTest.php`, `.gitignore` and this documentation changed.

### A9.1 Starting point

Amendment 8 (A8.18) left the full Playwright suite at 50 passed / 13 failed, in six specs
(`board-drag`, `board-migration`, `milestones-migration`, `projects-migration`,
`task-detail-migration`, `tasks-migration`) that each passed individually but failed as a group, plus
one unexplained `HTTP 429` in ten `POST /login` requests. This was explicitly recorded as unresolved
follow-up, not a passing gate.

### A9.2 Phase 1: diagnosis

Static inspection plus targeted reproductions (never the full suite, per the investigation's own
budget) established two independent causes:

1. **Shared Laravel session across parallel workers.** The A8.18 remediation minted one authenticated
   session per persona *per run*, through a Playwright `setup` project, and every worker reused the
   same cookie. Every worker's requests therefore read and wrote the *same* database-backed session
   row. Laravel flash data (`success`/`error`/`status`) and validation-error bags survive exactly one
   subsequent request; captured traces showed one worker's exact `"Task created."` flash appearing in
   another worker's Projects response, and an expected `"Project updated."` flash arriving `null`
   because another worker's request had already consumed the row. Demonstrated: the formerly
   deterministic Projects + Tasks failing pair passed 11/11 at `--workers=1` (no sharing possible) and
   failed 2/3 times at the default worker count, with the specific failing pair changing each run —
   consistent with contention over one shared row, not a fixed pairwise dependency.
2. **A "fresh" browser context that wasn't.** `projects-migration.spec.ts`'s membership test opened a
   new context for a manager it creates dynamically and called the real `signIn()` flow on it. The
   project's default `storageState` was authenticated, and a bare `browser.newContext()` inherits it,
   so `/login` redirected to `/dashboard`, the form never appeared, and the test failed alone and at
   `--workers=1` — an independent defect, never explained by the shared session.

A full suite at `--workers=1` (diagnostic only, never proposed as the fix) reached 62 passed / 1
failed, the one failure being cause 2. This gave defect 1 as demonstrated-and-fixable and defect 2 as
demonstrated-and-isolated, without yet testing the actual per-worker-session fix.

### A9.3 Phase 2: per-worker sessions, and a structural collision

The remediation replaced the shared per-run session with one minted per **worker**: a worker-scoped
`sessions` fixture (`support/auth.ts`) signs a persona in through the real login form the first time
that worker needs it and keeps the cookies in memory for the rest of its lifetime — memoized per
persona, so a worker mints a given persona's session at most once, however many spec files it goes on
to run. `browser.newContext()` calls that needed a second actor were replaced with an explicit
`contextFor(who, options)`, so a context that must start signed out (the manager test's fix) declares
`contextFor('anonymous')` rather than silently inheriting the default project state.

Session-isolation itself was proven directly: distinct session rows for distinct workers (confirmed
via the DB `sessions` table and a claim-file registry keyed by each minted session's CSRF token), and
three clean repetitions of the formerly-deterministic Projects + Tasks pair under two parallel workers
on a quiet machine, no foreign flash observed.

Validating under Playwright's *normal* (unpinned) worker count — 8, on this machine — surfaced a
second, structural problem: 47 `POST /login`, 29 refused with `HTTP 429`, 28 passed / 36 failed. With
enough spec files defaulting to the operator persona, and Playwright's default worker count tied to
machine core count, ordinary parallel execution alone could mint enough real operator logins inside
one Fortify window (5/minute per email+IP) to collide with the limiter — before any
authentication-subject test ran at all. This was reported rather than worked around with retries,
per instruction.

### A9.4 Final architecture

Two changes closed the structural gap, without weakening Fortify or serializing the suite:

1. **An explicit worker cap** (`workers: 3` in `playwright.config.ts`). Because a worker mints a
   persona's session at most once for its whole lifetime, the cap bounds that persona's *total* real
   logins for the entire run to at most the cap — deterministic, and independent of spec-file count
   or machine core count. Three leaves two logins of headroom under the 5/minute ceiling for both the
   operator and member buckets.
2. **Dedicated seeded fixtures for authentication-subject flows that don't need the canonical
   operator/member identity**, added to `database/seeders/DevSeeder.php` (dev/testing-only, same
   `firstOrCreate` pattern as the existing accounts):
   - `e2e-login-flow@intechral.test` — `auth-migration.spec.ts`'s invalid-credentials, valid-login,
     logout and password-confirmation tests. The test's subject is the login form, not a permission,
     so it no longer spends the operator persona's own per-worker login on top of this file's own
     login churn.
   - `e2e-profile-mutation@intechral.test` — `inertia-coexistence.spec.ts`'s account-mutation test,
     which edits its own name/email. A failure part-way now can never leave the *shared* operator
     account looking like a different person for every later run.
   - `e2e-signout@intechral.test` — `shell.spec.ts`'s sign-out test, seeded with the full-permission
     `operator` role. Its assertion (no administration item leaks into a fully-permissioned account's
     menu) holds for any fully-permissioned account, not specifically the shared one, and it must sign
     in for real regardless (sign-out destroys the database-backed session row it presents).

The dynamically created manager (`projects-migration.spec.ts`) needed no identity change — a unique
per-test email never shares a bucket with anything — only the `contextFor('anonymous')` fix so it
starts genuinely signed out.

Full login budget, per identity, and the reasoning behind each bound: `docs/testing/e2e-browser-suite.md`.

### A9.5 Validation

| Check | Result |
|---|---|
| `session-isolation.spec.ts` (new guard spec) | 3/3 passed |
| Projects + Tasks pair, 2 workers, 3 repetitions | 10/10 each run, no foreign flash |
| Manager fresh-context case, isolated | Passed; `GET /login` rendered the form (200), `POST /login` 302'd for the manager |
| Full suite, normal configuration (no `--workers` override) | **64/64 passed**, 3 workers, ~3.3 min |
| Login traffic, full suite | 12 `POST /login`, all 302; 0 `429`; 0 `419`; 0 application `5xx` |
| `./dev check` | Green (`BrowserAuthContractTest` rewritten for the new architecture; a milestone-dialog Vitest timeout reproduced once under full-machine load and passed cleanly in isolation and on retry — pre-existing, unrelated to this work) |
| `git diff --check` | Clean |

### A9.6 An unrelated dev-environment report

The owner separately reported `HTTP 400` accessing the normal dev environment in a browser. nginx's
own access log already held the evidence: real-browser requests (`GET /`, `GET /favicon.ico`, Chrome
on Windows) returning `400` with a 635-byte body immediately after an nginx restart, from a browser
that already held cookies. A synthetic request with a ~9 KB `Cookie` header reproduced the identical
signature (`400`, 635-byte body) against the same nginx, while a clean `curl` request with no cookies
succeeded normally. nginx's default `large_client_header_buffers` (unconfigured here, so nginx's
built-in default applies) rejects a request whose header line — including `Cookie` — exceeds its
limit. Browsers scope cookies to a host, not a host+port, so a `localhost`-scoped cookie jar
accumulates across *every* dev service the machine runs on `localhost`, of which this machine runs
several. This is an environment characteristic of the owner's machine and browser profile, not
anything this or any prior amendment changed; no repository file needed to change, and none did.

### A9.7 Standalone-task cleanup gap

Unchanged, as instructed. The known stale `E2E … standalone task` rows were confirmed in Phase 1 not
to intersect any query the failing specs used, and remain documented in `E2eCleanup`'s own docblock.

### A9.8 Handoff

WP5 is not started. Nothing in this amendment touches `resources/views/layouts/partials/`, any route,
policy, permission or dependency. The next Direction D work package inherits the browser suite as a
64/64 passing gate at its normal parallel configuration, which A8.18 explicitly said it could not yet
promise.

## Amendment 10: WP5 Results (2026-09-27)

**Status:** WP5 (Blade parity / renderer coexistence) implemented on the post-WP4-hardening tree
(`28cba36`). **This is the WP5 record; it does not revise Amendment 9's E2E-hardening scope.** No
route, permission, policy, controller, model, migration or dependency changed; no Blade page body was
edited; no WP4 React shell component or hook changed behaviour. **WP6 has not started:** the timer
strip is still the pre-Direction-D `timer-overlay` on Blade and `RunningTimerBar` on React.

### A10.1 Method

Inventory first (every Blade view using `layouts.app`, the nav partial, `ShellComposer`, the WP3
payload, the WP4 CSS hooks and storage keys), then the shell, then tests that compare rendered Blade
against the canonical payload, then real Chromium. Real Chromium caught one Blade defect that jsdom and
Pest could not (A10.12). Two pre-existing *test* defects were also found, one of which had made a WP4
seam guard unable to fail (A10.12).

### A10.2 Pre-WP5 Blade architecture, as found

| Part | State at `28cba36` |
|---|---|
| Blade root | `layouts/app.blade.php`: its own theme IIFE placed **after** `@vite`, `partials/nav.blade.php`, `partials/timer-overlay.blade.php`, `<main id="main-content">`, `partials/footer.blade.php`. 34 views extend it, including `errors/403` (reachable by a guest) |
| Inertia root | `app.blade.php`: a second, separately written theme IIFE before `@vite` |
| Navigation input | `ShellComposer` bound to `layouts.partials.nav`, supplying canonical `navigation` **and** the temporary flattened `navigationLegacy` (`LegacyShellNavigation`) — the nav partial rendered only the latter |
| Chrome | `max-w-7xl` sticky bar: brand text link, flat `primary` links, a standalone theme toggle, a hand-rolled user dropdown carrying the flattened `overflow` destinations, a hamburger + mobile panel, and a ~90-line inline IIFE. `nav aria-label="Primary navigation"` wrapped the whole bar (the A1.9 divergence). No skip link, no `aria-current`, no `header` landmark, a global footer |
| Theme | `localStorage['theme']` read by two different root scripts; a malformed stored value was applied verbatim to `data-theme` |
| Panel state | None on Blade: `html[data-drawer]` was only ever stamped by React |

`navigationLegacy`'s **only** consumer was `partials/nav.blade.php` (A8.11). Nothing else — no route,
controller, test double or type — read it apart from tests that existed to pin the projection itself.

### A10.3 Canonical navigation on Blade

Blade now renders `navigation` directly — the WP3 model, from the one builder, through `ShellComposer`
— and nothing else. Workspaces render in server order with server labels, `href`s, icons and
`isActive`; the current workspace is taken from the payload **by `currentWorkspace` key**, never
matched from the URL; its `context` sections render in order with `kind: 'actions'` as actions that can
never carry `aria-current`; `count` renders nothing. All nine workspace identities go through the same
loop, `resources` included. There is no `primary`/`overflow`, no "Manage", no second workspace
catalogue, no permission check, no URL-pattern matching and no pruning in any template.

**Visit mode.** From a Blade document there is no client router, so both `inertia` and `document`
destinations are plain `<a href>` full-page loads: a `document` destination lands on Blade, an `inertia`
destination boots the React app on arrival. Blade therefore needs no visit-mode branch at all, and
infers nothing from URL shape.

**Authorization** is exactly the server's: what the builder withheld is absent from the rendered HTML.
`BladeShellTest` asserts the rendered rail, panel and sheet equal the payload for the same actor and
route across nine actor/route cases, that a `user`-role actor's entire shell contains no `/admin/`,
`/operator/`, `/crm/` or `/organizations` href, and Chromium confirms `admin/users` still answers 403
inside the Blade shell.

**`ShellComposer` binding — a refinement of §14.4.** It is bound once, to `layouts.app`, not to the
individual shell partials. The root `<html>` needs `currentWorkspace` and the panel default for the
pre-paint bootstrap (§15.3 step 1), which a partial-level binding cannot supply, and one binding on the
layout keeps the builder at **one invocation per Blade request** (asserted) while every
`layouts.partials.shell.*` include inherits the data. A static `ShellComposer::rootState()` derives the
two bootstrap inputs (`workspace`, `drawerDefault` — null when `context` is empty, §12.3 rule 6) and is
reused by the Inertia root from the `navigation` prop that response already resolved, so the builder is
not invoked twice there either.

### A10.4 Blade shell structure

`layouts/app.blade.php` renders `div[data-shell="operational"][data-shell-renderer="blade"]` with the
same `data-shell-*` hooks React renders, so the **one unlayered WP4 geometry block lays out both
renderers**: skip link, `rail`, `drawer`, and a canvas holding the `utility-bar`, the unchanged timer
strip and `<main id="main-content" tabindex="-1">`. The footer is gone.

| Partial (`resources/views/layouts/partials/shell/`) | Twin of | Notes |
|---|---|---|
| `bootstrap.blade.php` | — | Inlines `resources/js/shell/bootstrap.js` (A10.6) |
| `rail.blade.php` | `rail.tsx` | 64px; brand, panel toggle, `nav "Workspaces"` (workspace links only), sheet trigger, account control |
| `drawer.blade.php` | `drawer.tsx` | 248px; projects `context`; the only accent strip; labels are not headings (A8.10) |
| `utility-bar.blade.php` | `utility-bar.tsx` + `breadcrumb.tsx` | 48px `header`; breadcrumb from `currentWorkspace` + the active view, ending with the current page |
| `account-menu.blade.php` | `account-menu.tsx` | 40×40 rounded-square trigger, 28px circular avatar from server initials |
| `nav-sheet.blade.php` | `nav-sheet.tsx` | Native modal `<dialog>`; workspaces + current views; rows ≥ 44px |
| `brand-mark.blade.php` | `brand-mark.tsx` | `App\Support\BrandMark`, a line-for-line port of `scopeBrandSvg()` |
| `icon.blade.php` | `workspace-icon.tsx` | Explicit icon-key → inline SVG map using lucide's own path data (same glyphs), neutral `handshake` fallback |

Styling is the Direction D token utilities React uses (`bg-surface-selected`, `ring-rule`,
`rounded-control`, the WP2 `focusRing` string, …); no inline style block and no parallel Blade theme.
Screenshots at XL in both themes show the Blade Helpdesk shell and the React Projects shell with the
same rail, panel and utility-bar chrome.

### A10.5 The simplified panel and the width classes

§14.2 allows Blade a panel that is **docked or hidden** — no overlay, no pin, no focus trap — with its
content folding into the nav sheet at M/S. Implemented as a small Blade-scoped CSS block
(`[data-shell-renderer='blade']`, unlayered like the WP4 block) on top of the shared rules:

| Class | Blade behaviour (measured in Chromium) |
|---|---|
| **XL** ≥ 1360 | As React: docked at 248px when `html[data-drawer]` is open, canvas at 312px; collapsed shows the rail toggle |
| **L** 1024–1359 | Starts collapsed unless pinned (the shared bootstrap rule). Opening it **docks** it (canvas → 312px) instead of floating; `Esc` and content clicks leave it alone (A8.12 #3); collapse returns focus to the toggle |
| **M** 768–1023 | Panel and toggle not rendered; the sheet trigger appears and the sheet holds the current views |
| **S** < 768 | As React: 56px top bar, rail list and panel `display: none`, the sheet is the single set of workspace links |

The toggle and collapse buttons write `localStorage['shell.operational.panel']` with the same key, shape
and write rule as `use-panel-state.ts`, so a choice on either renderer is honoured by the other.
`Ctrl+\` toggles whichever control CSS is currently showing, inert in text fields. There is **no Blade
pin affordance**; a pin set in React for a workspace is still honoured by the shared bootstrap.

### A10.6 Shared pre-paint bootstrap

`resources/js/shell/bootstrap.js` is the **single** pre-paint script for both roots (§14.4). It is a
classic, dependency-free script (a module script is deferred and could not run before paint), inlined by
`partials/shell/bootstrap.blade.php` directly after `<meta charset>`/`viewport` and **before any
stylesheet or `@vite`** in both roots (A1.7 requirement 2), replacing the two root-specific theme
scripts.

- **Owns** `html[data-theme]` and `html[data-drawer]` and nothing else. It reads the server-stamped
  `html[data-workspace]` / `html[data-drawer-default]` and `localStorage` only; it carries no server
  data, no authentication state, and never writes storage.
- **Keys:** `theme`, `shell.operational.panel`, `shell.operational.pin` — the existing WP4 keys,
  unchanged. No cookie, no server persistence.
- **Panel rules:** exactly `resolvePanel()`'s — no default → collapsed; below XL and not pinned →
  collapsed; else remembered, else default. `bootstrap.test.ts` executes the file's own text against
  the hook's real `resolvePanel` over a **144-case parity matrix** (workspace × default × stored value
  × pin × XL/below/`matchMedia` throwing); a deliberate one-line divergence fails 12 of them.
- **Failure modes:** blocked storage, malformed JSON, arrays/strings/`null`, unknown workspaces and
  out-of-set values all fall through to the server default; a `theme` value outside `light`/`dark` is no
  longer applied verbatim (it falls back to `prefers-color-scheme`); the whole body is wrapped so it can
  never break the page.
- **Measured:** a cold Blade load with `dark` + a collapsed Helpdesk panel remembered reads
  `dark/collapsed` on the **first animation frame** with a **cumulative layout shift of 0**.

React keeps owning the attributes after mount exactly as in WP4; on an Inertia root the bootstrap's
value and React's first layout effect agree by the parity test, and nothing paints in between
(`ssr: false`).

### A10.7 Appearance

One mechanism: `localStorage['theme']` + `html[data-theme]`, written by the React Appearance radio
group and by the Blade account menu's Appearance control, read by the one bootstrap. The standalone
Blade theme toggle is deleted. Chromium proves the theme survives React→Blade, Blade→React and browser
back/forward, and that a Blade Appearance change is what the React menu then shows as checked.

### A10.8 Account and avatar

Same destinations as React (§17.1–17.2): Profile, Security & MFA, Connected accounts, Sessions (2–4
anchoring into `/profile`), Appearance (Light/Dark), a disabled Notifications row with its reason, Sign
out (`POST /logout` with CSRF). The partial receives no navigation, so an administrative entry has no
path in; asserted in Pest and in Chromium for an actor holding every permission. The trigger is a
`button` with `aria-haspopup="menu"`, `aria-expanded` and the name "Account menu: {name}". Initials are
`$shellUser['avatar']['initials']` (`App\Support\Initials`); Blade derives none. The Blade menu is a real
`role="menu"`: items rove with arrows/Home/End, `Esc` closes and returns focus, `Tab` or an outside
pointer closes it; Appearance uses `menuitemradio` inside a labelled group (the correct in-menu role).

### A10.9 Accessibility

Skip link first (Pest: first focusable element; Chromium: first Tab stop, visible, lands focus in
`main`). Landmarks: `nav "Workspaces"` (workspace links only — the A1.9 divergence is fixed: no brand,
toggle or account control inside it), `nav "{Workspace} views"`, `header`, `nav "Breadcrumb"`, `main`.
`aria-current="page"` on the server-active rail item and view only. Rail and panel links are plain
`<a href>` with no `tabindex` (L11, asserted in Chromium); the only composites are the account menu and
the native `<dialog>` sheet. Visible focus measured at a 2px outline. No shell heading, so the page's
`h1` stays the only one. Selected state carries weight, a ring and the strip as well as colour. Motion
uses the Direction D motion tokens, which already honour `prefers-reduced-motion`. Sheet targets are
≥ 44px. Not done here: an NVDA pass (WP8, §22.3).

### A10.10 Renderer coexistence

Asserted in Chromium (`blade-shell.spec.ts`), both directions:

- **React → Blade:** on `/projects` choose Dark and collapse Projects; the rail's Helpdesk (`document`)
  link unloads React (`#app` gone), the Blade shell renders with Helpdesk current, Dark, and Helpdesk's
  own open panel.
- **Blade → React:** collapse Helpdesk in Blade; the Blade rail's Projects (`inertia`) link mounts React
  with Projects current, its panel still collapsed, still Dark; storage holds both choices.
- **History:** Back returns to the Blade page with Helpdesk current and collapsed; Forward remounts React
  on Projects.
- **S sheet:** an `inertia` destination chosen in the Blade sheet boots React.

The existing crossings in `shell.spec.ts`, `inertia-coexistence.spec.ts` and `time-migration.spec.ts`
(including the embedded Blade timer tracker) pass against the new shell.

### A10.11 Legacy cleanup

| Removed | Why it was safe |
|---|---|
| `app/Shared/Navigation/LegacyShellNavigation.php` | Its one consumer, `partials/nav.blade.php`, is gone; nothing else in `app/`, `resources/` or tests reads it (asserted) |
| `navigationLegacy` composer key | Same; the Inertia payload dropped it in WP4 |
| `layouts/partials/nav.blade.php` (bar, theme toggle, user dropdown, mobile menu, inline IIFE) | Replaced by the shell partials |
| `layouts/partials/footer.blade.php` | §14.4: removed from the authenticated shell; nothing else included it |
| Both root-specific theme IIFEs | Replaced by the shared bootstrap |
| Four compatibility-projection cases in `NavigationBuilderTest` | They tested the deleted class. The one with lasting value — every pre-WP3 destination still reachable for both roles — is kept, rewritten against the canonical model |

**Intentionally retained:** `partials/timer-overlay.blade.php` + `timer-overlay.js` (relocated into the
canvas, markup unchanged — the pill/tray rework is WP6, §18.4); `missing('navigationLegacy')` assertions
in `ShellContractTest`/`LoginTest` (they guard the removal, not the seam); `welcome.blade.php`
(standalone, not the shell).

### A10.12 Defects found

| # | Found by | Defect | Fix |
|---|---|---|---|
| 1 | Chromium | **The docked panel intercepted clicks on the Blade account menu.** The menu lives inside the rail (Blade has no portal), and a sticky rail is a stacking context, so the menu's own `z-index` could not lift it above the panel or utility bar later in the DOM | While the menu is open, the Blade rail itself is lifted (`:has([data-shell-account][aria-expanded='true'])`) |
| 2 | Pest, while writing WP5 guards | **WP4's `keeps pages chrome-agnostic` guard could never fail.** `->not->toContain($forbidden, "{$file} must not …")` passes a second *needle*, not a message; Pest's `toContain` is variadic, so the negation passed whenever either needle was absent — always. Demonstrated live: `expect('import Rail …')->not->toContain('Rail', 'msg')` passes | Rewritten to a real reference check (imports from the module, or JSX use), with positive and negative samples proving it can fail. The corrected guard immediately flagged `pages/projects/tasks/show.tsx`, whose own `<nav aria-label="Breadcrumb">` predates this epic — page content, not a shell import, so the page is correct and the check was made precise rather than the page changed |
| 3 | Same review | `NavigationBuilderTest`'s account-menu boundary case used `->not->toContain('management', 'primary', 'manage')`, which would pass if any one key were absent | Split into one needle per assertion |
| 4 | Chromium | My own L11 check matched the account menu's `menuitem` links (whose roving `tabindex="-1"` is correct for a composite) | Scoped to `nav a[tabindex]` |

### A10.13 Tests

**Pest.** `tests/Feature/BladeShellTest.php` — **new, 24 cases**, asserting on the parsed DOM of real
Blade responses against the builder's payload for the same actor and route: rail, sheet, panel and
sheet-views equal the canonical projection (9 actor/route cases); `aria-current` is exactly the payload's
active set, including the A1.10 collision routes and a nested route, and the breadcrumb ends on the
current page (5); pre-paint inputs stamped from the payload, and no panel/toggle for a panel-less
workspace; no withheld href anywhere in a member's shell; Resources for the member, not the operator;
landmarks with the skip link first and no shell heading; server initials and the exact personal menu;
retired chrome absent; the brand mark; a guest render; one bootstrap inlined before any stylesheet on
both roots; no workspace stamped on a guest Inertia page. A mutation that injects one rail link and
drops `aria-current` fails 15 of the 24. `tests/Unit/Support/BrandMarkTest.php` — **new, 8 cases**
mirroring `brand-mark.test.tsx`, plus "path data is never altered". `ShellContractTest` — composer
parity rebound to `layouts.app`, root-state derivation, one builder resolution per Blade request, and "no
Blade view names the builder". `ShellSeamContractTest` — the guard fix above, plus "the pre-WP5 Blade
shell and its projection are gone" and "both roots include the one bootstrap before `@vite`".

**Vitest.** `resources/js/shell/bootstrap.test.ts` — **new, 156 cases** (the 144-case parity matrix,
hostile storage, no writes, theme). `resources/js/shell/blade-shell.test.ts` — **new, 11 cases**: toggle
and collapse with persistence and focus, a Blade write read back by React's `resolvePanel`, unreadable
map replaced, refused write tolerated, menu open/Escape/arrows/Home/End/wrap/outside-pointer, Appearance.

**Playwright.** `tests/Browser/blade-shell.spec.ts` — **new, 10 flows**: geometry and server state in
both themes; React→Blade→React with history; first-frame theme/panel with CLS 0; L dock-only; M sheet;
S top bar, sheet, 44px targets, the account menu on top, an `inertia` destination from the sheet; 200%
reflow; the personal, keyboard-operable account menu owning Appearance across the crossing; skip link,
plain-Tab rail and panel, 2px focus; member authorization, Resources, and a 403 inside the shell. It
uses only the existing `operator`/`member` persona sessions — **no new identity and no new login**.
`shell.spec.ts` — the two flows that asserted "no shell on Blade" now assert the Blade renderer of the
shell; the sign-out flow checks the React menu, then signs out from the Blade menu (React sign-out
remains covered by `auth-migration.spec.ts`), on its existing single `e2e-signout` login.

### A10.14 Deviations from the written WP5 plan

| # | Plan | What was done | Why |
|---|---|---|---|
| 1 | §14.4: composer "binds … to the shell partials" | Bound once to `layouts.app` | The root `<html>` needs the bootstrap inputs; one binding keeps one build per request (A10.3) |
| 2 | §14.1: "same 48px utility bar with breadcrumb and timer pill" | Breadcrumb only; the timer strip is unchanged below the bar | The pill/tray is WP6 (§18.4), exactly as WP4 left React (A8.14 #5) |
| 3 | §14.2: docked or hidden; M/S fold into the sheet | Also: at **L** an opened Blade panel docks rather than floats | Blade has no overlay; the shared bootstrap already starts L collapsed unless pinned, so an open panel at L is one the user asked for |
| 4 | §14.1: menu semantics "`role="menu"` + Escape + click-outside" | Also roving arrows/Home/End, Tab-close, focus return | A `role="menu"` without them misstates the widget; small and native-first |
| 5 | §15.3: bootstrap reads the map "with `data-drawer-default` as the fallback" | Also honours `shell.operational.pin` and the below-XL rule | A8.19: required or it reintroduces A8.12 #4 |
| 6 | Not in the plan | Bootstrap validates `theme` to `light`/`dark` | A malformed stored value used to be applied verbatim to the root attribute |
| 7 | Not in the plan | WP4 seam-guard test corrected | It was vacuous (A10.12 #2); test-only, no product change |

### A10.15 Validation results

| Run | Result |
|---|---|
| Pest, focused (Blade shell, shell contract, navigation, configuration, support, login, Inertia foundation) | **206 passed / 2,286 assertions** |
| Vitest, shell + panel hook | **183 / 183** |
| Playwright, Blade-crossing specs (`blade-shell`, `shell`, `inertia-coexistence`, `time-migration`) | **31 / 31** |
| Playwright, full suite, normal configuration (no `--workers` flag) | **74 / 74 passed**, 0 failed, 0 skipped, **3 workers, 2.0 min** |
| nginx, full-suite window | **12 `POST /login`, all 302; 0 × 429; 0 × 419; 0 × 5xx.** Also: 2 × 403 (the two intentional `admin/users` authorization checks), 2 × 404 (`GET /projects/1493/board` and `DELETE /projects/1493` on an already-deleted project in the projects specs), 40 × 499 (requests abandoned by navigation, 35 of them `/time/timers/active` polls) |
| Product data | `tasks` 40 → 41: one `E2E WP8 standalone task` row from `tasks-migration.spec.ts` — the known standalone-task cleanup gap (A8.18 #5, A9.7), unchanged |
| `./dev check` | **All checks passed, exit 0**: CLI self-tests, `git diff --check`, Pint, `npm run check` (Wayfinder, `tsc`, ESLint, Prettier, **Vitest 672 / 672 in 69 files**, production build), **Pest 1,113 passed / 5,874 assertions** (269.6s). The first `./dev check` run on this tree failed only at Vitest, with 5,000ms `userEvent` timeouts in `milestone-form-dialog.test.tsx` and `form-dialog.test.tsx`: files WP5 did not change, which passed 12 / 12 in isolation and then passed in the full re-run. That is the load-sensitive pattern already recorded in A8.15 and A9.5; no timeout was raised and no assertion was weakened |
| `git diff --check` | Clean |

### A10.16 Known issues, not addressed here

- **Dark-mode legibility of Blade page content**, e.g. the overdue-row highlight on the operator ticket
  queue renders light text on a light tint in dark mode. That is page-body styling, unchanged by WP5
  (§14.3), and belongs with the Blade contrast debts A6.24 records.
- **Two `nav "Breadcrumb"` landmarks on the React task-detail page** (`pages/projects/tasks/show.tsx`'s
  own breadcrumb plus the shell's). Pre-existing React page content, surfaced by the corrected seam
  guard; not Blade and not WP5's to change.
- **`account-menu.tsx`'s avatar** computes initials client-side with `initialsOf(user.name)` rather than
  reading `auth.user.avatar.initials`, although A8.8 describes the latter. The two rules are identical
  and asserted equal (A7.12), so the renderers cannot disagree; recorded rather than changed, because
  WP5 does not modify WP4's React components.
- **The timer strip can draw over the sticky utility bar while a timer runs**, on Blade pages only. The
  pre-WP5 nav was `z-40`, above the strip's `z-30`; the utility bar is also `z-30` and sits later in the
  DOM, so ties now resolve to the strip. Cosmetic, Blade-only, live only while a timer runs, and moot
  once WP6 reworks the strip into the pill/tray (§18.4).
  **RESOLVED by WP6 (A11.11).** Not patched: the strip was deleted, and the timer now lives *inside*
  the utility bar, so there is no second stacking participant left to resolve a tie against.
- **No `pageshow`/`persisted` resync exists yet.** Neither the bootstrap nor `blade-shell.ts` listens for
  a bfcache restore, so a Blade page restored from the browser's back/forward cache could in principle
  repaint with a stale theme or an account menu left open from before the restore. Identified statically
  from the code, not reproduced in a browser; suggested as WP8 lifecycle hardening.
- **Two other Pest guards outside this diff share the `not->toContain($needle, $message)` defect this
  amendment fixed in `ShellSeamContractTest`** (A10.12 #2): `BrowserAuthContractTest.php` and
  `ProjectIntegrityTest.php`. Both accept a second argument as a second needle rather than a message, so
  the negation cannot fail. Unrelated to the shell seam; separate test-hygiene follow-up, not WP5's to
  fix here.
- **The Blade icon map ([§12.3 rule 2](#123-contract-rules)) has no contract test tying its keys to
  `NavigationBuilder`'s.** All nine current workspace icon keys match by inspection, but nothing asserts
  that a future workspace's key exists in `shell/icon.blade.php` before it silently falls back to the
  neutral glyph.

**A deployment note, not a defect.** Both root views read `resources/js/shell/bootstrap.js` from disk
on every render and inline its body (§14.4, `bootstrap.blade.php`). This is correct for the current
bind-mounted `src` deployment. A future production image or build strategy that strips `resources/js`
from the runtime container would need to preserve this file, or replace the read with a build-time
inline step, or every page render breaks.

### A10.17 Files changed

**New:** `src/app/Support/BrandMark.php` · `src/resources/js/shell/{bootstrap.js,blade-shell.ts,bootstrap.test.ts,blade-shell.test.ts}` · `src/resources/views/layouts/partials/shell/{bootstrap,rail,drawer,utility-bar,account-menu,nav-sheet,brand-mark,icon}.blade.php` · `src/tests/Feature/BladeShellTest.php` · `src/tests/Unit/Support/BrandMarkTest.php` · `src/tests/Browser/blade-shell.spec.ts`

**Modified:** `src/app/View/Composers/ShellComposer.php` · `src/app/Providers/AppServiceProvider.php` · `src/resources/views/layouts/app.blade.php` · `src/resources/views/app.blade.php` · `src/resources/css/app.css` (Blade-scoped block only) · `src/resources/js/app.js` · `src/resources/js/types/shared.ts` (comment) · tests `ShellContractTest.php`, `NavigationBuilderTest.php`, `ShellSeamContractTest.php`, `shell.spec.ts` · `docs/testing/e2e-browser-suite.md` · this document

**Deleted:** `src/app/Shared/Navigation/LegacyShellNavigation.php` · `src/resources/views/layouts/partials/nav.blade.php` · `src/resources/views/layouts/partials/footer.blade.php`

### A10.18 WP6 handoff

WP6 (global timer shell integration) may start. What it inherits on Blade: the utility bar is
`partials/shell/utility-bar.blade.php` (a `header[data-shell-utility]` with the breadcrumb in a
`min-w-0 flex-1` slot and room on the right for the pill); `partials/timer-overlay.blade.php` still
renders directly below it inside `[data-shell-canvas]`, with its `#timer-overlay`/`#timer-tiles` hooks,
`timerStarted`/`timerStopped` events and decorative `PALETTE` untouched; `app.js` initialises
`initBladeShell` and the timer overlay independently. An overlay-level widget inside the rail or bar
must account for A10.12 #1: the sticky rail and bar are stacking contexts on Blade, where nothing is
portaled.

---

## Amendment 11: WP6 Results (2026-09-28)

**Status:** WP6 (global timer shell integration) implemented on the WP5 tree (`e10f2ab`). **This is the
WP6 record; it does not revise Amendments 1–10.** No route, endpoint, migration, permission, policy,
controller, model or service changed — §18.2 is explicit that the five existing timer endpoints already
support every Foundation capability, and they were left exactly as they were. One dependency was added,
with approval, because the committed plan requires it (A11.2). WP7 has not started.

### A11.1 Method

Audit before edit. The timer was traced end to end first — routes, controller, `TimeEntryService`,
the `TimeEntry` model's billing lock, `TimerProvider`, `RunningTimerBar`, `timer-overlay.js`,
`timer-overlay.blade.php`, `components/time-tracker.blade.php`, the WP4 React shell and the WP5 Blade
shell — and only then replaced. Real Chromium then caught four defects that jsdom, Pest and review had
all missed (A11.12), one of which was a genuine ordering bug in shared logic rather than a styling slip.

### A11.2 The one dependency, and why it was not avoidable

`@radix-ui/react-popover@^1.1.23`, one package. §4.7, §12.2, §18.3 and §26 all specify the tray as a
Radix Popover, and the two already-installed alternatives are both wrong for this surface: Radix
`DropdownMenu` is a composite menu whose roving focus and typeahead would swallow the keystrokes meant
for the tray's inline description fields, and `dialog-shell` is modal and unanchored, which contradicts
"non-modal popover". The cost is near-zero: every transitive dependency was already present through the
existing `react-dropdown-menu` → `react-menu` chain, so `npm install` reported **"added 1 package"** and
the lockfile gained exactly one entry. (`npm audit`'s four pre-existing high findings — `nanoid`,
`postcss`, `shell-quote`, all build tooling — are unchanged by this and are not WP6's to fix.)

### A11.3 Pre-WP6 timer architecture, as found

**Server, authoritative and unchanged.** Five endpoints under `can:time.log`: `GET /time/timers/active`
(the active set plus `server_now`), `POST /time/timer/start`, `POST /time/timer/{entry}/stop`,
`PATCH /time/timer/{entry}/description`, `GET /time/context-options`. Stop and description edits assert
`$entry->user_id === auth()->id()` and run inside a transaction with `lockForUpdate`; `ensureMutable()`
raises the billing lock; stop is idempotent (a null `timer_started_at` returns early) and rounds any
partial minute up. Multiple concurrent timers are supported; there is no pause and no stop-all.

**React.** `TimerProvider` (the hardened EPIC-011D model) held the only client-side copy of the active
set, with its two sequence refs, its re-read on every failure path and its `401/419` reload.
`RunningTimerBar` drew a full-width strip under the header: one row per timer, its own `setInterval`,
its own `formatElapsed`, and an `aria-live="polite"` region wrapped around a clock that changed every
second.

**Blade.** `timer-overlay.js` (273 lines) fetched the same endpoint, rendered coloured tiles into
`partials/timer-overlay.blade.php` below the header, ticked its own interval, and dispatched and
listened for `timerStarted`/`timerStopped`. It carried an eight-entry decorative `PALETTE` including
four hard-coded hexes (`#8b5cf6`, `#ec4899`, `#14b8a6`, `#f97316`).

**What the two renderers disagreed about.** Each carried its own `formatElapsed`, and only the React
one applied the clock offset at all — so the same running timer could read differently on Blade than on
React. That is the duplication WP6 removes, and it is the reason the shared module below exists.

**The one legitimate non-shell consumer.** `resources/views/components/time-tracker.blade.php`, the
embedded tracker on ticket pages, depends on the `timerStarted`/`timerStopped` window events. It was
not touched, and the event contract is preserved verbatim (§18.4).

### A11.4 Canonical timer state

Laravel remains the sole authority on what a timer *is*. WP6 adds no server-side presentation contract,
because none is needed and §18.2 forbids the backend work.

What WP6 does add is one small, framework-free, DOM-free module — `resources/js/lib/timer-state.ts` —
holding the handful of *derivations* both renderers need in order to draw the server's answer: the
clock offset, elapsed seconds, the `H:MM:SS` and `H:MM` forms, which timer the pill shows, row order,
the pill label and its truncation, the tray heading, and the pill's accessible name. React
(`timer-pill.tsx`, `timer-tray.tsx`) and Blade (`shell/blade-timer.ts`) both import it. The renderers
draw different markup — §26's accepted cost of coexistence — but they can no longer disagree about
which timer is newest, how long it has run, or how its label reads.

`TimerProvider`'s reconciliation is **unchanged**: same reducer, same `refreshSeq`/`mutationSeq`, same
re-read on failure, same reload on `401/419`. Its one edit is a deletion — the private `offsetFrom`
helper moved into the shared module as `clockOffsetFrom`, behaviour identical, so the Blade renderer
estimates the server clock by the same rule instead of by a second copy of it.

### A11.5 The timer pill

Utility-bar right, on both renderers, gated on `time.log` exactly as the endpoints are.

| State | What it draws |
|---|---|
| None | Ghost clock glyph + "Start timer"; opens the tray to its empty state |
| One | Live dot + mono `H:MM:SS` + context label (≤28 chars) + a Stop naming that context |
| Several | The **most recently started** timer, plus `+N` in `live-soft`/`live-text` and a chevron |
| Pending | Hollow live ring + "Starting…"/"Stopping…", no elapsed value, Stop withdrawn |
| Failed stop | Last confirmed state kept, `danger` border, "Couldn't stop", Stop becomes "Retry stop" |
| Narrow (S) | Dot + `H:MM` + `+N`; seconds and label are CSS-hidden |

Measured at 295×32 at XL/L/M and 135×44 at S — inside Direction D's ~360px ceiling at every width.

**The tick lives inside the pill**, which is the EPIC-011D lesson and §18.3's hard requirement. A
Vitest guard mounts a sentinel beside the pill and asserts it does not re-render when fake timers
advance five seconds. The interval is not created at all when nothing is running and the tray is shut,
so an idle shell runs no timer. Elapsed time is *derived* every tick from `started_at` plus the clock
offset rather than incremented, so it cannot accumulate drift, and a remount reads what a tab open for
an hour reads. The React tick is a self-scheduling `setTimeout` re-aligned to the second boundary each
time — so the digits change when the clock's second changes, and a throttled background tab resumes on
the boundary — and it reads the clock in the callback, never during render, so there is nothing for a
future SSR pass to mismatch on.

**Accessibility.** The accessible name deliberately excludes the elapsed value ("Running timer: Ticket:
TKT-42. Show timers"): the name is re-read on change, so a clock in it would announce every second. The
digits are visible text outside any live region, asserted in both Vitest and Chromium. A separate
`sr-only` `aria-live="polite"` region announces only "Timer started."/"Timer stopped.", driven by the
*count*, and stays silent on first render so hydrating a page with a timer already running announces
nothing. Running state is never carried by colour or motion alone — there is a ticking numeric clock
and a textual name. The dot's pulse is a new `--animate-live-pulse` token (2.4s, shallow) that resolves
to `none` under `prefers-reduced-motion`, verified in Chromium (`dotAnim=none`, `opacity=1`: the dot
stays fully visible, only the animation stops).

### A11.6 The timer tray

A non-modal Radix Popover, measured at 408px (inside the 400–420 band), level-2 elevation, anchored to
the pill. Header "N running"; rows newest-first with live dot, context link, inline description field,
elapsed and a context-named Stop; footer "Open Time →". Zero state names the absence and points at the
existing start flow. A failed hydration surfaces in the tray with a Retry, so a broken read is visible
rather than silently indistinguishable from "nothing is running".

Open moves focus to **the tray itself**, not to the first row's description field — Chromium showed the
caret landing in a text box for someone who opened the tray to press Stop (A11.12 #3). `Esc` closes and
returns focus to the pill; an outside pointer press closes it without taking focus back. Tab walks the
row controls in order. The pill's Stop is a **sibling** of the trigger, never nested inside it.

**Escape has two meanings, resolved the same way on both renderers.** In a description field holding an
unsaved draft it reverts the draft and keeps the tray open; with nothing to lose it closes the tray, as
Escape does everywhere else. On React this is a `onEscapeKeyDown` veto keyed to a `data-draft`
attribute, because Radix listens on the document and a synthetic `stopPropagation` never reaches it; on
Blade it is a conditional `stopPropagation`. Both were defects first (A11.12 #2, #4).

**Foundation only.** No Stop all, no "Today N logged", no "Start another…" search, no Switch. Asserted
by absence in Vitest and Playwright.

### A11.7 `TimerControl`

The contextual control (§12.3) for surfaces that already start and stop timers — today the task detail
page's time panel, which now draws its running state and Stop from the shared control and the Direction
D `live` tokens instead of its own `--text-success` markup. It holds no timer state; `TimerProvider`
remains the only client-side holder, so a timer started here appears in the pill without being told.
Starting from a context never stops another timer.

**Deviation:** the **unavailable** state (§12.3: disabled, with the reason given) is not implemented.

The reason is scope and reachability, not dependency cost. WP6's authoritative in/out list
([§18.1](#181-in-and-out), taken from Direction D §12.0) enumerates "contextual start/stop on surfaces
that already have it; pending and error states" and does not include an unavailable state; neither
WP6's **Exit**, nor [§29](#29-exit-criteria) criterion 9, nor the [§25.2](#252-vitest--rtl) matrix
requires it. Nor can any current surface reach it: the callers gate the whole control on `time.log`,
and the billing lock is a server rejection surfaced as an error after the click, not a precondition
known before it. Building a state nothing can trigger, against no acceptance criterion, would be
building blind.

To be accurate about the cost, since the first draft of this amendment overstated it: the state does
**not** necessarily require `@radix-ui/react-tooltip`. [A6.24](#a624-wp3-handoff) names
`Tooltip`'s first consumer as the rail's unavailable items, so WP6 was never its owner, and a disabled
control can carry its reason without Radix at all. The dependency was the wrong argument for the right
conclusion. Registered in [§31](#31-deferred-follow-on-work).

### A11.8 React integration

`operator-shell.tsx` stops rendering `RunningTimerBar` below the utility bar and instead passes
`<TimerPill />` into `UtilityBar`'s existing `children` slot, gated on `auth.permissions.includes('time.log')`
— the same seam WP4 already provided, so no shell component was restructured. `utility-bar.tsx` changed
only its docblock. No other WP4 component or hook was touched.

### A11.9 Blade integration

`partials/shell/timer.blade.php` renders the pill and tray inside `partials/shell/utility-bar.blade.php`,
gated on `@can('time.log')`. `resources/js/shell/blade-timer.ts` drives it, mounted from `app.js`
alongside `initBladeShell`, following the WP5 Blade-runtime conventions. It owns presentation only:
every derivation comes from the shared module, every mutation goes through the existing endpoints with
the CSRF token, `401/419` reloads, and a rejected request is resolved by re-reading the server rather
than guessing locally. Tray rows are reused across refreshes rather than rebuilt, so a half-typed
description is not destroyed by the next tick.

The pill renders its **idle** state server-side and the script swaps in the running state once the
active set arrives. Server-rendering the running state would mean a timer query on every Blade page
render, which §18.2 rules out; nothing shifts when it swaps, because the pill is last in the bar.

Two Blade-specific mechanics worth recording. `[hidden]` needed an unlayered `display: none` rule scoped
to the pill, because several of its parts also carry a Tailwind display utility that would otherwise win
and show a part the script had just hidden — the same layered-vs-unlayered trap A8.12 #2 records. And
the icon map gained `chevron-down` and a **filled** `square-filled`, the outline square having read as
an unchecked checkbox beside its "Stop" label (A11.12 #3).

### A11.10 Renderer crossings

Asserted on **state**, not appearance: both renderers expose the same `data-timer-count`,
`data-timer-running` and accessible names, so the same selector must find the same timer on either side
of a document navigation.

| Crossing | Result |
|---|---|
| React → Blade, running | Same timer, same label, count preserved; elapsed non-zero and still ticking; one pill; no `#timer-overlay` |
| Blade → React, running | Same timer and label; elapsed does not reset semantically; React shell mounts normally |
| Back / Forward while running | Count preserved both ways — the timer is server state, so history cannot invent or lose it |
| Stop, then cross | Stays stopped; the pill returns to its idle "Start timer" name |
| Start on Blade (embedded tracker), cross to React | Reconstructed from the server; the preserved `timerStarted` event lets the Blade pill adopt it without a reload |
| Description edited in the tray, then cross | The edit is server state and is shown by the other renderer's tray |

### A11.11 Legacy retirement

**Deleted:** `resources/js/components/time/running-timer-bar.tsx` and its test ·
`resources/js/timer-overlay.js` · `resources/views/layouts/partials/timer-overlay.blade.php`.

**Deleted with them:** the decorative `PALETTE` and its four hard-coded hexes, the two duplicate
`formatElapsed` implementations, the strip's `aria-live` wrapper around a per-second clock, and the
`#timer-overlay` / `#timer-tiles` / `.timer-tile` hooks.

**Proof no live consumer remains.** Every consumer was searched before deletion: `operator-shell.tsx`
and `app-shell.test.tsx` (React), `app.js` and `layouts/app.blade.php` (Blade), and five browser specs.
All were migrated. A Pest guard now asserts the retired hooks and hexes are absent from rendered Blade
and that exactly **one** `[data-shell-timer]` node exists per page. `time-tracker.blade.php` was
identified as a legitimate non-shell consumer of the event contract and deliberately left unchanged.

**A10.16's stacking follow-up is resolved by construction, not patched.** The strip is gone and the
timer lives inside the sticky utility bar, so there is no second participant to lose a z-index tie.
Blade has no portal, so a Chromium `elementFromPoint` assertion proves the open tray is actually the
topmost element at its own coordinates.

### A11.12 Defects found by real-browser validation

1. **Tray rows were ordered wrongly whenever two timers started in the same second.** The pill showed
   the newest while the tray listed the oldest first. `time_entries.timer_started_at` is a MySQL
   `timestamp` with **no fractional seconds**, so concurrent timers carry byte-identical `started_at`
   values; sorting on the timestamp alone left their order to the server's, while the pill's reduce
   resolved the tie the other way. Fixed by one shared `compareRecency` used by both the pill and the
   tray, breaking ties on the monotonic auto-increment id. The unit tests had missed it because they
   only ever used distinct timestamps; a tie regression test was added.
2. **Escape in a description field closed the whole tray on React.** Radix listens for Escape on the
   document, so the field's synthetic `stopPropagation` never reached it. Fixed with an
   `onEscapeKeyDown` veto — then narrowed a second time, because the first version vetoed *whenever* an
   input had focus and so stopped Escape ever closing the tray. It now vetoes only for a field holding
   an unsaved draft.
3. **Two presentation defects seen only in a screenshot.** Opening the tray put the caret in the first
   description field and selected its text; and the outline Stop square read as an unchecked checkbox
   beside its label. Fixed by focusing the tray container itself and by filling the square.
4. **The Blade tray could be left stuck open.** Its description field swallowed Escape
   unconditionally, so with no draft the tray stayed open where React's closed — the exact
   renderer-divergence the shared contract exists to prevent. Aligned with React's draft rule.

Also found and fixed while migrating the suite: the pill's `aria-live` region would have collided with
the board specs' `[aria-live="polite"].sr-only:not([data-shell-announcer])` selector, causing a
strict-mode violation. The React announcer now carries `data-shell-timer-announce` (as the Blade one
already did) and both board specs exclude it explicitly.

### A11.13 Responsive, theme and contrast (measured in Chromium, both themes)

| Width | Pill | Overflow | Overlap with breadcrumb / rail |
|---|---|---|---|
| XL 1440 | 295×32 | none | none / none |
| L 1200 | 295×32 | none | none / none |
| M 900 | 295×32 | none | none / none |
| S 390 | 135×44 (narrow form) | none | none / none |
| 200% zoom | — | none | — |

Contrast, sRGB, rendered composites (measured after letting the theme transition settle — an immediate
read returns an interpolated colour and understates it badly, which is worth recording for the next
amendment that measures this way):

| Surface | Light | Dark |
|---|---|---|
| Pill elapsed digits | 6.32:1 | 10.45:1 |
| Pill context label | 10.08:1 | 10.17:1 |
| Live dot vs backdrop (non-text, needs 3:1) | 4.29:1 | 10.45:1 |
| Tray row title | 6.40:1 | 7.17:1 |
| Tray elapsed | 6.32:1 | 10.91:1 |
| Tray description field edge (non-text, needs 3:1) | 3.59:1 | 3.47:1 |

Blade measured identically to React in both themes (6.32:1 light, 10.45:1 dark), which is the intended
consequence of both renderers using the same tokens. Touch targets at S are 44px. No new colour token
was introduced; the only new token is the motion one in A11.5.

### A11.14 Tests

**Pest (+5, all in existing files).** `BladeShellTest`: the pill mounts inside the utility bar with its
idle server-rendered state and a closed, named, non-modal tray; it is withheld from an actor without
`time.log`; the retired strip's hooks and hexes are gone and exactly one timer affordance survives.
`ShellSeamContractTest`: `@/components/time/timer-pill` and `timer-tray` added to the page-chrome
guard. **No new controller tests** — WP6 changes no server behaviour, and manufacturing them would
inflate coverage without asserting anything new.

**Vitest (+79, 672 → 751).** `lib/timer-state.test.ts` (27) — the pure contract, including the
same-second tie, malformed timestamps, minute/hour/day boundaries and the no-clock-in-the-name rule.
`timer-pill.test.tsx` (17) — the full zero/one/many/pending/failed matrix, malformed state,
accessibility, and the **tick-localisation guard**. `timer-tray.test.tsx` (10) — description editing,
Escape's two meanings, individual stop, absence of every NEXT feature. `timer-control.test.tsx` (6).
`shell/blade-timer.test.ts` (24) — the Blade runtime, including the preserved event contract, the
`419` reload, malformed payloads and no-interval-while-idle. `timer-provider.test.tsx` retargeted from
the deleted `RunningTimerBar` onto `TimerPill`.

**Playwright (+8, 74 → 82).** Eight WP6 flows covering §25.3 flow 9 and the timer half of flow 10.
They live in a `describe` block **inside `time-migration.spec.ts`**, not in a spec of their own — see
A11.15.

The eighth was added after the WP6 audit and is worth its own note, because it exists to pin a cascade
this epic does not own. The narrow-shell flow above runs on React, and jsdom sees no CSS at all, so
nothing exercised the **Blade** pill at S with real stylesheets applied. The audit raised a hypothesis
there: Blade toggles the pill's parts with the `hidden` **attribute**, and the two hand-written rules —
the one enforcing `hidden` and the S rule that shows the narrow elapsed span — tie on specificity, with
the S rule later, so it should win and leave a stale clock visible beside "Stopping…" and "Start timer"
(`blade-timer.ts` hides that span, it never clears its text).

**Measured in Chromium, the hypothesis is wrong and there is no defect.** The attribute wins, because
Tailwind's preflight emits `[hidden]:where(:not([hidden="until-found"])){display:none!important}`, and
an `!important` author declaration outranks every normal one regardless of specificity or order. The
hand-written `[data-shell-timer] [hidden]` rule is belt-and-braces on top of it. **No CSS was changed.**

What the episode did expose is that the behaviour rests on a third-party stylesheet's `!important` —
which a Tailwind upgrade, or a `display` utility added to that span, could quietly remove. So the test
stayed: at 390px on a Blade document it asserts the narrow form shows while running, that no elapsed
value is drawn beside "Stopping…", and that the pill returns to "Start timer" with no stale clock
behind it. It passes; the point is that it would stop passing if that guarantee ever moved.

### A11.15 One spec file owns the member's timers

Timers are global per user and Playwright runs spec files concurrently across its three workers. A
second `member` spec that started and stopped timers would race `time-migration.spec.ts`: an exact-count
assertion would see the other file's timer, and `stopAllTimers` would stop it mid-test. That is exactly
what happened when the WP6 flows first lived in their own file — they passed alone and failed in the
full suite.

The fix was to give the member's active-timer set a single owning file, since tests within a file run
serially. **No third persona was added** (one more identity is one more login against Fortify's
per-email limiter for no behavioural gain), the suite was not serialised, and the three-worker
architecture is untouched. The shared helpers in `support/shell.ts` are deliberately renderer-agnostic,
which is what lets one assertion drive both renderers across a crossing.

**The same is not true of the `operator` persona, and nothing here made it so.** Ownership was
established for the member's timers because that is what WP6's new flows mutate. Operator timer state
is mutated from several concurrently schedulable spec files — `board-migration`, `projects-migration`,
`tasks-migration` and `task-detail-migration` all default to `operator` and start and stop timers as
their navigation signal, and the `as an operator` group in this file both calls `stopAllTimers` and
asserts exact running-timer counts. With `fullyParallel: false` those files are distributed across the
three workers and can overlap, so an exact count here could in principle see another file's timer, and
the broad cleanup could stop one mid-test.

This is **pre-existing test-infrastructure debt, not a WP6 product defect and not a WP6 regression**:
the pre-WP6 version of that group already did the same thing, stopping every React timer and asserting
`#timer-overlay [data-timer-id]` had a count of exactly one. WP6 renamed the helpers and left the shape
untouched. It is recorded here rather than redesigned because rebalancing the operator E2E architecture
is not WP6's scope and would touch four specs WP6 otherwise only re-pointed at a new selector.

The invariant future timer work needs to carry: **operator timer state is not isolated, and must not be
assumed isolated merely because member state is.** Anything adding exact-count or stop-everything
assertions for the operator should either move into a single owning file the way the member flows did,
or stop relying on the actor's global timer set.

The five other specs that used the retired strip's `region "Active timers"` as their
"did this navigate over Inertia?" signal now use the pill's clock, which serves the same purpose and is
equally persistent.

### A11.16 Validation results

- **Pest:** 1,118 passed / 5,964 assertions (baseline 1,113 / 5,874).
- **Vitest:** 751 passed / 73 files (baseline 672 / 69).
- **Playwright:** **81/81 passed, 3 workers, 2.8 minutes** (baseline 74/74, ~2.0 min). Auth traffic for
  the run: **12 POST `/login`, 12 × 302, 0 × 429, 0 × 419, 0 application 5xx** — identical to the
  accepted baseline, so WP6 added no login traffic.
  - *Post-audit amendment.* That run covered the 81 tests present at the time. The Blade-at-S flow
    added afterwards (A11.14) brings the suite to **82**, and it was verified on its own —
    `--grep "the Blade pill hides its narrow clock"`, **1 passed**, product-data counts unchanged
    before and after. The full suite has **not** been re-run for it: the only other changes in the
    remediation are documentation and one test comment, none of which touch product code. The 81/81
    figure above is reported as what it is — the accepted evidence for the implementation — and is not
    restated as 82/82.
- **`./dev check`:** all checks passed (CLI self-tests, `git diff --check`, Pint, `npm run check`, Pest).

### A11.17 Known issues, not addressed here

- **The `E2E … standalone task` fixture leak is unchanged and still pre-existing** (A9.7). One task row
  leaks per full browser run; rows dating from 2026-09-27, before WP6, confirm it predates this work.
  Not WP6's to fix, and it intersects nothing WP6 asserts.
- **`tests/Browser` is neither type-checked nor linted.** `tsconfig.json` includes only
  `resources/js/**` plus `vite.config.ts`, and the lint glob matches the same set, so a browser spec
  can reference an undefined import and still pass `npm run check` — it fails only when Playwright runs
  it. Observed while migrating the specs (an unimported helper type-checked clean). Pre-existing,
  unrelated to the timer, and widening either glob is likely to surface unrelated errors, so it is
  recorded rather than changed.
- **The Vitest suite runs close to its default 5s per-test timeout under load.** Two tests in files WP6
  does not touch (`pages/projects/create.test.tsx`, `components/projects/milestone-form-dialog.test.tsx`)
  timed out once each while a browser suite ran concurrently, and passed in five consecutive full runs
  afterwards. Vitest's own output notes jsdom creation is ~42% of tracked time across 73 environments.
  WP6's four new test files add to that load without being its cause; if this recurs, the fix is the
  environment strategy Vitest suggests, not a longer timeout.
- **`TimerControl`'s unavailable state is deferred** — on scope and reachability, not dependency cost (A11.7); registered in [§31](#31-deferred-follow-on-work).

### A11.18 WP7 handoff

WP7 (page frames and Home) may start. What it inherits: the utility bar now has a populated right-hand
slot (`UtilityBar`'s `children` on React, the `shell.timer` include on Blade), so a future search or
command affordance shares that row with the pill and must fit beside it within the bar's 48px.
`resources/js/lib/timer-state.ts` is the pattern for any further logic both renderers need — small,
pure, server-derived, imported by both. `time-migration.spec.ts` owns the `member` persona's timer
state; any new spec that starts timers as that persona belongs in it, or must use a different actor.

## Amendment 12: WP7 Results (2026-09-28)

**Status:** WP7 (page frames and Home) implemented on the WP6 tree (`8f2fc34`). **This is the WP7
record; it does not revise Amendments 1–11.** No route, endpoint, migration, permission, policy,
controller, model or service changed, and no dependency was added — §21.2 is explicit that Home's
four props keep their shape and its route keeps its name, and they did. WP8 has not started.

### A12.1 Scope, as the committed plan defines it

[§28 WP7](#wp7--page-frames-and-home) assigns four things: `PageFrame` (canvas/grid/reading),
`EntityHeader`, `Strata`; Dashboard → **Home** with honest data and no new props; `projects/board`
onto `PageFrame width="canvas"`; and the other ten pages' containers left alone. Exit: Home is a real
Direction D surface, nothing is fabricated, other pages still work. [§29](#29-exit-criteria)
criterion 10 restates it as: all three width classes exist, Home adopts Direction D using only
existing DTO data, and no approvals, project health or Helpdesk intelligence are invented.

Two boundaries were read from the plan rather than assumed, because a name alone would have misled:

- **Strata is a rule, not a container.** Direction D §17 and the §4.3 rule table define it as three
  stacked lines — 1px `rule-control`, 1px `text-faint` at 70%, 2px `rule-strong`, 2px apart — allowed
  **under entity headers only** and forbidden on "section headings, tables, cards, dialogs, **Home**,
  list pages". L8 says the same. Reading it as a card or panel system would have inverted the one
  rule it carries. Home therefore has no strata; the board does.
- **Home uses `PageHeader`, not `EntityHeader`.** §21.2 names `PageHeader` + `Section`, and §6
  separates the two grammars: an operator page has an overline, title, summary and actions; an entity
  page has the record's name, its state, its actions, then the strata. Home is the first; the board is
  the second.

**No conflict** was found between the committed specification and the work-package brief.

### A12.2 Pre-WP7 page architecture

Twelve React pages each supplied their own container and their own title block. The duplication that
mattered for WP7 was narrow and specific:

| Pattern | Where | WP7's answer |
|---|---|---|
| `mx-auto max-w-7xl px-4 sm:px-6 lg:px-8` | Home and four others | `PageFrame` owns page geometry |
| `px-4 py-6 sm:px-6 lg:px-8` with no max-width | `projects/board` | `PageFrame width="canvas"` |
| A hand-built title row with an in-page `Projects /` trail | `projects/board` | `EntityHeader` |
| Four bordered, shadowed metric cards | Home | A figure row (§21.1) |
| Ad hoc one-line empty copy | Home and three others | `EmptyState`, with Home as its consumer |

Everything else that looks similar across the twelve pages — forms, filters, tables — was left alone.
§19.3 converts the pages it is already touching and no others, and R11 caps WP7 at exactly two.

### A12.3 The page grammar

The shell answers *where am I*. The frame answers *how does this page's content sit in the canvas*,
and the header answers *what am I looking at*. They are separate components because they are separate
questions, and none of them knows anything about a domain.

- `PageFrame` owns page gutters, vertical rhythm, content width and the optional supporting column.
  It owns no navigation, no utility bar, no breadcrumb, no timer, no authorization, no routing and no
  data. `<main>` stays unpadded (§19.2), which is what lets a canvas page reclaim the full viewport.
- `PageHeader` (WP2, unchanged) states a page. `EntityHeader` states a record and draws the strata.
- `Section` (WP2, unchanged) groups content under a `rule-strong` line.
- The ten unmigrated pages are untouched and unaffected: nothing in the new CSS applies without
  `data-page-frame`, so their own containers keep working exactly as before.

### A12.4 `PageFrame`

A discriminated union rather than one wide prop bag, so a prop is only offered where it means
something: `aside` is meaningless on a reading column and `measure` is meaningless on a board.

| Width | Behaviour | Consumer |
|---|---|---|
| `canvas` | Full width minus gutters. **No max-width at all.** | `projects/board` |
| `grid` | `1fr + 340px` at XL; stacks below. Optional `header` (spans) and `aside` (labelled). | Home |
| `reading` | Centred column at `forms` 640 / `conversation` 700 / `account` 720 / `knowledge` 760. | **None yet** |

`reading` ships without a consumer because §29 criterion 10 requires all three width classes to
exist and §25.2 asserts each one applies its documented constraint. It is four CSS rules and a data
attribute, not a speculative component, and the first reading-width page adopts it without inventing
it. That is the one place WP7 builds ahead of a consumer, and it is the plan asking for it.

The `header` slot exists because Home needed it: §6 draws the page header spanning the content
region, and inside the main column its rule would stop where the supporting column begins.

**Geometry lives in `app.css`, not in utilities.** The gutters are 40/32/24/16 by width class
(§19.2), and XL is 1360px, which is not a Tailwind screen — expressing it in utilities would have
meant either a second breakpoint vocabulary or a JS width query, and the width classes are the one
place widths are decided. The rules are unlayered for the reason A8.12 #2 records. The consequence is
that a jsdom test cannot measure them, so the split is deliberate: `page-frame.test.tsx` asserts which
geometry the frame *names*, and the browser suite measures what it *is*. Neither half can quietly
assert nothing.

### A12.5 `EntityHeader` and `Strata`

`EntityHeader` takes `title`, `overline`, `status`, `meta` and `actions`, renders the record's name as
the page's one `h1`, and closes the block with `Strata`. It is an identity band, not a hero: one
compact row where the width allows, so the record's actual content is not pushed below the fold.

It knows no domain. No project, task, ticket or customer concept appears in it; the caller passes
already-resolved strings and **already-authorized** action elements, because a component that received
every possible action and decided which to draw would be making a capability decision it has no basis
to make. It also draws no breadcrumb — the utility bar has owned that trail since WP4, and a second
one inside the page would be both a duplicate landmark and a second source of truth.

`Strata` is the motif itself and is drawn by `EntityHeader` alone. That scope is now a static guard in
`ShellSeamContractTest`: no file outside `entity-header.tsx` may import it. A motif that means "this
page is a record" stops meaning anything the first time another surface borrows it for decoration, and
no rendering test would catch that.

### A12.6 Home

`PageFrame width="grid"`, `PageHeader` (date overline, title, summary), `Section` titles over
`rule-strong`, and a supporting column carrying Quick actions and Directory.

**The four metrics are a figure row, not cards** (§21.1, L10): a description list of label-and-number
pairs, mono tabular numerals, separated by hairlines drawn by the band rather than by a border around
each figure. Each figure is still its own link to the surface the number came from, and its accessible
name now carries the value — a link called only "Open Tickets" would make a screen-reader user open
the page to learn the number.

**Preserved exactly:** the four props and their shape; every `visit` mode (Inertia vs document, which
the server decides and the page honours); every href; the `crmSummary` gating; the route, its name and
its URL. **Changed:** the composition, the "Dashboard" label → "Home" (the rail has said Home since
WP3), the "CRM" heading → "Directory" to match the IA while the prop keeps its key, and the ticket
list's empty copy.

**Fabricated: nothing.** Approvals, project health, Helpdesk intelligence, SLA, "my work", watch lists
and notifications are all absent, each because §21.1 marks it Future for want of any data behind it.
Both the Vitest suite and the browser suite assert their absence, because the realistic failure mode
is a later change quietly adding a plausible-looking figure with nothing behind it.

**One deliberate deviation — acceptable documented deviation, corrected below (A12.16).** §21.1 marks
"running timers on Home" as *Ship if it costs nothing beyond reading existing context*. It is not
implemented. §12/§18 already planned the global timer pill as foundation scope when §21 was written —
the shell did not yet have no timer surface planned, only none *built* — and WP6 is what actually put
the pill permanently in the utility bar on every page. With that pill live, a timers section on Home
would duplicate a control already on screen a few hundred pixels above it, and the tray it opens
already lists every running timer, which is the "richer view" a Home section would otherwise exist to
give. The condition "costs nothing" was about data availability, and that condition is still met; what
changed is that Direction D §12.1's "exactly one global timer affordance" now has its one affordance
elsewhere, and duplicating it is what §21.1 did not anticipate paying for. Recorded here rather than
silently dropped.

### A12.7 `projects/board`

`PageFrame width="canvas"` and `EntityHeader`. The board was already full-bleed, so the frame hands it
the whole canvas minus the gutters; measured at XL with the drawer collapsed it is 1376px wide with
40px gutters and no max-width. The `Board` component itself — drag, quick-add, move, reorder — is
untouched, as are `abilities.manage` and `abilities.openSettings`, which remain the server's answers.

The hand-built title row became the real `EntityHeader`, which is where the strata legitimately
appears: §17 lists the project workspace by name. That row also carried its own `Projects /` trail,
which is gone, so the page now has one breadcrumb instead of two.

### A12.8 `EmptyState`

WP2 deferred it and A6.10 named **Home** as its first structured consumer. Home is that consumer, so
the primitive arrives with it rather than ahead of it — the same rule WP6 followed for Popover. It is
a rule-bounded band, not a card, and it takes a title and an optional description so a caller can say
*which* kind of empty this is; §21.2 asks for copy distinguishing truly-empty from filtered-empty, and
that is a writing requirement the component makes room for rather than one it can satisfy alone. Home
has no filter, so its copy says what would put something in the list instead.

**No other deferred primitive was revived.** `Tabs`, `Tooltip`, `Skeleton`, `ErrorState` and `Tag` have
no WP7 consumer and none was invented for them. The board's Milestones link is a route link with
`aria-current`, not a tab (A6.3), so no Radix Tabs dependency arose.

### A12.9 Responsive and theme results (measured in Chromium)

| Width class | Viewport | Gutters | Home | Board |
|---|---|---|---|---|
| XL | 1440 | 40px | `916px + 340px` split | frame 1376 with the drawer collapsed |
| L | 1200 | 32px | stacked, aside full width | canvas |
| M | 900 | 24px | stacked | canvas |
| S | 390 | 16px | stacked | canvas, no document scroll |
| 200% | 640 logical | 16px | stacked | — |

No horizontal page overflow at any width on either page. Exactly one `h1` at every width. The `h1` is
not clipped at 390px or at 200%. Both themes hold their structure; the figure band's numerals are drawn
from tokens in both. The aside stacks *under* the content it supports below XL, which is also its
reading order.

The split waits for XL rather than starting at L because at L the docked drawer has already taken
248px, and splitting the remainder would leave the main column narrower than the 340px aside beside it.

### A12.10 Accessibility

- One `h1` per page: "Home" on Home, the project's name on the board. The board previously had one
  too, but as a hand-built row rather than a header component.
- One breadcrumb landmark on both pages. WP7 **removed** the board's second one by replacing the
  markup that drew it. The separately recorded duplicate in `projects/tasks/show.tsx` is untouched:
  that page is one of the ten §19.3 leaves alone, and WP7 does not own its markup.
- `PageFrame`'s supporting column is a labelled `<aside>` ("Shortcuts and directory"), so it is a named
  complementary landmark rather than an unnamed region.
- `Strata` and `EmptyState`'s icon are `aria-hidden`; the meaning is carried by the heading each sits
  with.
- Status stays glyph-and-label, never colour alone (§10), on both the ticket list and the board header.
- Metric links are named by label, value and supporting text.
- Actions remain real links, keeping their server-decided visit mode. No button nests inside a button.
- WP7 introduces no motion, so there is nothing new under `prefers-reduced-motion`.

### A12.11 Tests

**Vitest (+24, corrected from an earlier "+34" — A12.16).** `page-frame.test.tsx` (5) — which geometry each width names, the reading default and
its variants, `data-page-split` only when an aside is actually supplied, the header spanning as a
direct child, and no complementary landmark on the single-column widths. `entity-header.test.tsx` (7,
including `Strata`) — the `h1` level, the decorative strata, regions omitted when absent, **no
breadcrumb**, exactly the actions it is handed and no others, a long name that is not truncated away,
and the motif's three lines heaviest last. `empty-state.test.tsx` (5). `pages/dashboard/index.test.tsx`
(9, rewritten) — the grid frame and labelled aside, the figure row as `dt`/`dd` pairs rather than
cards, mono tabular numerals, the empty copy, Directory gating, the metric row omitted entirely when
the actor may see no metric, and the absence sweep for the seven fabricated concepts.

That last file was **rewritten, not replaced**: its three pre-WP7 cases asserted the `visit` contract
through a `data-inertia` marker, the metric link's accessible name, and the CRM gating. All three
properties are still asserted — the `data-inertia` mock is kept deliberately, because which links stay
in the SPA is behaviour and WP7 is a presentation change.

**Pest (+1 at WP7 completion; +1 more after audit remediation — A12.16).** The strata scope guard in
`ShellSeamContractTest` (A12.5). WP7 changes no server contract, and no controller or request test was
added for one — that much holds. This paragraph originally went further and claimed
`DashboardInertiaTest`'s existing assertions over the four props were "exactly the guard that the
props did not move." That overstated it: none of those assertions checked the complete top-level prop
set, so a fabricated fifth prop would have passed unnoticed. §25.1's "Home DTO minimality" row asked
for exactly that guard and none existed. A12.16 adds it and corrects this claim.

**Playwright (+7, 82 → 89).** `home.spec.ts` (6) — the grid split and the 340px aside at XL against
stacking below it, the measured 40/32/24/16 gutters with `main` still unpadded, the figure band with no
figure carrying a radius or shadow, reflow at 200% and 390px with an unclipped heading, both themes,
and the one-`h1`/one-breadcrumb/nothing-fabricated/no-strata assertions. `board-migration.spec.ts` (1)
— the board reclaiming 1376px with the drawer collapsed, `max-width: none`, the entity header with its
single `h1` and its one strata, and one breadcrumb where there used to be two.

`home.spec.ts` is a new file and needs no ownership boundary of the kind A11.15 records: Home is
read-only, creates no fixture and starts no timer. The board flow deliberately went into
`board-migration.spec.ts`, which already owns project fixtures and their cleanup, rather than into a
second spec that would have created its own.

**Two existing test files were updated rather than deleted, both inverting an assertion WP7 made
false.** `pages/projects/board.test.tsx` asserted the in-page `Projects` back-link; it now asserts the
page draws *no* trail and no navigation landmark of its own, which is the clearest record of the
boundary moving to the shell, plus a new case for the entity header and the canvas frame.
`inertia-coexistence.spec.ts` asserted a `Dashboard` heading in two places; both now assert `Home` at
level 1. That spec already navigated by a rail link **named Home** (WP3), so the page heading and the
rail had disagreed since then and now agree. The route, its name and `/dashboard` are unchanged.

### A12.12 Chromium findings

Two, both found by measuring rather than by reading:

1. **A false alarm, recorded because the first read was wrong.** At screenshot scale the strata under
   the board's entity header looked like one heavy line rather than three. The first probe appeared to
   confirm it — but it had selected the status badge's dot, because `header [aria-hidden="true"]`
   matches that first. Re-probed against a precise hook, the motif measures exactly as §4.3 specifies:
   8px total, 2px gaps, lines of 1px `rgb(210,206,195)`, 1px `text-faint` at 70%, and 2px
   `rgb(26,27,30)`. Nothing was changed. `Strata` did gain a `data-strata` hook, which the browser
   suite now uses.
2. **Everything else measured first time.** Gutters, the 340px aside, the 1376px canvas, single `h1`,
   no overflow at any width, both themes.

### A12.13 Deviations and follow-ups

- **Running timers on Home: not implemented** (A12.6). Deliberate, reasoned, recorded.
- **`PageFrame width="reading"` ships without a consumer** (A12.4), because §29 criterion 10 and §25.2
  require the width class to exist and be asserted.
- **Untouched pre-existing issues**, none of which WP7 owns: the duplicate breadcrumb landmark in
  `projects/tasks/show.tsx`; `tests/Browser` being neither type-checked nor linted; the load-sensitive
  Vitest jsdom overhead; the `E2E … standalone task` fixture leak; the operator-persona E2E timer
  isolation debt (A11.15); and the cosmetic lockfile caret (A11.2). `home.spec.ts` adds no mutable
  resource, so none of them is made worse.
- **The other ten pages keep their containers**, per §19.3. Converting them belongs to the epics that
  redesign them.

### A12.14 Validation results

- **Pest:** **1,119 passed / 5,966 assertions** (WP6 baseline 1,118 / 5,964 — the one addition is the
  strata scope guard).
- **Vitest:** **775 passed / 76 files** (baseline 751 / 73).
- **Playwright:** **89/89 passed, 0 failed, 0 skipped, 3 workers, 4.5 minutes** (baseline 82 tests,
  of which the last full run measured 81/81 in 2.8 min). Auth traffic for the run: **12 POST `/login`,
  12 × 302, 0 × 429, 0 × 419, 0 application 5xx** — the same twelve logins as every run since WP4, so
  the new spec file added none. Full status distribution: 891 × 200, 144 × 302, 67 × 499 (client
  navigated away mid-request), 39 × 303, 2 × 404 (a fixture teardown deleting an already-deleted
  project), 2 × 403 (the deliberate `admin/users` authorization flow, §25.3 flow 12).
- **`./dev check`:** all checks passed — CLI self-tests, `git diff --check`, Pint, `npm run check`
  (Wayfinder, typecheck, lint, format check, Vitest, production build), Pest.

**Two failures were real and are fixed; the rest were load, and the difference was measured, not
assumed.** A first full run of both suites *concurrently* reported 9 failed Vitest files and 16 failed
Playwright tests, with Playwright taking 9.8 minutes against a 2.8-minute baseline. Run alone, Vitest
fell to **one** failure and Playwright to **one**, and both were genuine WP7 regressions: the board
page test asserted the back-link WP7 removed, and `inertia-coexistence.spec.ts` asserted the heading
WP7 renamed. With those fixed, both suites are green. The other 23 were the load-sensitivity A11.17
already records — jsdom creation alone accounted for 969s of tracked time in the concurrent run
against 368s when run alone — and the lesson is about how these gates are run, not about the tests:
**do not run the two full suites at once.**

**Fixture counts:** the authoritative run moved `tasks` by +1 and left `projects` and `time_entries`
unchanged. That +1 is the pre-existing `E2E … standalone task` leak A9.7 and A11.17 record, one row
per full run, unchanged by WP7. The development database also carries a project and two tasks left by
the *failed* concurrent run, whose aborted tests never reached their cleanup; nothing was deleted, and
they are inert fixtures.

### A12.15 WP8 handoff

WP8 (hardening and verification) may start. What it inherits: `PageFrame` exists with all three width
classes, and `reading` is the one with no consumer yet — the compatibility matrix should exercise it
through whichever page first adopts it, or note that none has. Home and `projects/board` are Direction
D surfaces; the other ten pages still carry their own `mx-auto max-w-*` containers by design (§19.3),
so the ten-screen matrix is still measuring those containers, not `PageFrame`. The strata motif's
scope is now statically guarded, so WP8's regression audit does not need to re-police it by reading.
The browser suite is 89 tests at 4.5 minutes on three workers, which is the figure the CI runtime
budget in §30 should use rather than the 2.8-minute WP6 number.

### A12.16 Independent audit and remediation (2026-09-28)

An independent read-only architectural audit of the uncommitted WP7 tree (§28's scope, A12.1–A12.15 as
written above, before this section) found the architecture **sound and fit to freeze** — no Critical
and no High finding — but withheld a ready-to-commit verdict pending **2 Medium and 6 Low findings**,
all local. This section is not a rewrite of A12.1–A12.15: those sections stand as the record of what
WP7 actually shipped for review, including the two claims (the Vitest delta and the
`DashboardInertiaTest` coverage claim, both corrected in place above with a pointer here) that the
audit found inaccurate. What follows is what changed in response, applied to the same uncommitted WP7
tree, before commit.

**M1 — the Home metric row's `dl` structure was invalid HTML.** `dt`/`dd`/`p` sat inside an `<a>`
inside `dl > div`; an anchor is not a legal parent of `dt`/`dd`, and only jsdom's tag-based role
assignment — not the HTML content model — made the pre-remediation test pass. Fixed by making
`dt`/`dd`/`p` direct children of the group `div` and turning the link into a stretched overlay
(`absolute inset-0`) that carries the figure's full accessible name instead of wrapping the content.
The whole figure is still one click target; nothing about the visible figure row, the four props, any
href or any `visit` mode changed. `dashboard/index.test.tsx` gained a case asserting `dt`/`dd` are
direct children of the group and that neither has an anchor ancestor.

**M2 — `EmptyState` centred its content against Direction D §15.2**, which reads "Left-aligned inside
the region, not centred illustrations." `text-center`, `mx-auto` and `justify-center` are gone; the
icon now sits inline beside the copy as §15.2's "optional small line glyph" rather than above it as an
illustration. `empty-state.test.tsx` gained a case asserting none of the pre-remediation centering
utilities appear anywhere in the tree.

**L1 — `PageFrame`'s `asideLabel` was optional even though the comment above it said a label is
required whenever `aside` is supplied.** The `grid` arm is now a union of `{ aside: ReactNode;
asideLabel: string }` and `{ aside?: never; asideLabel?: never }`, so the type now enforces what the
comment already claimed. Home's own usage (`aside` and `asideLabel` always supplied together) needed
no change; `npm run typecheck` is clean.

**L2 — the Strata scope guard only matched the literal string `@/components/strata`**, so a relative
import (`./strata`, `../components/strata`) would have passed it unnoticed even though the
architectural rule it enforces — Strata is drawn by `EntityHeader` alone — is correct. The guard now
matches any quoted module specifier whose last path segment is `strata`, which catches the alias and
every relative spelling without parsing JavaScript imports. A new fixture-level test exercises the
detection function directly against both forms, plus a near-miss (`strata-legacy`) it must not flag.

**L3 — the "both themes" Playwright assertion was vacuous**: it asserted a captured colour was
`toBeTruthy()`, which a hard-coded literal colour would also satisfy, so it did not prove the comment's
claim that the figure numerals are token-driven. `home.spec.ts` now captures the colour in both light
and dark and asserts `colours.light !== colours.dark`, which a literal colour cannot satisfy.

**L4 — `entity-header.test.tsx` located Strata with the broad `[aria-hidden="true"]` selector**, the
same selector that matched the wrong element (a status glyph's dot) during the Chromium measurement in
A12.12. The test now uses `[data-strata]`, the stable hook that measurement settled on, and additionally
asserts the element it finds carries `aria-hidden="true"` rather than assuming the selector guarantees it.

**L5 — Home's empty-ticket copy ("Nothing is waiting on you here") implied personal assignment** that
the underlying query does not consistently support: the operator query is every open ticket, not this
actor's own. The sentence is now "New support activity appears in this list as it arrives.", true for
both audiences.

**L6a — the Vitest delta was misstated as "+34"; the real delta is +24** (751 → 775, and the per-file
counts in A12.11 sum to 24). Corrected in A12.11 above. The 775 absolute result is unchanged by that
correction; this remediation pass adds 2 more (the M1 and M2 test cases), for **777**.

**L6b — A12.11 claimed `DashboardInertiaTest`'s existing assertions were "exactly the guard that the
props did not move."** They were not: none of them checked the complete top-level prop set, so a new
prop would have passed silently. Corrected in A12.11 above; L6c is the actual guard.

**L6c — §25.1's "Home DTO minimality" row ("`dashboard` still ships exactly `metrics`, `recentTickets`,
`quickActions`, `crmSummary` — no new props") had no test asserting it.** Added directly rather than
deferred to WP8: `DashboardInertiaTest` now has a case that reads the raw Inertia page response,
subtracts the named set of props every page shares (`errors` from Inertia's own base middleware, plus
`app`, `auth`, `shell`, `navigation` and `flash` from `HandleInertiaRequests::share()`), and asserts
what remains is exactly `metrics`, `recentTickets`, `quickActions`, `crmSummary` — no more, no fewer.
**What this proves:** Home's own top-level props are exactly the four §25.1 names, so any fifth prop —
whatever it is called, including but not limited to an "approvals" list, a "project health" score,
"SLA" or "my work" data, a watch list or a notification — would fail the test the moment it appeared.
**What it does not prove:** it does not prove anything about the *shape* of the four props themselves
(the two existing tests already do that), and it does not protect against a change to
`HandleInertiaRequests::share()` silently growing the shared set — a real new shared prop must be added
to the test's own named list to keep passing, which is deliberate: that is the one place a change to
what every page shares becomes visible in this file, rather than disappearing into an already-broad
diff.

**L6d — A12.6 said "when §21 was written the shell had no timer surface," offered as why the pill
supersedes the §21.1 timer-on-Home item.** That was backwards: §12/§18 already scoped the global timer
pill as foundation work when §21 was written; only its *build* (WP6) came later. Corrected in A12.6
above. The conclusion does not change and is restated precisely: this is an **acceptable documented
deviation**, not full compliance — §21.1's "costs nothing" condition was about data availability and
is still met, but Direction D §12.1 permits exactly one global timer affordance, WP6 gave the product
that one affordance in the utility bar pill, and its tray already lists every running timer, which
makes a second, Home-local instance of the same affordance the thing that would now cost something.
Neither the WP7 exit criteria nor §29 requires a Home timer section.

**Not remediated, on the audit's own instruction.** Two observations (Tailwind's `sm`/`xl` breakpoints
coexisting with Direction D's 768/1360 geometry; 1360/1024/768 as literal media-query values matching
the existing shell CSS) were explicitly out of scope and are unchanged. The local development
database's E2E residue (an old WP7 canvas project and the pre-existing standalone-task fixture leak,
both already recorded in A12.14) was confirmed harmless and left untouched, per the audit and per
§28's standing rule that WP7 does not own pre-existing debt it did not create.

**Validation after remediation.** Focused Vitest across the five touched component/page files: **33
passed** (page-frame 5, entity-header 7, empty-state 6, `dashboard/index` 10, `board` 5). Full Vitest:
**777 passed / 76 files**. Focused Pest (`ShellSeamContractTest`, `DashboardInertiaTest`): **28 passed
/ 565 assertions**. Full Pest (via `./dev check`): **1,121 passed / 5,980 assertions**. Focused
Playwright, run alone rather than alongside `./dev check` (A12.14's own lesson about concurrent full
suites, applied here at the focused scale too): `home.spec.ts` and `board-migration.spec.ts`, **12
passed, 0 failed, 0 skipped**, 2 workers, 51.6s; development-database product counts unchanged before
and after (`projects=19 tasks=48 time_entries=3`). `./dev check` run alone afterward: all five gates
passed (CLI self-tests, `git diff --check`, Pint, frontend `npm run check` — Wayfinder, typecheck,
lint, format check, the full Vitest run above, production build — and the full Pest run above).
`git diff --check` is clean; `package.json` and `package-lock.json` remain byte-identical to HEAD; no
file under `app/`, `routes/`, `database/`, `config/` or `bootstrap/` changed; the only PHP files
touched are `tests/Feature/DashboardInertiaTest.php` and
`tests/Unit/Configuration/ShellSeamContractTest.php`, both test-only.

**The full 89-test Playwright run from A12.14 was not re-run.** None of M1/M2/L1–L6 changes a route,
a prop shape, a `visit` mode, an href, board behaviour, authorization, or anything the other 87 tests
in that suite observe; the two specs that do cover the changed surfaces were run alone and are green,
with fixture counts unchanged. The 89/89 figure in A12.14 stands as evidence for the tree as a whole,
not as something this remediation pass re-produced.

**Recommendation: WP7 is ready to commit.**
