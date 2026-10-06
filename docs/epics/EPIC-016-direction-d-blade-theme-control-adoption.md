# EPIC-016: Direction D Theme and Control Adoption for Blade Workspaces

**Status:** In Progress (2026-10-06). WP0 (this document, the design gate) and WP1 ([PR #23](https://github.com/Intechral-Solutions/intechral-client-portal/pull/23), merged as `6dfc115`, main CI green) are done; WP2, WP3 and WP4 are not started. The lifecycle moved from Planned to In Progress when PR 1 merged (§20). Results and review rulings are in [Amendment 1](#amendment-1-wp1-controls-and-accessibility-pr-1); the System Delete Role defect carried out of the WP1 review is closed by the hotfix in [A1.19](#a119-carried-finding-the-delete-role-form-hotfix).
**Class:** Hardening / design-system adoption (Product Roadmap [NEXT — Product/UX foundation → Blade workspace theme and control adoption](../product/product-roadmap.md#blade-workspace-theme-and-control-adoption))
**Design contract:** [Direction D — Design System Specification](../design/direction-d-design-system.md), as implemented in `src/resources/css/app.css` (the `--ds-*` layer and its `@theme inline` utilities) and the React primitives in `src/resources/js/components/ui/`
**Prerequisites:** [EPIC-013: Direction D Application Shell and Design System Foundation](./EPIC-013-direction-d-shell-design-system.md) (Done) · [EPIC-014: Tasks Workspace Overhaul](./EPIC-014-tasks-workspace-overhaul.md) (Done) · [EPIC-015: Projects UX Expansion](./EPIC-015-projects-ux-expansion.md) (Done) · Lightweight CI baseline (Done, [`docs/testing/ci.md`](../testing/ci.md))
**Inherits:** EPIC-013 [A13.4](./EPIC-013-direction-d-shell-design-system.md#a134-contrast-debt-disposition) (legacy Blade page-body contrast debt) and the matching [§31](./EPIC-013-direction-d-shell-design-system.md#31-deferred-follow-on-work) row · EPIC-015 [A6.16](./EPIC-015-projects-ux-expansion.md#a616-post-epic-follow-up-global-design-token--ui-consistency-audit-recorded-only) (post-EPIC design-token / UI consistency audit)
**Planning baseline:** `main` @ `d87b5b1` (`docs: close EPIC-015`), equal to `origin/main`, working tree clean, verified 2026-10-05. The read-only consistency audit that precedes this document was run on the same commit.

---

## Contents

1. [Goal](#1-goal)
2. [Relationship to the roadmap and earlier epics](#2-relationship-to-the-roadmap-and-earlier-epics)
3. [Current-state inventory](#3-current-state-inventory)
4. [Locked owner decisions](#4-locked-owner-decisions)
5. [Scope](#5-scope)
6. [Design authority](#6-design-authority)
7. [Blade component contract](#7-blade-component-contract)
8. [Action hierarchy rules](#8-action-hierarchy-rules)
9. [Status and semantic mapping](#9-status-and-semantic-mapping)
10. [Accessibility contract](#10-accessibility-contract)
11. [Theme normalization](#11-theme-normalization)
12. [Spacing, layout and product geometry](#12-spacing-layout-and-product-geometry)
13. [Target-area migration inventory](#13-target-area-migration-inventory)
14. [Projects and Tasks regression boundary](#14-projects-and-tasks-regression-boundary)
15. [Cross-application impact](#15-cross-application-impact)
16. [Legacy alias retirement](#16-legacy-alias-retirement)
17. [Scoped guard](#17-scoped-guard)
18. [Test strategy](#18-test-strategy)
19. [Work packages](#19-work-packages)
20. [Exit criteria](#20-exit-criteria)
21. [Non-goals](#21-non-goals)
22. [Deferred items and follow-ups](#22-deferred-items-and-follow-ups)
23. [Risks and rollback](#23-risks-and-rollback)
24. [Plan-level choices open to review](#24-plan-level-choices-open-to-review)
25. [Branch and PR strategy](#25-branch-and-pr-strategy)

---

## 1. Goal

Move the legacy Blade workspaces (**Helpdesk, Directory, Finance and System**) onto the Direction D theme and control system, so that their presentation flows the same way the React reference surfaces do:

```
semantic Tailwind v4 theme  →  shared Blade components / ordinary semantic utilities  →  application views
```

That replaces today's arrangement:

```
application view  →  raw CSS variables · literal colours · palette classes · duplicated styling
```

When the epic is done:
- **Controls.** Every target control (action, field, checkbox, pagination link) comes from a shared `x-ui.*` component carrying the Direction D treatment and the accessibility contract.
- **Status.** Every status, priority and alert uses the Direction D semantic mapping.
- **Colour.** Every application colour on a target page is a semantic theme utility, a justified new semantic utility, or a documented exception (§11.1). That includes ordinary page-body text, borders and surfaces.
- **Geometry.** Repeated product-level geometry follows the shared conventions; ordinary layout stays ordinary Tailwind (§12).
- **Locked in.** A permanent scoped guard keeps all of this from regressing.

**This is a presentation-system migration.** Every page renders the same information and behaves the same way before and after. No renderer, route, workflow, permission or business rule changes (§21).

The epic exists because of one architectural gap. Projects and Tasks are built from reviewed React primitives on the semantic theme. The Blade page bodies have **no equivalent seam** and use **no** Direction D semantic utilities at all: every colour is inline, against legacy raw variables. That is why their actions render in the retired indigo brand colour, why 44 field sites have no visible keyboard focus, why statuses are pastel pills that ignore the theme, and why their pages sit on the legacy gray palette inside a Direction D shell.

## 2. Relationship to the roadmap and earlier epics

- **EPIC-013** built the Direction D token layer, the React primitives and the Blade **shell** (rail, drawer, utility bar, timer pill). It deliberately froze about 900 raw-variable sites in the Blade page bodies "until Blade page bodies migrate", and recorded their contrast debt in [A13.4](./EPIC-013-direction-d-shell-design-system.md#a134-contrast-debt-disposition), assigning it to "each Blade module's product epic".

  EPIC-016 takes over the **presentation** side of that debt for the four target workspaces: controls, colour (including ordinary page-body colour), status presentation, accessibility, and repeated product geometry. Renderer migration and product redesign stay with the module epics.
- **EPIC-015** recorded the consistency audit as out-of-scope follow-up work ([A6.16](./EPIC-015-projects-ux-expansion.md#a616-post-epic-follow-up-global-design-token--ui-consistency-audit-recorded-only)). The audit ran on `d87b5b1`, and this epic is its destination.
- **Roadmap principle 4** ("redesign and renderer migration travel together") still holds. EPIC-016 does not redesign or re-render any module. It changes **how** existing Blade markup expresses presentation: through the semantic theme instead of raw values. When a module is later redesigned and moved to React, it starts from a page that already speaks the theme.

## 3. Current-state inventory

All figures are from `main` @ `d87b5b1` and are a **planning baseline**. Where Blade templating makes a count ambiguous (PHP ternaries, JavaScript strings), the figure is approximate, and each PR re-takes it. Rendered values come from a computed-style probe over 23 routes in both themes, run in the repository's pinned Playwright image with the source mounted read-only.

### 3.1 Rendering stack

| Area | Navigation (`NavigationBuilder`) | Renderer | Shared control primitives in use |
|---|---|---|---|
| Helpdesk | My requests, Queue, Reports (`visit: document`) | Blade: `tickets/*`, `operator/tickets/*`, `components/time-tracker` | none |
| Directory | People, Organizations, Portal access | Blade: `crm/contacts/*`, `crm/companies/*`, `organizations/*` | none |
| Finance | Invoices or My invoices | Blade: `billing/invoices/*`, `billing/client/*`, `billing/payment/show` | none |
| System | Users, Roles, Pages | Blade: `admin/users/*`, `admin/roles/*`, `operator/cms/*` | none |

All four render **inside the Direction D Blade shell** (`layouts/app.blade.php` and `layouts/partials/shell/*`). The shell partials already use Direction D utilities exclusively and contain no legacy accent. The inconsistency is entirely in the page bodies.


### 3.2 Control and accessibility census (target views)

| Pattern | Baseline |
|---|---:|
| `style="background-color: var(--accent); color: #fff"` primary fills | 36 (+2 in `errors/403`) |
| `style="color: var(--accent)"` links, row actions and figures | 25 (+1 in `errors/403`) |
| `accent-legacy-accent` checkboxes | 7 sites (43 rendered on `roles/create`) |
| `outline-none` fields with **no** replacement focus | 44 (12 files; 3 inside a JavaScript template string) |
| `outline-none` + `focus:ring-2` (ring falls back to `currentColor`) | 9 |
| `<label>` not associated with its field | 25 of 39 labels; 54 of 69 fields have no `id` |
| User-facing controls with **no accessible name** | about 30 (§10.2) |
| Field errors without `aria-invalid` / `aria-describedby` | every `@error` site (27) |
| `$paginator->links()` on the vendor view (`focus:border-blue-300`; `dark:` follows the OS) | 9 index views |
| Destructive actions, all with native `confirm()`, four different treatments | 8 forms |

### 3.3 Colour and theme census (target views; `errors/403` in brackets)

| Measure | Baseline | Notes |
|---|---:|---|
| Direction D semantic colour utilities in page bodies | **0** | everything is inline |
| Inline `style` attributes that set colour, background, border or outline | 735 of 737 (+6) | the other two are the badge partials' dynamic `style="{{ $style }}"` |
| Raw colour-variable references, `var(--…)` | 1,081 (+7) | by family: `--text-secondary` 189, `--text-primary` 183, `--border-base` 177, `--text-muted` 121, `--text-danger` 68, `--accent` 64, `--surface-input` 63, `--text-success` 35, `--surface-card` 35, `--surface-success` 26, `--surface-base` 24, `--border-success` 23, `--border-subtle` 14, `--border-danger` 14, `--surface-danger` 12, `--surface-muted` 10, `--surface-elevated` 9, `--text-info` 4, `--surface-info` 4, `--border-muted` 4, `--surface-accent` 3, `--surface-warning` 2, `--border-info` 2, `--text-warning` 1, `--border-warning` 1 |
| Literal colour values | 58 (+2) | `#fff` on legacy buttons 36 (+2); status, priority, overdue and internal-note hex 21; the invite modal's `rgba(0,0,0,0.5)` overlay 1. The time tracker's JavaScript strings carry legacy colour **variables**, not hex |
| Numeric Tailwind palette utilities | 1 | `bg-red-50`, the overdue queue row |
| Named palette utilities (`white`, `black`) | 0 | |
| Legacy accent | 64 `var(--accent)` + 7 `accent-legacy-accent` | |
| Dead `hover:legacy-bg-surface` (generates no CSS) | 25 (+1) | |
| `x-ui` visual overrides | — | measured once the components exist (guard B7) |

Per area (raw colour-variable references / inline style attributes / files): Helpdesk 308 / 218 / 9; Directory 264 / 188 / 12; Finance 308 / 189 / 8; System 199 / 142 / 9; `errors/403` 7 / 6 / 1.

### 3.4 Geometry census (target views)

| Measure | Baseline | Assessment (§12) |
|---|---|---|
| Radius | `rounded-lg` 169, `rounded-xl` 59, `rounded-full` 19, `rounded` 8 | controls and pills take their radius from the components; cards use 12px where Direction D §4.2 specifies 8px |
| Inline control geometry | `px-3`/`px-4`/`px-5` with `py-2` on 22 hand-built controls | replaced by `controlHeight` / `rounded-control` / `px-3` in the components |
| Field label / error rhythm | `mb-1`, `mb-1.5`, `mt-0.5`, `mt-1`, `mt-1.5` mixed | React forms use a `space-y-2` field group |
| Card padding | `p-5` 22, `p-6` 21 | ordinary layout; no Direction D card-padding contract |
| Table cell padding | `px-4 py-3` dominant (113 / 118); `px-6 py-4` in about 30 cells | Direction D §4.1 density belongs to the `DataTable` conventions |
| Page containers | seven widths (`max-w-lg` … `max-w-7xl`), all `px-4 py-10 sm:px-6 lg:px-8` | Direction D page frame (§9) |
| Arbitrary values | `min-h-[60vh]` (`errors/403`); inline `min-width:180px` (1) | §12.3 |
| Page title size | `text-2xl` (30) | already the Direction D `title` size (24px) |

### 3.5 Measured contrast (inherited, A13.4 plus this audit)

| Pair | Light | Dark |
|---|---:|---:|
| White on legacy `--accent` fill | 6.29 | 4.47 |
| Indigo link text on the page | passes | 3.28 (A13.4) |
| Priority pill `medium` / `high` / `low` / `critical` | 2.84 / 3.35 / 3.15 / 4.41 | same hex in both themes |
| Status pill `in_progress` / `pending` | 4.75 / 5.31 | same hex in both themes |
| `--surface-accent` / `--accent` (`open`, `Built-in`) | 5.78 | 3.28 |
| `--surface-muted` / `--text-muted` (invoice `draft`) | 2.49 | 2.35 |
| Overdue row: theme text on `bg-red-50` | — | 1.05 |
| Field edge `--border-base` on its fill | 1.41–1.47 | 1.72 |
| Reference: Direction D `control-edge` on `surface` | 3.59 | ≥ 3:1 (EPIC-013) |

### 3.6 Tests that pin the current legacy state

- `tests/Unit/Configuration/DirectionDThemeContractTest.php`:
  - requires `--accent`, `--accent-hover`, `--accent-text`, `--surface-accent` and the other legacy families in both theme blocks;
  - requires `--color-legacy-accent: var(--accent);` in the Tailwind exposure;
  - pins the WP1b mapping `--surface-accent: var(--surface-info)`.

  Retirement (§16) must rewrite these pins to assert **absence**. The pins must never simply be deleted.
- `tests/Browser/time-migration.spec.ts` drives the Blade-embedded time tracker on `/tickets/{id}` by accessible name (`/Start Timer/`). That name must survive.
- `components/time-tracker.blade.php`'s own script finds its status region with `card.querySelector('.flex.items-center.gap-2')`, a selector made of utility classes. Any class migration would silently break it, so PR 2 must move it to a `data-*` hook first (§9.9).
- `tests/Browser/blade-shell.spec.ts:472` already produces a deterministic 403: the member persona requests `/admin/users`, and `errors/403` renders inside the Blade shell. §18.4 reuses this.
- No Feature test asserts target-page styling, status label text or badge markup.

## 4. Locked owner decisions

| # | Decision |
|---|---|
| **O1** | **Primary actions are ink** (`bg-ink text-on-ink`) in both themes. They are never teal, green or indigo. Teal (`accent`, `accent-line`, `focus`) is for links, focus, selection and informational accent. Green is reserved for success semantics. No teal-filled primary button is designed. This supersedes A6.16's phrase "green/teal semantic control language". |
| **O2** | The epic is **EPIC-016**, *Direction D Theme and Control Adoption for Blade Workspaces*. This supersedes the WP0 draft title *… Control Adoption …*. Target areas: Helpdesk, Directory, Finance, System, plus `errors/403` as the required alias-retirement consumer. |
| **O3** | **Scope boundary: a presentation-system migration.** EPIC-016 corrects:<br>• controls;<br>• semantic colour across the target page bodies, including ordinary text, borders and surfaces;<br>• focus and accessibility;<br>• status presentation;<br>• Blade UI primitives;<br>• pagination;<br>• repeated product geometry;<br>• legacy token retirement.<br><br>It does **not** perform:<br>• Blade → React migration;<br>• route changes;<br>• information-architecture redesign;<br>• workflow redesign;<br>• business-rule or permission changes;<br>• Helpdesk, Directory, Finance or System product redesign;<br>• customer-shell redesign.<br><br>A page renders the same information and behaviour before and after. |
| **O4** | One epic delivered through **four implementation PRs**:<br>1. Controls and Accessibility;<br>2. Status and Semantic State;<br>3. Blade Theme Normalization;<br>4. Alias Retirement, Guard and Final Hardening.<br><br>This supersedes the WP0 draft's three-PR plan. |
| **O5** | **React is untouched by default.** Projects and Tasks are the visual reference. A change to a React primitive, or to an **existing** token value they consume, needs a proven shared defect and independent justification (§14). PR 3 may **add** a semantic theme token only under §11.4. |
| **O6** | The missing keyboard focus on the 44 field sites is **required scope**. Browser-default focus alone is not an accepted fix. |
| **O7** | `--accent` is **not** repointed. It serves two incompatible roles: the primary fill (Direction D: ink) and link text (Direction D: accent). Pointing it at `--ds-accent` would put the hard-coded white on `#7ADDE4` in dark, about 1.6:1. Pointing it at `--ds-ink` would turn every link into body text. Its consumers are migrated and the alias is then retired (§16). |
| **O8** | **Theme-first application styling.** In the target workspaces:<br>• application colours are expressed through **semantic Tailwind theme utilities**;<br>• literal colours are exceptional and documented;<br>• numeric palette utilities (`blue-500`, `red-50`, `amber-600`, `stone-200` …) are not used for application presentation when a semantic concept exists;<br>• page-local `style="color: …"` / `background` / `border-color` is not a normal styling mechanism;<br>• a raw legacy colour variable is replaced by its semantic utility wherever one exists;<br>• a genuinely reusable missing concept is added to the Tailwind v4 theme (§11.4) rather than scattered as literals.<br><br>Semantic meaning stays authoritative. This is not "make everything green", and not "hide every number behind a token" (§12). |
| **O9** | **`var(--…)` is not banned as a language feature.** Tailwind v4 is itself theme-variable based. The debt is application views styling themselves with raw, legacy or ambiguous **colour** variables instead of semantic utilities (§11.2). Token definitions, `@theme` mappings, primitive internals, genuine runtime values and documented exceptions are valid. |
| **O10** | **Approved rulings carried forward unchanged:**<br>• the accessible-name rules (§7.2 rule 4), including the three Finance placeholder-name changes;<br>• the field identity contract (§7.5);<br>• the invoice line-item index-collision repair as a narrow PR 1 integrity fix with a regression test (P8);<br>• the temporary glyph substitutes (P6, P11);<br>• the labelable-control definition and the `errors/403` browser case (§18). |

## 5. Scope

### 5.1 Required (the epic is not complete without these)

1. The Blade control layer (§7): `button`, `link`, `input`, `select`, `textarea`, `checkbox`, `label`, `field-error`, `status`, `priority`, `alert`, `tag`, and a Direction D pagination view.
2. Every target page's actions, fields, checkboxes and pagination migrated onto it (§13). This includes:
   - the 44 missing focus indicators;
   - the field identity contract (§7.5);
   - names for every unnamed control (§10.2);
   - the invoice line-item integrity repair.
3. Status, priority, overdue, internal-note, role-type, alert and live presentation migrated to the §9 semantic mapping.
4. **Theme normalization** of the four target workspaces and the `errors/403` consumer (§11): every application colour, including ordinary page-body text, borders and surfaces, becomes a semantic theme utility, a justified new semantic utility (§11.4), or a documented exception.
5. Repeated product-level geometry normalized where §12 says it should be. Ordinary layout utilities are left alone.
6. Retirement of the locked alias set (`--accent`, `--accent-hover`, `--accent-text`, `--surface-accent`, `.legacy-btn-accent`, `--color-legacy-accent`, the `--color-brand-*` scale), each after zero-use proof, and evaluation of the §16 candidates.
7. The permanent two-level guard (§17).
8. Validation per §18 and the exit criteria in §20.

### 5.2 Deferred (explicitly out of EPIC-016)

See [§22](#22-deferred-items-and-follow-ups). In short:
- renderer conversion, IA redesign, and product or domain changes in any module;
- React `Badge`'s legacy status variants, and the shadcn aliases that Projects consumes;
- `cms/*` (Resources) and other modules outside the four targets, except where shared alias retirement requires;
- the Direction D page frame (content widths and the `[data-page-frame]` contract) and the `DataTable` density conventions on Blade pages (P13, P15);
- broad typography redesign and arbitrary full-application layout redesign;
- `@shadcn/lint` evaluation for React (§22.1).

## 6. Design authority

The Blade layer mirrors the **semantic contract** of the React primitives, not their implementation. Sources: Direction D §2 (tokens and usage rules), §8 (selection), §10 (status), §14 (focus), §15 (states), and the React files named below.

| Role | Direction D utility | Light | Dark | React source |
|---|---|---|---|---|
| Primary action | `bg-ink text-on-ink` | `#1A1B1E` / white | `#E6EEEF` / `#0D1416` | `button.tsx` `primary` |
| Secondary action | `bg-surface border-control-edge text-text` | white / `#8B877C` | `#142023` / `#617679` | `button.tsx` `secondary` |
| Ghost / utility | `text-text hover:bg-surface-hover` | | | `button.tsx` `ghost` |
| Destructive confirm | `bg-destructive text-destructive-foreground` (= `--ds-danger`) | `#B42318` | `#FF8A7A` | `button.tsx` `destructive` |
| Destructive trigger | secondary + `border-danger text-danger` | | | `pages/projects/edit.tsx:221` |
| Field | `border-control-edge bg-surface text-text`, hover `border-text-muted`, invalid `border-danger`, placeholder `text-text-muted` | | | `input.tsx`, `native-select.tsx`, `textarea.tsx` |
| Disabled | `bg-surface-sunken text-text-muted border-rule(-control)`; never `text-faint` | | | same |
| Focus | `focus-visible:outline-2 outline-offset-2 outline-focus` | `#0B6A73` | `#19E7F2` | `control-metrics.ts` `focusRing` |
| Control heights | `h-8/9/10`, `pointer-coarse:` one step up | | | `control-metrics.ts` `controlHeight` |
| Checkbox | native, `accent-accent` | `#0B6A73` | `#7ADDE4` | `company-selector.tsx`, `login.tsx` |
| Link / info | `text-accent`, hover `text-accent-hover` | `#0B6A73` | `#7ADDE4` | `status.tsx` `info`, `alert.tsx` |
| Status | glyph + label + tone (`neutral/info/success/warning/danger/live`) | | | `status.tsx` |
| Priority | three bars + label; critical in `danger` | | | `priority.tsx` |
| Banner | `info/success/warning/danger/neutral`, each with a glyph and an `sr-only` kind; `danger` is `role="alert"`, the rest `role="status"` | | | `alert.tsx` |
| Tag | mono, uppercase, `rule-control` edge, `text-muted` | | | `tag.tsx` |
| Pagination | `nav aria-label="Pagination"`, secondary links, text position | | | `components/pagination.tsx` |

**Row-link grammar.** In the reference surfaces, a row's primary identifier is `text-text font-medium hover:underline` (`pages/projects/index.tsx:119`, `components/tasks/task-title-cell.tsx`). Quieter links are `text-text-secondary` with an underline (`pages/projects/show.tsx`). Teal link text appears where a link is a standalone affordance. §7.3 and [P2](#24-plan-level-choices-open-to-review) apply this split to Blade.

## 7. Blade component contract

### 7.1 Location and naming

- Anonymous Blade components under **`src/resources/views/components/ui/`**, used as `<x-ui.button>`, `<x-ui.input>` and so on. This mirrors the React `components/ui/` namespace, and follows the one existing Blade component convention (`views/components/time-tracker.blade.php` → `<x-time-tracker>`). No class-based components are planned: `app/View` holds only composers, and the components need no PHP logic beyond attribute handling.
- Domain mapping stays **outside** the primitives, as in React. The primitives know tones and glyphs, never tickets or invoices. The existing domain partials `tickets/_status_badge` and `tickets/_priority_badge` are kept as mapping partials that now emit `x-ui.status` / `x-ui.priority`. One new partial (working name `billing/_invoice_status`) replaces the invoice status map that is duplicated in four views.
- Tailwind already sources `../**/*.blade.php` (`app.css`), so component class strings compile with no CSS change.

### 7.2 Rules shared by every component

1. **Class ownership.** Colour, border, background, radius, height, padding and focus classes belong to the component. Callers may add **layout** classes only: width, margin, self-alignment, grid and flex placement, `sr-only`, `truncate`, text alignment, and responsive prefixes of these. Blade's `$attributes->class()` cannot resolve conflicting Tailwind utilities the way `cn()` / `tailwind-merge` does in React, so a caller-supplied colour class would produce an unpredictable cascade.

   The layout-only rule is a **contract**, enforced by review. Guard rule B7 (§17.2) adds a practical mechanical check in the target views, and catches **clearly visual** overrides on `<x-ui.*>` invocations:
   - literal class tokens with a colour, border, ring, outline, shadow, radius, opacity, owned-geometry or state/theme-variant prefix;
   - any `style=` attribute;
   - any `:class=` attribute (a bound expression cannot be checked statically, so a conditional appearance belongs in a component prop).

   B7 is a deny-list, not a Tailwind parser. A visual class outside its prefixes, an invocation outside the target paths, or an attribute bag forwarded programmatically is left to review. The guard does not claim more than that.
2. **Focus.** Every interactive component carries the exact `focusRing` string: `focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus`. No component uses `outline-none` or `focus:ring-*`. Transitions cover colour, background and border only, never `outline-color`. This is the same reason `control-metrics.ts` gives.
3. **Attributes pass through.** `type`, `name`, `value`, `required`, `form`, `data-*`, `aria-*` and event attributes are forwarded unchanged via `$attributes`, so form submission, `confirm()` handlers and existing selectors keep working.
4. **Existing names stay; missing names are added.** Two halves, neither weakening the other:
   - **Preserved.** Every control that has an accessible name today keeps it exactly, and visible control text does not change: `▶ Start Timer`, `Post Reply`, `Update Status`, search fields named by their placeholder, wrapped checkbox labels, and the rest. The only exception is the enumerated placeholder fallback below. Every existing role/name selector, including `time-migration.spec.ts`'s, keeps resolving.
   - **Added.** A control with no accessible name today gains one, in this order of preference (§10.2):
     1. from its **existing visible label**, by associating it (`for`/`id`);
     2. from **existing visible text** that is not a label, through `aria-labelledby`;
     3. only when nothing visible names it, through an **explicit `aria-label`** that states its purpose. This covers the invoice line-item `×` and the queue selection checkboxes and filters.

   Adding a name never renames a control that already had an authored name (its own text or value, a label, a wrapping label, `aria-label` or `aria-labelledby`).

   **The one enumerated exception (owner-approved)** is a *placeholder fallback*: a browser derives a computed name from `placeholder` only when nothing else names the field. Three Finance fields have such a fallback name **and** a visible label beside them, which is unassociated today (or, for added line-item rows, the P8 `sr-only` copy of the row-0 label). Associating that label replaces the placeholder text with the visible label text:
   - invoice line-item description: "Service or product description" → "Description";
   - unit price on JavaScript-added line-item rows: "0.00" → "Unit Price";
   - the payment `notes` field on `billing/invoices/show`: "e.g. Bank transfer" → "Notes".

   The placeholder stays visible as a hint, and the name now matches the visible label (WCAG 2.5.3, Label in Name). Fields named by a placeholder that have **no** visible label (the search fields and the reply textareas) keep their placeholder-derived name unchanged.
5. **No hex, no legacy variables, no inline `style` for colour.** Components use Direction D utilities only (Direction D §2.3.9).
6. **No new colour tokens.** Every contract below is expressible in the existing `--ds-*` layer. If implementation finds a gap, it stops and records it as an amendment, rather than adding a token silently.

### 7.3 Components

| Component | Variants / props | Replaces | Disabled | Invalid | Notes |
|---|---|---|---|---|---|
| `x-ui.button` | `variant`: `primary` (default), `secondary`, `ghost`, `destructive`; `tone="danger"` on `secondary`/`ghost` only (destructive **trigger**); `size`: `sm`, `md` (default), `lg`, `icon`; `href` renders an `<a>` with the same classes; `type` defaults to `button`, and the caller passes `type="submit"` explicitly | inline `var(--accent)` fills, `border-base` outline buttons, soft-danger and text-link deletes | `disabled` attribute on `<button>`; `aria-disabled="true"` plus no `href` on links; `bg-surface-sunken text-text-muted` | — | `icon` requires `aria-label`. Pest asserts the classes per variant. |
| `x-ui.link` | `variant`: `accent` (default; standalone links and row actions), `quiet` (`text-text-secondary`, underlined), `row` (row primary identifier: `text-text font-medium hover:underline`) | `style="color: var(--accent)"` | — | — | Carries `focusRing` with `rounded-control`. Figures that are not links are **not** links: they become plain text (§9.8). |
| `x-ui.input` | passes `type`; identity props `name`, `id`, `error-key`, `aria-describedby` per §7.5; `invalid` (bool) forces the invalid state | `outline-none` + `--surface-input` / `--border-base` | `disabled` → `bg-surface-sunken text-text-muted border-rule-control` | when any `error-key` has an error, or `invalid` is set: `aria-invalid="true"`, `border-danger`, and `{id}-error` merged into `aria-describedby` (§7.5) | `controlHeight.md`; covers `search`, `date`, `number`, `email`, `url`, `file` |
| `x-ui.select` | as `x-ui.input` | same | same | same | native `<select>` (keyboard and touch accessible, as `NativeSelect`) |
| `x-ui.textarea` | as `x-ui.input` | same | same | same | `min-h-24 resize-y` |
| `x-ui.checkbox` | identity props per §7.5 (a wrapped checkbox needs no `id`) | `accent-legacy-accent` | `disabled` keeps the label `text-text-muted` | as `x-ui.input` | native checkbox, `size-4 accent-accent` + `focusRing`. A checked state is a **selection** colour, never a primary-action colour. |
| `x-ui.label` | `for` = the field's **DOM id** (required unless it wraps its control); `sr-only` allowed as a layout class | `<label style="color: var(--text-muted)">` | — | — | `text-sm font-medium text-text`, as React `Label`. `required` renders the existing `*` marker in `text-danger` (replacing `<span style="color: var(--text-danger)">*</span>`); the field keeps its `required` attribute. |
| `x-ui.field-error` | `for` = the field's **DOM id**; `error-key` = the same key(s) the field uses (§7.5) | `@error … style="color: var(--text-danger)"` | — | renders `id="{for}-error"` only when an `error-key` has an error, showing the first message in key order | `text-sm text-danger`, `role="alert"`, as `FormFieldError` |
| `x-ui.status` | `tone`: `neutral`, `info`, `success`, `warning`, `danger`, `live`; `glyph`: `circle`, `half`, `dot`, `check`, `triangle`, `square`, `dashed` (the React set) | hex and legacy-variable pills | — | — | inline glyph (SVG, `aria-hidden`) + visible text label; never a filled pill |
| `x-ui.priority` | `bars` 1–3; `tone` `neutral` or `danger` | `tickets/_priority_badge` pastel pills | — | — | bars `aria-hidden`; the label is the signal |
| `x-ui.alert` | `variant`: `neutral`, `info`, `success`, `warning`, `danger`; optional `action` slot | page-local flash `div`s, all currently `role="alert"` | — | — | glyph + `sr-only` kind label; `danger` is `role="alert"`, the others `role="status"`, as React `Alert`. The glyphs are lucide's own path data (`info`, `circle-check`, `triangle-alert`, `circle-alert`, the icons React `Alert` imports), following the `layouts/partials/shell/icon.blade.php` precedent of copying lucide paths into Blade |
| `x-ui.tag` | — | `Built-in` / `Custom` role pills | — | — | mono uppercase marker (`Tag`), for kind and category, never state |

### 7.4 Pagination view

- A Blade paginator view registered as the application default in `AppServiceProvider::boot` via `Paginator::defaultView()` and `defaultSimpleView()` (working name `pagination/direction-d`). The nine `->links()` call sites then need no change.
- **Behaviour is preserved, not reduced.** The vendor view's affordances stay:
  - previous / next;
  - numbered pages with the ellipsis;
  - the "Showing x to y of z results" summary;
  - the compact mobile previous / next.

  Laravel generates the URLs exactly as today, so query strings behave as before. The React `Pagination` is prev/next only; matching it would remove navigation from Blade lists, so it is not copied (see [P5](#24-plan-level-choices-open-to-review)).
- Treatment:
  - `nav aria-label="Pagination"`;
  - links are `x-ui.button` `secondary` `sm` (or the same class string);
  - the current page is `aria-current="page"`, drawn as the selected segmented state (`bg-ink text-on-ink`, Direction D §8);
  - unavailable directions are omitted or `aria-disabled`, never fake controls;
  - the summary is `text-text-secondary tabular-nums`.
- After no Blade view uses the vendor view, the `@source` line for `vendor/laravel/framework/src/Illuminate/Pagination/resources/views` is removed from `app.css`. That removes the vendor `blue-*` and OS-driven `dark:` utilities from the bundle. Removing the line deletes compiled classes only; it changes no token.

### 7.5 Field identity contract

Four identifiers are related, and PR 1 implements this contract rather than inventing one.

| Identifier | Meaning | Source |
|---|---|---|
| `name` | the HTML form field name, which is what gets submitted | caller. Every existing field's `name` is unchanged. The one change is the index *seed* for JavaScript-added invoice line items (`max(keys) + 1`, below), which keeps the same `items[{i}][…]` pattern |
| `id` | the DOM id; the target of `label[for]` and the root of every derived id | derived from `name` for a simple name; **explicit** otherwise |
| `error-key` | the Laravel validation key(s) looked up in `$errors` | derived from `name` for a simple name; **explicit** otherwise |
| error element id | the `x-ui.field-error` element's id | always `{id}-error` |

**Rules**

1. **Simple names.** A name matching `^[A-Za-z][A-Za-z0-9_-]*$` (for example `title`, `client_id`, `first_name`) defaults to `id = name` and `error-key = name`. This equals current practice: every target field that has an `id` today uses its `name` as the id (`title`, `category`, `client_id`, `issued_at`, …). The two exceptions are `invite-email` (name `email`) and `select-all` (no name), and rule 3 keeps both. No target file repeats a field name, and no shell id (`shell-*`, `main-content`) can collide with one.
2. **Nested or array names** (`items[3][description]`, `attachments[]`, `permissions[]`) **must** pass an explicit, deterministic `id`, and, if the field can carry an error, an explicit `error-key`. A component given a non-simple `name` without an `id` throws an `InvalidArgumentException` at render. Pest proves that, so the mistake cannot reach a page.
3. **An explicit `id` always wins.** Existing ids that differ from the name, or that scripts depend on (`select-all`, `invite-email`), are passed through unchanged. Non-field ids that scripts use (`payment-element`, `line-items`, `bulk-bar`, …) are not touched by the components at all.
4. **`error-key` may be one key or an ordered list**, and each key may use Laravel's wildcard (`attachments.*`). For example, `['attachments', 'attachments.*']` catches both the array rule and the per-file rules. The field is invalid when **any** key has an error, and `x-ui.field-error` shows the **first** message in key order.
5. **`label[for]` targets the DOM id**, never the name.
6. **`aria-describedby` is merged, never replaced.** The rendered value is:
   - the caller's own tokens, in their order;
   - then `{id}-error` when the field is invalid;
   - de-duplicated and space-separated.

   Existing visible hint text ("Up to 10 files, 20 MB each.") gets `id="{id}-hint"`, and the caller passes that id, so hints become associated without their text changing.
7. **Uniqueness.** One page renders each DOM id once. A page that legitimately shows the same simple name twice (none does today) must give the second instance an explicit `id`. Pest checks every target form's rendered response for duplicate ids.
8. **Checkbox groups** (`permissions[]`, `roles[]`):
   - each checkbox stays wrapped in its existing `<label>`, which already names it, so it needs no `id`;
   - the group container gets an explicit `id`, `role="group"` and `aria-labelledby` pointing at the group's existing heading;
   - a group error uses `error-key` `['permissions', 'permissions.*']` (or the `roles` equivalent), with its field-error rendered once after the group as `{groupId}-error`, and referenced from the group container's `aria-describedby`.

**Invoice line items (`billing/invoices/_form`)**

| Field | `name` | `id` | `error-key` |
|---|---|---|---|
| Description | `items[{i}][description]` | `items-{i}-description` | `items.{i}.description` |
| Quantity | `items[{i}][quantity]` | `items-{i}-quantity` | `items.{i}.quantity` |
| Unit price | `items[{i}][unit_price]` | `items-{i}-unit_price` | `items.{i}.unit_price` |

- `{i}` is the row's array key. Server-rendered rows use the keys of `old('items', …)` exactly, so after a validation failure every error lines up with the row that caused it. The current markup shows an error only for `description`; quantity and unit price gain their field-error too, with the same keys.
- **New rows** clone one server-rendered `<template>` built from the same components, with the literal placeholder `__INDEX__` in `name`, `id`, `label[for]` and `aria-describedby`. The script replaces `__INDEX__` on clone. A template row never carries an error.
- **The next index is `max(existing keys) + 1`, never the row count.** Today the script starts from `count($existingItems)`. After a validation failure whose submission had a removed middle row (keys `0, 2`), that reuses key `2`: the added row would duplicate both the DOM id and the submitted `items[2][…]` name, and the two would collide. The new rule is required for id uniqueness, and incidentally prevents that collision. Submission names, and how existing rows submit, are otherwise unchanged.
- Each row's labels:
  - row 0 keeps the visible column labels;
  - every other row has the same label text as `sr-only` `x-ui.label`s (P8), so every row's fields are named;
  - the remove button is `x-ui.button variant="ghost" tone="danger" size="icon"` with `aria-label="Remove line item"`.

## 8. Action hierarchy rules

1. **One ink primary per region** (Direction D §2.3.5). A page header has at most one primary action (for example `New invoice`). A form region's submit is its primary.
2. **Search and Filter submits are secondary.** Today they are indigo fills on `crm/*/index` and `billing/invoices/index`, and an outline on `operator/tickets/index`. All of them become `secondary`.
3. **Region-local updates and applies are secondary** when the page already has a primary. On `operator/tickets/show`, `Post Reply` stays primary in the conversation region, and `Update Status` / `Update Assignee` in the context rail become `secondary`. On `billing/invoices/show`, the region's main lifecycle action (`Mark as Sent` or `Record Payment`) stays primary, and the rest become secondary. The same rule covers the queue bulk-bar `Apply`.
4. **Destructive trigger and destructive confirm are different semantics.**
   - The on-page **trigger** is `secondary tone="danger"`, as `projects/edit.tsx:221` draws it. Row-level text actions (`Delete`, `Remove`) use `ghost tone="danger"`.
   - The **confirm** step remains the existing native `confirm()`, unchanged (no workflow change, §21).
   - The solid `destructive` variant exists for contract parity and future dialogs. EPIC-016 introduces no Blade confirmation dialog.
5. **Never normalize by colour.** A destructive action never becomes ink or teal. A neutral utility (`Clear`, `Back`, `Reports`) stays secondary or ghost. Nothing becomes green.
6. **Consequential non-destructive confirms** (`Promote … to an Organization`, `Publish`) keep their `confirm()` and are secondary or primary by region, never danger.

## 9. Status and semantic mapping

The tables below cover every status the target views render. Tones follow Direction D §10 exactly for every domain §10 defines (tickets, priority, invoices, overdue, internal note). CMS page state (§9.6), which §10 does not cover, is a plan choice in the same pattern.

Glyphs come from the shared `Status` vocabulary (`circle`, `half`, `dot`, `check`, `triangle`, `square`, `dashed`). Direction D draws three glyphs that vocabulary does not have: an **hourglass** (pending), an **arrow** (invoice sent) and a **clock** (overdue). Each of those three rows uses a recorded, **temporary** substitute, under [P6](#24-plan-level-choices-open-to-review) and [P11](#24-plan-level-choices-open-to-review), marked "substitute" below. They are **not** Direction D glyph parity, and the real glyphs are deferred to the shared cross-renderer vocabulary (§22). Every other row matches Direction D. Labels keep their current text (§7.2 rule 4).

### 9.1 Ticket lifecycle (Direction D §10.4)

| Status | Tone | Glyph | Note |
|---|---|---|---|
| `open` | `info` | `circle` | was indigo on blue-50 |
| `in_progress` | `info` | `half` | was `#2563EB`; Open and In progress share the colour and differ by glyph and label |
| `pending_user` | `neutral` | `dashed` (**substitute**) | Direction D §10.4 draws an hourglass, which the shared glyph set does not have (see [P6](#24-plan-level-choices-open-to-review)) |
| `resolved` | `success` | `check` | was green-600 on green-50 |
| `closed` | `neutral` | `check` | |

### 9.2 Priority (Direction D §10.3)

`low` 1 bar, `medium` 2, `high` 3, all `neutral`. `critical` is 3 bars in `danger` with the label in `danger`. The pastel pills are removed in both themes.

### 9.3 Invoice lifecycle (Direction D §10.5)

| Status | Tone | Glyph | Direction D §10.5 |
|---|---|---|---|
| `draft` | `neutral` | `dashed` | dashed hollow, muted: **matches** |
| `sent` | `info` | `half` (**substitute**) | accent **arrow**: not in the shared vocabulary ([P11](#24-plan-level-choices-open-to-review)) |
| `paid` | `success` | `check` | success check: **matches** |
| `overdue` | `danger` | `square` (**substitute**) | danger **clock**: not in the shared vocabulary ([P11](#24-plan-level-choices-open-to-review)) |
| `cancelled` | `neutral` | `circle` | muted, with the amount struck through where the row shows it ([P7](#24-plan-level-choices-open-to-review)); no glyph specified |

Tones match §10.5 for every status. The two substitutes are deliberate and temporary (P11): each keeps the invoice column's five glyphs distinct, and each is the shared default for its tone. The same mapping serves the operator and the client views through the one `billing/_invoice_status` partial. `Invoice::STATUSES` is unchanged.

### 9.4 Overdue (ticket SLA, invoice due date)

- The ticket queue row loses `bg-red-50`. Direction D §2.3.6 keeps soft tints for selection, running rows and banners. The SLA cell shows the due date in `text-danger font-medium` plus `x-ui.status tone="danger"` "Overdue", with the `square` glyph as the P11 substitute for Direction D's clock (Direction D §10.2, and §10.4 "SLA breach").
- The hex `Overdue` pill on `operator/tickets/show` becomes the same status.
- Invoice due dates already switch to the danger colour; they move to `text-danger`.

### 9.5 Internal note (Direction D §10.5)

The note card uses:
- a dashed `border-warning-glyph`;
- `bg-warning-soft`;
- a lock glyph (lucide `lock` path data, following the `icon.blade.php` precedent; this is an icon, not a `Status` glyph, so it is no substitution) with the existing "Internal Note" label in `text-warning`.

This replaces `#fef3c7`, `#92400e`, `#d97706` and `#fffbeb` in both ticket detail views.

### 9.6 CMS page state (`operator/cms/index`, `operator/cms/edit`)

`Published` → `success` / `check`; `Draft` → `neutral` / `dashed`. Direction D §10 defines no CMS states, so this follows the invoice `paid` / `draft` pattern and is not a spec quotation. These replace the `--surface-success` / `--surface-input` pill. The CMS publish rules are unchanged.

### 9.7 Role type and flash messages

- `Built-in` / `Custom` on `admin/roles/*` become `x-ui.tag`. They are a kind, not a state, and are no longer accent-coloured.
- The organization member role pill on `organizations/show` (`admin` in warning colours, `member` on `--surface-input`) becomes `x-ui.tag`. A role is a kind, not a warning.
- MFA "Enabled" on `admin/users/show` becomes `x-ui.status tone="success"`.
- The payment ledger's `+amount` on `billing/invoices/show` uses `text-success`. The Stripe page's error region (`#payment-message` on `billing/payment/show`) becomes `x-ui.alert variant="danger"`, keeping its id for the script.
- Flash success becomes `x-ui.alert variant="success"` (`role="status"`). Flash errors become `variant="danger"` (`role="alert"`).

### 9.8 Figures and informational accent

- Numbers that are not links (the report counts on `operator/tickets/reports`) are plain `text-text` in mono with tabular figures (Direction D §3.3). Accent is not decoration.
- `✓ Paid {date}` on invoice detail uses `text-success`. This is legitimate success semantics on the Direction D token.

### 9.9 Embedded time tracker (`components/time-tracker`, on `tickets/show`)

- Running indicator: `live` dot + label in `text-live-text` (Direction D §2.3.1: running is `live`, not `success`).
- `▶ Start Timer` and `Stop`: `x-ui.button` `secondary` `sm`, with the same visible text. Stopping a timer is not destructive, so `Stop` loses its danger colour.
- The script currently rebuilds this markup through `innerHTML` strings, which contain inline colour styles. It also locates its status region with the utility-class selector `.flex.items-center.gap-2`. Both change:
  - the script finds its parts through `data-*` hooks, never utility classes;
  - it toggles server-rendered states, or clones server-rendered `<template>` markup, so the classes come from the components and no colour lives in a JavaScript string.

  `time-migration.spec.ts` must stay green unchanged, and the button names `▶ Start Timer` and `Stop` are preserved.
- Timer behaviour (EPIC-011D endpoints, reconciliation) is unchanged.

## 10. Accessibility contract

### 10.1 Required properties of every migrated control

| Property | Requirement | How it is measured |
|---|---|---|
| Visible focus | 2px solid outline in `--ds-focus`, offset 2px, on `:focus-visible`, for every field, button, link, checkbox and pagination link in the target views | Playwright: keyboard `Tab` through `main`; computed `outline-style: solid`, `outline-width: 2px`, `outline-color` equals the theme's focus token |
| Focus contrast | the focus colour reaches ≥ 3:1 against the surfaces a control sits on | asserted once per theme against `canvas` and `surface` |
| Non-text contrast | field, select, textarea and secondary-button boundaries use `control-edge` (≥ 3:1 by EPIC-013 measurement) | computed `border-color` equals `control-edge` |
| Text contrast | button labels, link text, status labels and field labels ≥ 4.5:1 in both themes | Playwright contrast computation on representative routes |
| Names (preserved) | every control with an authored name today keeps that exact accessible name, and visible control text is unchanged. Placeholder-derived names are kept, except the three enumerated fields whose visible label becomes the name (§7.2 rule 4) | the existing specs, unedited; Playwright role/name lookups of the pre-epic names |
| Names (added) | every user-facing labelable control (§18.3) has an accessible name, gained by the §10.2 rules: existing visible label, then existing visible text, then an explicit `aria-label` only where nothing visible names it | Pest render assertions; Playwright `getByLabel` / `getByRole(…, { name })` per control |
| Errors | a field whose `error-key` has a server error has `aria-invalid="true"`, and its `aria-describedby` contains `{id}-error` plus every token the caller supplied (§7.5) | Pest |
| Disabled | `disabled` (button, field) or `aria-disabled` (link); label or value stays `text-text-muted`, never `text-faint` | Pest classes |
| Keyboard | every interactive control is a native `button`, `a[href]`, `input`, `select` or `textarea` in DOM order; no new composite widgets | review + Playwright Tab order |
| Status | glyph + text + tone; colour never the only signal | Pest mapping tests |

### 10.2 Naming inventory (controls with no accessible name at `d87b5b1`)

| Case | How the name is gained | Controls |
|---|---|---|
| (a) A visible label exists but is not associated | `x-ui.label for="{id}"` (§7.5): the existing visible text becomes the name | `crm/contacts/_form` (7), `crm/companies/_form` (5), `operator/cms/_form` (3), `billing/invoices/_form` row 0 (3) and its header fields, `tickets/create` attachments, `operator/tickets/reports` date range (2), `billing/invoices/show` payment fields (2) |
| (b) Visible text exists but is not a label | `aria-labelledby` pointing at that existing text | `operator/tickets/show`: the `status` and `assignee_id` selects, named by their existing "Status" and "Assignee" headings |
| (c) Nothing visible names the control | an explicit `aria-label` stating its purpose; visible layout unchanged | invoice line-item `×` → "Remove line item"; line-item rows after the first → `sr-only` copies of the row-0 labels (P8); queue select-all → "Select all tickets on this page"; queue row checkbox → "Select ticket {number}"; queue filters `status`, `priority`, `assignee`, `date_from`, `date_to` → "Status", "Priority", "Assignee", "Submitted from", "Submitted to"; bulk bar `action`, `assignee_id` → "Bulk action", "Assign to"; `tickets/index` and `billing/invoices/index` status filters → "Status"; reply `attachments[]` on both ticket detail views → "Attachments" (and `aria-describedby` → the existing hint); `organizations/show` `user_id`, `role` → "Person to add", "Organization role" |

- **Unchanged:** controls that already have a name: the search fields and reply textareas named by their placeholder (they have no visible label), wrapped checkboxes (`is_internal`, `permissions[]`, `roles[]`), and every button and link with visible text.
- **Placeholder fallback replaced by the visible label** (the §7.2 rule 4 exception, exactly three fields): invoice line-item description, the unit price on JavaScript-added rows, and the payment `notes` field. These are case (a) associations; their placeholders remain as hints.
- PR 1 re-takes this census. A control found unnamed later is named by the same rules, in the same order, and recorded in the amendment.
- The exact `aria-label` wording is fixed in PR 1 review. Case (c) is the only place new wording appears.

### 10.3 Claim boundary

EPIC-016 asserts the criteria above **only for the target views listed in §13, plus `errors/403`**. It does not claim WCAG conformance for the product. Screen-reader matrices, real-device and cross-browser work stay with FINAL HARDENING, as in EPIC-013 and EPIC-015. Customer-facing target pages (`/tickets*`, `/my/invoices*`, the payment page) are inside the boundary.

## 11. Theme normalization

### 11.1 End state, and what "normalized" means

For the four target workspaces and the `errors/403` consumer, **colour normalization is complete when every application presentation colour is one of**:

1. an **existing semantic Tailwind theme utility** (the Direction D layer: `text-text`, `bg-surface`, `border-rule`, `text-danger` …), used directly or through an `x-ui.*` component;
2. a **justified new semantic theme utility**, added under §11.4;
3. a **documented explicit exception** (§11.3 category C), listed in the guard's allowlist with its reason.

A raw legacy colour variable is **not** normalized merely because its value happens to equal a Direction D token. Light and dark differences come from the theme tokens, never from per-page colour declarations. This is a required exit criterion (§20 #11 and #12).

### 11.2 Valid and invalid `var(--…)` (O9)

| Valid (not debt) | Target-view debt |
|---|---|
| token definitions in `app.css` (`--ds-*` and the compatibility layer) | `style="color: var(--text-primary)"` → `text-text` |
| Tailwind `@theme` / `@theme inline` mappings | `style="color: var(--text-secondary)"` → `text-text-secondary` |
| implementation internals of a shared primitive, where a utility cannot express the value | `style="border-color: var(--border-base)"` → the correct semantic border (`border-rule`, `border-rule-control` or, on a control, `border-control-edge` via the component) |
| a genuinely dynamic runtime value that no static utility can express (for example a computed width or a server-supplied colour), documented | `style="background-color: var(--surface-card)"` → `bg-surface` |
| an explicitly documented external, third-party or brand exception | any other legacy colour variable used to style an application view |

### 11.3 Classification of the colour census

Every colour-related use from §3.3 falls into one category. **A is the default** for ordinary application presentation, and raw variables are never kept merely to reduce diff size.

**A. Existing semantic theme utility.** This is the whole census; the table is the mechanical mapping. Semantic distinctions are preserved, not flattened.

| Legacy | Semantic utility | Where the decision is made |
|---|---|---|
| `--text-primary` | `text-text` | PR 3 (PR 1/2 where the element is a control or status) |
| `--text-secondary` | `text-text-secondary` | PR 3 |
| `--text-muted` (information-bearing in these views) | `text-text-muted` (≥ 4.5:1; fixes A13.4) | PR 1 (labels), PR 2 (status maps), PR 3 (the rest) |
| `--text-danger` / `--text-success` / `--text-warning` | `text-danger` / `text-success` / `text-warning` (field errors, delete triggers, the overdue date, `✓ Paid`, `+amount`, required `*` markers) | PR 1 / PR 2 |
| `--text-info` | `text-accent` (informational) | PR 2 |
| `--surface-card`, `--surface-base` | `bg-surface` | PR 3 |
| `--surface-elevated`, `--surface-muted` (table headers, sunken panels) | `bg-surface-sunken` | PR 3; the `draft` pill in PR 2 |
| `--surface-input`, `--border-base` on a control | `bg-surface`, `border-control-edge`, via the components | PR 1 |
| `--border-base`, `--border-subtle`, `--border-muted` on panels, cards, rows and dividers | `border-rule` / `divide-rule`; `border-rule-control` for the table-header underline (Direction D §4.3) | PR 3 |
| `--surface-danger` / `--border-danger` | `bg-danger-soft` / `border-danger` (banners via `x-ui.alert`; danger-zone containers) | PR 1 / PR 2 |
| `--surface-warning` / `--border-warning` | `bg-warning-soft` / `border-warning-glyph` (internal note, warning banner) | PR 2 |
| `--surface-info` / `--border-info` | `bg-accent-soft` / `border-accent-line` (informational) | PR 2 |
| `--surface-success` / `--border-success` | `x-ui.alert variant="success"` and `x-ui.status`. Direction D deliberately has no success-soft surface: React `Alert` draws success on `surface` with a `rule-control` edge, and Blade matches it | PR 2 |
| `--accent`, `--surface-accent` | `x-ui.button` (ink), `x-ui.link` (accent), `x-ui.status`, `x-ui.tag` | PR 1 / PR 2 / PR 4 (`errors/403` lands in PR 3) |
| `#fff` on legacy buttons | `text-on-ink` via `x-ui.button` | PR 1 |
| status, priority, overdue and internal-note hex | `x-ui.status`, `x-ui.priority`, warning tokens | PR 2 |
| `bg-red-50` overdue row | removed; danger text + status (§9.4) | PR 2 |
| `rgba(0,0,0,0.5)` invite-modal overlay | `bg-scrim` (Direction D: modal dialogs only) | PR 3 |

Semantic distinctions are kept intact:
- **ink** for primary actions;
- **accent** for links and information;
- **focus** for focus;
- **accent-soft / accent-line** for selection;
- **success**, **warning**, **danger** for state;
- **live** for running;
- **text / text-secondary / text-muted** for the text hierarchy;
- **rule** (structure) apart from **control-edge** (interactive boundaries).

A semantic utility being available does not make it right everywhere. Each replacement keeps the role the legacy value played.

**B. Missing reusable semantic concept.** **None found.** Every legacy role has a Direction D utility. The absence of a success-soft surface is a Direction D decision, not a gap. §11.4 governs any concept PR 3 discovers.

**C. Legitimate exception.** **None required in the target views at baseline.**
- The Stripe Payment Element is a third-party iframe styled by Stripe's defaults. Nothing in the view's markup styles it, so it is outside the guard rather than an exception.
- The badge partials' dynamic `style="{{ $style }}"` disappears in PR 2.

Any exception PR 3 finds is recorded with its reason in the guard allowlist and the amendment.

**D. Not a colour concern.** Left alone by colour normalization; geometry is handled in §12:
- `min-h-[60vh]` and the inline `min-width:180px`;
- `animate-pulse`;
- spacing, sizing and radius utilities.

### 11.4 Theme extension policy

EPIC-016 may extend the Tailwind v4 semantic theme, but only when all of these hold:

1. **No existing token fits.** No existing Direction D token expresses the concept. The existing vocabulary to check first: `canvas`, `surface`, `surface-sunken`, `surface-hover`, `surface-selected`, `rule`, `rule-control`, `rule-strong`, `control-edge`, `text`, `text-secondary`, `text-muted`, `text-faint`, `accent`, `accent-soft`, `accent-line`, `live`, `live-soft`, `live-text`, `ink`, `on-ink`, `danger`, `danger-soft`, `warning`, `warning-glyph`, `warning-soft`, `success`, `success-glyph`, `focus`, `scrim`.
2. **It is genuinely reusable.** The concept appears in more than one place, or is clearly an application-level semantic concept.
3. **The name describes meaning**, never the current colour. `blue-action`, `gray-border` and `finance-green` are rejected unless they are genuinely the domain meaning.
4. **Both themes are defined.** Light **and** dark values are set intentionally, in the `--ds-*` theme blocks, and exposed through `@theme inline` like every other Direction D token.
5. **It earns its place** by improving reuse or removing duplicated raw styling.

The introducing PR also:
- documents the token in the Direction D specification (§2.2 table plus a forward note);
- adds the §18.3 theme-token contract test;
- records the justification in its amendment.

**An existing token's value never changes in EPIC-016** (O5, §14). Expected outcome: **zero new tokens** (§11.3 B).

### 11.5 Light and dark parity

| Problem | Theme | Resolution | PR |
|---|---|---|---|
| Indigo action fills and link text (`--accent`) | both | `x-ui.button` / `x-ui.link` | 1 |
| Vendor pagination `dark:` follows `prefers-color-scheme` | both (theme ≠ OS) | Direction D pagination view on `data-theme` tokens | 1 |
| Field edges under 3:1 | both | `control-edge` via the field components | 1 |
| Pastel status and priority pills on the dark canvas | dark | `x-ui.status` / `x-ui.priority` | 2 |
| Overdue row 1.05:1 | dark | row tint removed; danger text + status | 2 |
| Internal-note hex | dark | warning tokens | 2 |
| `--text-muted` used as text (1.94:1 dark) | both | `text-text-muted` | 1–3 |
| Gray-950 / gray-800 cards and table wrappers against the teal-black shell; legacy gray text and rules on Direction D surfaces | both (most visible in dark) | §11.3 A mapping: `bg-surface`, `bg-surface-sunken`, `border-rule`, `text-text` / `text-text-secondary` | 3 |

Every row is verified in both themes by the §18.4 browser checks. In light mode, PR 3 moves page bodies from Tailwind gray to the warm Direction D neutrals. That is the intended per-surface adoption (Direction D §2.4.4), not a regression.

## 12. Spacing, layout and product geometry

### 12.1 Ordinary layout

Ordinary Tailwind utilities are already theme-driven and idiomatic: `gap-4`, `px-5`, `py-3`, `space-y-4`, `max-w-3xl`, `grid-cols-2`. They stay as they are. EPIC-016 does **not** create a semantic token for every number, and does not normalize one-off layout for purity.

### 12.2 Product-level UI contracts (recovered, not invented)

| Concept | Existing convention | Applied by | PR |
|---|---|---|---|
| Control height | `controlHeight` (`h-8/9/10`, a step taller under `pointer-coarse:`) | the `x-ui` components | 1 |
| Control horizontal padding, radius | `px-3` (`px-2.5` small, `px-4` large), `rounded-control` 5px / `rounded-control-lg` 7px (Direction D §4.2) | the `x-ui` components | 1 |
| Checkbox radius | 4px (Direction D §4.2) | `x-ui.checkbox` | 1 |
| Focus offset | 2px outline, 2px offset (`focusRing`) | the `x-ui` components | 1 |
| Field label / control / error rhythm | `space-y-2` field group (React forms: `projects/create.tsx`, `projects/edit.tsx`) | field markup rewritten in PR 1 | 1 |
| Status and tag geometry | glyph + label inline; `Tag` `rounded-tag` | `x-ui.status`, `x-ui.tag` | 2 |
| Card radius | **8px** for cards (Direction D §4.2); today's Blade cards use `rounded-xl` (12px) | `rounded-xl` → `rounded-lg` (Tailwind's 8px), an ordinary utility equal to the convention ([P14](#24-plan-level-choices-open-to-review)) | 3 |
| Modal overlay | `bg-scrim` (§11.3) | invite modal | 3 |

### 12.3 Arbitrary and inline geometry

- Inline `min-width:180px` (operator queue search) → `min-w-45`, the ordinary scale value (45 × 4px). PR 3.
- `min-h-[60vh]` (`errors/403`) stays. It is a one-off viewport proportion with no scale equivalent, which makes it category D and allowed by the guard.

### 12.4 Deliberately not normalized

| Geometry | Why not |
|---|---|
| Card padding (`p-5` / `p-6`) | no Direction D card-padding contract; ordinary layout |
| Table cell density (`px-4 py-3` vs `px-6 py-4`) | Direction D §4.1 dense rows arrive with the `DataTable` conventions, which belong to the module product epics ([P15](#24-plan-level-choices-open-to-review)) |
| Page containers (seven `max-w-*` widths) | adopting the Direction D page frame (`[data-page-frame]`, the §9 reading widths) changes every page's content width and is page-structure work ([P13](#24-plan-level-choices-open-to-review)) |
| Typography scale | page titles already use the Direction D `title` size; broad typography redesign is a non-goal |

## 13. Target-area migration inventory

Legend for the counts: *fill* = `var(--accent)` fills · *acc* = `var(--accent)` text · *chk* = legacy checkboxes · *nofocus* = `outline-none` fields with no focus style · *ring* = `focus:ring-2` fields · *lbl* = unassociated labels · *mute* = `--text-muted` text · *dead* = dead hovers · *pg* = pagination. "Cust." marks pages that customer members reach. The last column lists notable debt; **PR 3 normalizes every remaining colour on every listed view** (§11), not only what that column names.

### 13.1 Helpdesk

| View | Controls (PR 1) | Status semantics (PR 2) | Accessibility defects | Notable page-body debt (PR 3) |
|---|---|---|---|---|
| `tickets/index` (Cust.) | `New Ticket` primary; search and status filter (1 nofocus, 1 ring); `View` link; pg | status, priority partials | no focus (1) | dead 3 |
| `tickets/create` (Cust.) | 5 fields (3 nofocus, 2 ring, 1 lbl); required `*` markers via `x-ui.label required`; submit primary | — | no focus (3); 1 label | dead 1 |
| `tickets/show` (Cust.) | reply textarea (ring), internal checkbox (wrapped, already named), file input (`attachments[]`: explicit `id`, `error-key` `['attachments', 'attachments.*']`, named "Attachments", hint associated), `Post Reply` primary | status, priority; internal note (hex) | 1 label; unnamed file input | dead 2 |
| `tickets/_status_badge`, `tickets/_priority_badge` | — | rewritten as mapping partials (§9.1, §9.2) | contrast 2.84–3.35 | — |
| `operator/tickets/index` | search, 4 selects and dates (4 nofocus, 1 ring); select-all (`id="select-all"` kept) and row checkboxes (`ticket_ids[]`, explicit ids; 2 chk); bulk `Apply` secondary; `View`; pg | status, priority, overdue row | no focus (4); 9 unnamed controls (filters, bulk selects, selection checkboxes; §10.2 case c); dark overdue 1.05 | dead 4 |
| `operator/tickets/show` | reply (ring), internal checkbox, file input (as on `tickets/show`), `Post Reply` primary, `Update Status` / `Update Assignee` secondary; the two selects named through `aria-labelledby` by their existing headings | status, priority, Overdue pill, internal note | 1 label; 3 unnamed controls | dead 2 |
| `operator/tickets/reports` | date filters (existing labels associated), export | figures (§9.8) | 2 labels | dead 2 |
| `components/time-tracker` (via `tickets/show`) | start and stop buttons (§9.9) | running = `live` | — | `--text-muted` 1 |

### 13.2 Directory

| View | Controls (PR 1) | Status (PR 2) | Accessibility defects | Notable page-body debt (PR 3) |
|---|---|---|---|---|
| `crm/contacts/index`, `crm/companies/index` | `+ New …` primary; search field (1 nofocus each); `Search` secondary; row links (including the success-coloured "Org ↗" → `x-ui.link`); pg | — | no focus (1 each) | mute 6 each |
| `crm/contacts/_form`, `crm/companies/_form` (create and edit) | 7 and 5 fields, all nofocus, all labels unassociated | — | no focus (12); labels (12); errors without ARIA | mute 7 / 5 |
| `crm/*/create`, `crm/*/edit` | submit primary; `Delete …` → `secondary tone="danger"` (`confirm()` kept); the danger-zone container's `--border-danger` → `border-danger` | — | — | — |
| `crm/contacts/show`, `crm/companies/show` | action and row links (`acc` 1 / 3); `Promote` secondary (`confirm()` kept); the success-coloured "View Organization ↗" outline link → `x-ui.button variant="secondary" href` | — | — | mute 4 / 6 |
| `organizations/index` | `Promote a company` link; pg | — | — | mute 6 |
| `organizations/show` | add-member select and role select (2 nofocus; named "Person to add" and "Organization role"); `Add` primary; `Remove` → `ghost tone="danger"` | member role pill → tag (§9.7) | no focus (2); 2 unnamed selects | mute 7 |

### 13.3 Finance

| View | Controls (PR 1) | Status (PR 2) | Accessibility defects | Notable page-body debt (PR 3) |
|---|---|---|---|---|
| `billing/invoices/index` | `New Invoice` primary; search and status (2 nofocus); `Filter` secondary; number links (`row`); pg | invoice status (map 1 of 4) | no focus (2) | mute 7 |
| `billing/invoices/_form` (create and edit) | 12 fields + 1 in the JS template group (13 nofocus); line-item `×` → `ghost tone="danger" size="icon"` with `aria-label="Remove line item"`; `+ Add line item` ghost; line-item identity, errors, `<template>` and next-index rule per §7.5 ([P8](#24-plan-level-choices-open-to-review)) | — | no focus (13); labels only on row 0 and unassociated; unnamed `×` | mute 5 |
| `billing/invoices/create`, `…/edit` | submit primary | — | — | — |
| `billing/invoices/show` | `Mark as Sent` / `Record Payment` / `Pay Now` per §8.3; payment fields (2 nofocus, 2 lbl, existing labels associated); `Delete` → `secondary tone="danger"` | invoice status (map 2 of 4); `✓ Paid`; ledger `+amount` (§9.7) | no focus (2); labels (2) | mute 17 |
| `billing/client/index` (Cust.) | per-row `Pay Now` → `secondary sm`, because it repeats on every row (§8.1); number links; pg | invoice status (map 3 of 4) | — | mute 6 |
| `billing/client/show` (Cust.) | `Pay Now` primary | invoice status (map 4 of 4) | — | mute 9 |
| `billing/payment/show` (Cust.) | Stripe submit primary (Stripe Elements' iframe styling is out of scope) | `#payment-message` → danger alert (§9.7) | — | mute 3 |

### 13.4 System

| View | Controls (PR 1) | Status (PR 2) | Accessibility defects | Notable page-body debt (PR 3) |
|---|---|---|---|---|
| `admin/users/index` | `Invite User` primary; search field and the invite modal's field (2 ring); invite submit primary inside the modal; row links; pg. The hand-built `hidden` modal's behaviour (focus management, `Esc`) is **not** changed (§20) | — | — | dead 4 |
| `admin/users/show` | role checkboxes (1 chk); save primary | MFA "Enabled" → status (§9.7) | — | dead 1 |
| `admin/roles/index` | `New Role` primary; `Edit` link; `Delete` → `ghost tone="danger"` | `Built-in` / `Custom` → tag | — | dead 1 |
| `admin/roles/create`, `admin/roles/edit` | name field (`ring` on create); permission checkboxes (1 chk each, 43 rendered); save primary; edit `Delete` → `secondary tone="danger"` | `Built-in` tag on edit | — | dead 2 / 3 |
| `operator/cms/index` | `+ New Page` primary; links; pg | Published / Draft (§9.6) | — | mute 7 |
| `operator/cms/_form` (create and edit) | 3 fields (3 nofocus, 3 lbl) | — | no focus (3); labels (3) | mute 4 |
| `operator/cms/edit` | `Publish` / `Save` per §8.1; `Delete Page` → `secondary tone="danger"`; danger-zone container → `border-danger` | Published / Draft (§9.6) | — | mute 1 |

### 13.5 Totals and PR 3 load

PR 1 and PR 2 accounts:
- 44 no-focus field sites, 9 `ring` sites, 7 checkbox sites;
- 36 fills and 25 accent texts (plus `errors/403`: 2 + 1);
- 25 unassociated labels and about 30 unnamed controls (§10.2);
- 9 pagination sites;
- 3 status families plus priority;
- 8 destructive triggers, required-field `*` markers in three forms, and three danger-zone containers.

**PR 3** takes everything else. The figures below are raw colour-variable references remaining after PR 1 and PR 2, estimated by subtracting control, status, alert and error uses from §3.3:

| Group | Areas | Files | Remaining raw colour refs (approx.) | Also |
|---|---|---:|---:|---|
| Commit A | Helpdesk + Directory | 21 | ≈ 305 (Helpdesk ≈ 170, Directory ≈ 135) | dead hovers 20 |
| Commit B | Finance + System + `errors/403` | 18 | ≈ 240 (Finance ≈ 145, System ≈ 90, `errors/403` ≈ 4) | dead hovers 6; invite-modal scrim |
| Commit C | all target views | 39 | — | geometry only: card radius (59 `rounded-xl`), `min-w-45` |

That is roughly 545 references in about 450 `style` attributes across 39 files. It is mechanical, one-to-one through §11.3, with no markup, copy or behaviour change. Each PR re-takes the counts.

## 14. Projects and Tasks regression boundary

- **React is untouched.** No file under `src/resources/js/` changes in EPIC-016. If a PR finds a genuine shared bug, it stops and records an amendment, which needs owner approval.
- **`app.css` changes are tightly limited.**
  - PR 3 may **add** a semantic token only under §11.4 (expected: none).
  - PR 4 **deletes** the retired aliases (§16) and the vendor-pagination `@source` line (§7.4).
  - No existing `--ds-*` value, no `@theme inline` Direction D mapping, and no shadcn alias consumed by React changes: `--border` → `border-border` (23 React sites, 20 in `components/projects`), `--muted-foreground`, `--input`, `--surface-info` / `--text-info` (React `Badge`), `--accent-success` (`board-column.tsx`).
- **Reference checks.** PR 3 and PR 4 run representative comparisons on `/projects` and `/tasks` in both themes (§18.4):
  - computed colours of reference elements identical before and after;
  - primary action computed as ink;
  - no indigo or blue hues;
  - the field focus outline equal to the focus token;
  - the existing Projects/Tasks specs green unchanged.

## 15. Cross-application impact

| Consumer | Inherits | Classification |
|---|---|---|
| `errors/403` (shown from any workspace) | 2 `--accent` fills, 1 `--accent` text, 1 dead hover, legacy text colours | **Required consumer** (O2): accent sites in PR 1 / PR 3, colour normalization in PR 3 commit B; it blocks `--accent` retirement |
| `cms/index`, `cms/show` (Resources workspace, customer-facing) | legacy `--surface-card`, `--border-base`, `--text-*` (about 12 uses); no `--accent`, no fields, no pagination | **Not migrated** (outside the four targets); inside guard Level A, which they already pass; their colour normalization and `--text-muted` contrast are a **follow-up** for Knowledge/CMS evolution. They keep `--surface-card` and `--border-base` alive |
| `layouts/app.blade.php`, `layouts/partials/shell/*` | already Direction D utilities only | **Safe, unaffected**; inside Level A and passing |
| `welcome.blade.php` | unrouted Laravel stub with its own inlined CSS | **Safe, unaffected**; excluded from both guard levels |
| Mail and notification views | none in `resources/views` (framework defaults) | **Safe, unaffected** |
| React Profile, Time, Home, Auth | none of the locked retirements; `Badge` uses the status families; three `legacy-text-primary` uses | **Safe, unaffected**; `Badge` legacy variants are a **follow-up** (§22) |
| React Projects, Tasks | none of the locked retirements | **Safe, unaffected** (§14) |
| `html` / `body` base and the shadcn aliases in `app.css` | `--bg-base`, `--text-primary`, `--border-base`, `--text-secondary` … | **Unchanged**; they keep those raw families alive regardless of the target views |
| Laravel paginator default | every Blade `->links()`, which are exactly the 9 target views | **Required** (PR 1) |

No migration outside the target views is added silently. A newly discovered consumer becomes either a required consumer (it blocks a retirement) or an explicit follow-up, recorded in that PR's amendment.

## 16. Legacy alias retirement

Each retirement follows Direction D §2.4.7. An alias is removed only when all four hold, and the PR 4 amendment records the evidence:

1. **zero production consumers**, by a repository search of `resources/` and `app/`;
2. **zero target-view consumers**;
3. **no generated or runtime dependency**: no `@theme` mapping, utility, `app.css` rule or JavaScript reads it;
4. **its contract-test pin flipped** to assert absence.

An alias is never retired just because the plan wants it gone.

### 16.1 Locked retirements (consumer migration already scoped)

| Alias | Consumers at `d87b5b1` | Consumers cleared by | Contract-test change (PR 4) |
|---|---|---|---|
| `.legacy-btn-accent` | **0** (definition only) | — | none pinned |
| `--accent-hover`, `--accent-text` | only `.legacy-btn-accent` | with it | remove from the compatibility list; assert absence |
| `--color-legacy-accent` (`accent-legacy-accent` …) | 7 Blade checkbox sites | PR 1 | `toContain` → `not->toContain` |
| `--accent` | 61 target sites + 3 in `errors/403`; `.legacy-btn-accent`; `--color-legacy-accent` | PR 1, PR 2, PR 3 (`errors/403`) | remove from the list; assert absence in both themes |
| `--surface-accent` | 3 (status badge, `roles/index`, `roles/edit`) | PR 2 | drop the WP1b mapping pin; assert absence |
| `--color-brand-50 … 950` | only `--accent` / `--accent-hover` definitions; **no** `brand-*` utility anywhere | with `--accent` | assert absence |

### 16.2 Candidates (evaluated in PR 4; deletion **not** locked)

Full normalization is expected to leave these with no consumer. PR 4 retires each **only** if the four-part proof holds, and otherwise records why it stays.

| Candidate | Consumers today | Expected after PR 3 |
|---|---|---|
| `--surface-input` | the target views only (63) | zero |
| `--surface-base`, `--surface-elevated`, `--surface-muted`, `--border-muted` (EPIC-013 WP1b "orphans", pinned by `DirectionDThemeContractTest`) | the target views only | zero; retiring them flips the WP1b mapping pin |
| `--border-subtle` | `--border-muted` and `.legacy-border-subtle` only | zero, if both go |
| `.legacy-bg-surface` | only the 26 dead `hover:` uses | zero, once PR 3 removes the dead hovers |
| `.legacy-bg-base`, `.legacy-bg-elevated`, `.legacy-border-subtle`, `.legacy-border-base`, `.legacy-text-secondary`, `.legacy-text-muted`, `.legacy-text-inverse`, `.legacy-shadow-theme-sm/md/lg` | **already zero** | zero |

**Not candidates.** These keep consumers outside EPIC-016:
- `--surface-card`, `--border-base` and `--text-primary` / `--text-secondary` / `--text-muted`: `cms/*`, the `html`/`body` base, the shadcn aliases, and `.legacy-text-primary` (3 React uses);
- the `success` / `danger` / `warning` / `info` families: React `Badge` and React's `--text-danger` uses;
- `--bg-*`: the shadcn aliases and the base;
- `--accent-success`: Projects board;
- `--accent-foreground`.

## 17. Scoped guard

Implemented in **PR 4**, not in WP0 and not in PR 3. It is a Pest test in `tests/Unit/Configuration/` (working name `BladeThemeGuardTest`), modelled on the existing `DirectionDThemeContractTest` source scan. It protects the theme-first architecture (O8) **without banning `var(--…)` as such** (O9).

### 17.1 Level A — application-wide

**Scope.** All of `resource_path('views')` except `welcome.blade.php`, plus `resource_path('js')` without `*.test.*` files, plus `resources/css/app.css` for A4.

| Rule | Forbidden | Basis |
|---|---|---|
| A1 | the aliases **actually retired** by EPIC-016, in any syntax: the §16.1 set, plus each §16.2 candidate that PR 4 retires | §16; consumers proven zero |
| A2 | legacy accent and brand utilities: `legacy-accent`, `legacy-btn-accent`, `brand-50` … `brand-950` | retired |
| A3 | dead legacy utilities: `hover:legacy-*` and any `legacy-*` utility PR 4 retires | they generate no CSS, or no longer exist |
| A4 | the retired definitions in `app.css` | the absence pins, shared with `DirectionDThemeContractTest` |
| A5 | numeric `blue`, `indigo`, `violet`, `sky`, `purple` palette utilities in React application source | Direction D §2.1 (components consume semantic tokens only); zero hits today |

### 17.2 Level B — the four target workspaces and `errors/403`

**Scope.** An explicit path list:
- `tickets/`, `operator/tickets/`, `crm/`, `organizations/`, `billing/`, `admin/`, `operator/cms/`;
- `components/time-tracker.blade.php`, `components/ui/`, the pagination view;
- `errors/403.blade.php`.

Adding a path is a reviewed change. `cms/*`, the shell, `welcome` and React are outside it.

| Rule | Forbidden in target views | Approved replacement |
|---|---|---|
| B1 | numeric Tailwind palette colour utilities of any family (`slate`, `gray`, `stone`, `red`, `amber`, `green`, `blue`, `indigo`, `violet`, `sky`, `purple` …), with any colour-bearing prefix (`bg`, `text`, `border`, `ring`, `outline`, `divide`, `accent`, `fill`, `stroke`, `from`, `via`, `to`, `decoration`, `placeholder`, `caret`, `shadow`) and any variant; also the named `white` / `black` | semantic theme utilities |
| B2 | literal colour values (`#…`, `rgb(…)`, `hsl(…)`, `oklch(…)`) anywhere they set presentation: CSS declarations in `style`, PHP or JavaScript strings, and arbitrary class values `-[…]` | semantic theme utilities |
| B3 | **legacy colour variables**: `var(--…)` naming a legacy raw colour family (`--bg-*`, `--surface-*`, `--border-*`, `--text-*`, `--accent*`, `--danger`, `--success`, `--warning`, `--info`) and every retired alias. Other custom properties, such as `--ds-*` inside a documented primitive or a documented runtime value, are **not** forbidden by this rule | the §11.3 utilities |
| B4 | inline CSS **colour, background, border or outline** declarations (`color`, `background`, `background-color`, `border`, `border-*-color`, `outline`, `outline-color`, `accent-color`, `fill`, `stroke`) in `style` attributes, PHP style strings and JavaScript `.style.*` assignments | utilities and components; non-colour inline styles are allowed |
| B5 | `outline-none`, `outline-hidden`, `focus:outline-none`, `focus:ring-*` (the obsolete focus treatment) | the components' `focusRing` |
| B6 | retired and dead utilities: `legacy-accent`, `legacy-btn-accent`, `hover:legacy-*`, `legacy-*` | semantic utilities |
| B7 | **clearly visual overrides** on an `<x-ui.*>` invocation: a literal `class` token with a visual prefix (`bg-`, `border`, `ring`, `outline`, `shadow`, `rounded`, `divide-`, `accent-`, `fill-`, `stroke-`, `opacity-`), a `text-` token that is neither a size nor an alignment or wrapping keyword, an owned-geometry token (`h-`, `min-h-`, `size-`, padding), a state or theme variant (`hover:`, `focus:`, `focus-visible:`, `active:`, `disabled:`, `dark:`), or any `style=` or `:class=` | component props |

**Never rejected by the guard:**
- ordinary Tailwind spacing and layout utilities (`gap-4`, `px-5`, `max-w-3xl`, `min-h-[60vh]`);
- semantic theme utilities;
- legitimate runtime or dynamic values (allowlisted with a reason);
- custom properties merely for being custom properties.

**What the guard does not claim.**
- **B7 is a deny-list of clearly visual tokens, not a Tailwind parser.** The rule that callers may supply **layout classes only** (§7.2 rule 1) remains a contract enforced by review.
- **B3 and B4 see source text, not intent.** A colour reaching a view by some indirect route not listed here is left to review and the §18.4 browser palette-conformance check.

### 17.3 Allowlist, reporting and mutation checks

- **Allowlist.** A short, reviewed array of `path => rule => reason`. It is expected to be **empty** (§11.3 C). An entry needs a written reason and appears in the PR diff.
- **Reporting.** Each failure prints `rule file:line => match`.
- **Mutation and fixture checks.** Each rule has a built-in fixture it **must** catch, and a near-miss it **must not** catch, so the guard cannot be vacuous (EPIC-013 A13.13). Removing a rule's pattern must make its fixture assertion fail. The near-misses:

  | Rule | Near-miss that must pass |
  |---|---|
  | A1 | `var(--accent-success)` |
  | B1 | `text-sm`, `bg-surface`, `hover:bg-surface-hover` |
  | B2 | `min-h-[60vh]`, `href="#"`, `&#9654;` |
  | B3 | `style="width: var(--progress)"` (a non-colour custom property) |
  | B4 | `style="min-width: 11.25rem"` |
  | B5 | `focus-visible:outline-2` |
  | B7 | `<x-ui.button class="w-full sm:w-auto mt-2">`, `<x-ui.label class="sr-only">` |

## 18. Test strategy

### 18.1 Gates (every PR)

`./dev check`, `./dev test:e2e` and PR CI green. Hosted CI remains the authoritative complete-browser gate. Each PR closes with an amendment to this document that records its counts, evidence and deviations.

### 18.2 Static census

The §3.2–§3.4 measures are re-taken at four points:

```
baseline (d87b5b1) → after PR 1 and PR 2 → after PR 3 (normalized) → PR 4 (zero, or allowlisted with reasons)
```

- PR 3's amendment shows every §3.3 colour measure at zero or allowlisted for the target views.
- PR 4 records the zero-use proof for each retirement (§16) and the final census.
- Exact numbers are recorded where templating allows, and approximate otherwise.

### 18.3 Pest

| Area | Assertions | PR |
|---|---|---|
| `x-ui.button` | per variant: the exact token classes; `tone="danger"` only on secondary/ghost; `href` renders `<a>`; `disabled` / `aria-disabled`; `type` default; focus string | 1 |
| `x-ui.link` | variants; focus string | 1 |
| `x-ui.input` / `select` / `textarea` | `control-edge`, hover, focus string; disabled classes; no `outline-none` | 1 |
| Field identity (§7.5) | a simple name gives `id = name` and `error-key = name`; a nested or array name without `id` throws `InvalidArgumentException`; an explicit `id` wins; an `error-key` list and a wildcard (`attachments.*`) both set the invalid state, and the first message in key order is shown; the error element id is `{id}-error`; `aria-describedby` = caller tokens + `{id}-error`, in order, de-duplicated, with the caller's tokens preserved when the field is valid; `label[for]` equals the DOM id | 1 |
| Invoice line items | with errors at `items.2.description` and `items.2.unit_price`, only row key 2 is invalid, and its ids are `items-2-description` / `items-2-unit_price` with matching error elements; the `<template>` row carries `__INDEX__` in `name`, `id`, `for` and `aria-describedby`; **regression test for the index collision**: re-rendering after a validation failure with submitted keys `0, 2` seeds the next index at `3`, so an added row can never reuse key `2`'s `name` or `id`; every row's fields have labels | 1 |
| `x-ui.checkbox`, `x-ui.label`, `x-ui.field-error` | `accent-accent` + focus; `for`/`id` association; the group pattern (`role="group"`, `aria-labelledby`, a single group error); error id and `role="alert"` | 1 |
| Pagination view | `nav` name; `aria-current="page"`; `rel` prev/next; URLs identical to the vendor view's for the same paginator, query string included; single-page renders nothing | 1 |
| Target forms | each target view's response has an accessible name for **every user-facing labelable control**, and no duplicate DOM id. "User-facing labelable" means `textarea`, `select`, and `input` of every type **except** `hidden`, `submit`, `button`, `reset` and `image`. The excluded types are either not presented to the user or are named by their own value or text, and they are checked as buttons instead. A name may come from an associated label, a wrapping label, `aria-labelledby` or `aria-label`. Controls already named by their placeholder are counted as named and are not altered (§7.2 rule 4). Out of scope: the Stripe Payment Element's iframe on `billing/payment/show`, which Stripe owns | 1 |
| `x-ui.status`, `x-ui.priority`, `x-ui.alert`, `x-ui.tag` | tone → classes; glyph per tone; label text present; alert roles | 2 |
| Domain mappings | every ticket status, priority and `Invoice::STATUSES` value maps to the §9 tone and glyph; an unknown value falls back to `neutral` | 2 |
| Guard and retirement | §17 Level A and Level B, each rule with its must-catch and must-pass fixtures; contract-test absence pins for every retired alias | 4 |
| Theme tokens (only if §11.4 adds one) | the light **and** dark `--ds-*` values exist; the `@theme inline` mapping exists; a Blade render using the utility emits it, and the browser palette check sees it resolve in both themes; the name is semantic (reviewed); every **existing** `--ds-*` declaration keeps its value, pinned by a focused name→value map of the existing tokens (not a CSS snapshot) | 3 |
| Pre-existing token values | the focused `--ds-*` name→value pin above lands in PR 3 even if no token is added, so PR 3 provably changes no existing value | 3 |

### 18.4 Playwright

- **New spec** (working name `blade-theme-controls.spec.ts`) using the existing personas and `e2e-fixtures` cleanup.
- **Fixtures.** The spec creates the records it needs: an overdue ticket, a company, a contact, and invoices in `draft`, `sent`, `paid` and `overdue`.

| Area | Representative routes |
|---|---|
| Helpdesk | `/tickets` and `/tickets/create` (member), `/tickets/{id}`, `/operator/tickets`, `/operator/tickets/{id}` |
| Directory | `/crm/contacts`, `/crm/contacts/create`, `/organizations/{id}` |
| Finance | `/billing/invoices`, `/billing/invoices/create`, `/billing/invoices/{id}`, `/my/invoices` (member) |
| System | `/admin/users`, `/admin/roles/create`, `/operator/cms/create` |
| Required consumer | `errors/403`: the member persona requests `/admin/users` and receives HTTP 403, rendered in the Blade shell (the existing deterministic case from `blade-shell.spec.ts:472`) |
| Reference | `/projects`, `/tasks` |

Each route runs in **light and dark**, at **1440 and 390**:
- no document-level horizontal overflow;
- keyboard `Tab` through `main`: every focused control shows the focus outline (§10.1);
- accessible names: the pre-epic names still resolve by role and name, and every user-facing labelable control has a name (§10.1, §10.2). PR 1 captures the pre-epic name baseline for these routes and records it;
- primary actions compute to ink; destructive triggers compute to `danger`;
- status labels and links reach ≥ 4.5:1 (from PR 2);
- the overdue queue row is legible in dark (from PR 2);
- **palette conformance (from PR 3):** every computed text, background and border colour in `main` equals one of the current theme's Direction D token values, or an allowlisted exception. This is resolved from the live `--ds-*` values per theme, at rest and not mid-transition, and catches legacy gray, indigo or hex by value rather than by source text;
- **reference comparison (PR 3, PR 4):** on `/projects` and `/tasks`, the computed colours of representative elements match the values captured before the PR.

The existing specs (`blade-shell`, `shell`, `time-migration`, the Projects and Tasks specs) stay green unchanged.

## 19. Work packages

Each package ends with an amendment to this document. Packages after WP0 are PRs from the implementation branch (§25).

### WP0: Design gate (this document)

- **Objective:** lock the owner decisions, the component, theme and guard contracts, the semantic mapping, the census and the exit criteria. Make EPIC-016 the Planned contract.
- **In scope:**
  - this file;
  - `docs/epics/README.md`;
  - the roadmap entry and forward note;
  - forward notes in EPIC-013 (A13.4 and §31) and EPIC-015 (A6.16).
- **Out of scope:** any source, test, CSS or dependency change.
- **Gates:** `git diff --check`; links and anchors verified; historical text untouched.
- **Exit:** owner review; committed to `main` (§25).

### WP1: PR 1 — Controls and Accessibility (highest-priority accessibility work)

- **Objective:** create the Blade component seam, and move every target action, field, checkbox and paginator onto it.
- **In scope:**
  - the components `button`, `link`, `input`, `select`, `textarea`, `checkbox`, `label`, `field-error`, and the pagination view with its `Paginator` default;
  - migrating the §13 PR 1 columns: the 44 no-focus sites, 9 `ring` sites, 7 checkbox sites, 36 fills and 25 accent texts;
  - the action hierarchy (§8) and the destructive triggers, including danger-zone containers;
  - the field identity contract (§7.5) and every §10.2 name, with field groups on the `space-y-2` rhythm (§12.2);
  - the invoice line items: identity, errors, the `<template>`, and the `max(keys) + 1` integrity repair with its regression test (P8);
  - Pest component, field-identity, line-item, pagination and target-form naming tests;
  - the Playwright spec's control, focus and name checks.
- **Expected touch:**
  - `resources/views/components/ui/*` (new), `resources/views/pagination/*` (new);
  - `app/Providers/AppServiceProvider.php`;
  - the target views in §13;
  - `tests/Feature/…`, `tests/Browser/…`.
- **Out of scope:**
  - status and priority markup;
  - the broad page-body theme sweep (PR 3);
  - `app.css`;
  - any React file.
- **Exit:** no target field without the focus outline; no indigo action fill or checkbox in the target views; gates green.

### WP2: PR 2 — Status and Semantic State (separate review: it changes visual meaning)

- **Objective:** move every status, priority, alert and live indicator to the §9 mapping.
- **In scope:**
  - `x-ui.status`, `x-ui.priority`, `x-ui.alert`, `x-ui.tag`;
  - rewriting `tickets/_status_badge` and `tickets/_priority_badge`;
  - the new `billing/_invoice_status` partial replacing four duplicated maps;
  - the overdue row and SLA cell, the internal-note card, role tags (including the organization member role pill), flash alerts, report figures, MFA "Enabled", the payment ledger `+amount` and the Stripe page's error region (§9.7);
  - the time tracker's live semantics and `data-*` hooks (§9.9);
  - Pest mapping tests; Playwright status-contrast and dark overdue checks.
- **Review focus:** every mapping against Direction D §10 and §9; no domain rule, status value or transition changes.
- **Exit:** no hex or legacy-variable status styling in the target views; every status shows glyph + label; the A13.4 badge and overdue debts are re-measured and pass.

### WP3: PR 3 — Blade Theme Normalization (the large mechanical package)

- **Objective:** normalize every remaining application colour in the four target workspaces and `errors/403` to the semantic theme (§11), and apply the §12 geometry normalizations.
- **In scope:**
  - the §11.3 A mapping for all remaining raw colour variables, literal colours and inline colour styles: text hierarchy, borders and rules, card and surface colours, remaining theme-parity debt;
  - the dead hover classes (→ `hover:bg-surface-hover`, or removed where no hover is wanted);
  - `errors/403`'s accent and colour sites;
  - the invite-modal scrim;
  - §12 geometry: card radius, `min-w-45`;
  - any §11.4 token, with its contract test;
  - the focused `--ds-*` value pin;
  - the browser palette-conformance and reference-comparison checks.
- **Commit grouping** (one PR, reviewable commit by commit; §13.5):
  - **A:** Helpdesk + Directory colour (≈ 305 references, 21 files);
  - **B:** Finance + System + `errors/403` colour (≈ 240 references, 18 files);
  - **C:** geometry only.
- **Rules:**
  - no business-logic, route, copy, markup-structure or behaviour edits;
  - one-to-one §11.3 replacements;
  - anything not in §11.3 stops and is recorded (category B or C).
- **Out of scope:** alias deletion, the vendor `@source` removal, and the permanent guard (all PR 4); React.
- **Split trigger.** The planning estimate (about 545 references) fits one PR with these commits. If PR 3's own census finds substantially more (above about 800 remaining references), or any non-mechanical change, it **stops before implementation and reports a split recommendation** rather than silently changing the package count.
- **Exit:** the §3.3 colour measures are zero or allowlisted in the target views; palette conformance holds on every §18.4 route in both themes; Projects/Tasks reference comparison unchanged; gates green.

### WP4: PR 4 — Alias Retirement, Guard and Final Hardening

- **Objective:** irreversible cleanup and permanent protection, only after PR 1–3 consumers are migrated.
- **In scope:**
  - the four-part zero-use proof and retirement of the §16.1 set;
  - evaluation of every §16.2 candidate, retiring each only on proof;
  - removing the vendor-pagination `@source` line;
  - flipping the contract-test pins to absence;
  - the §17 guard, both levels, with fixtures;
  - the final static census (§18.2);
  - the final Projects/Tasks regression checks and the full §18.4 matrix;
  - closeout evidence.
- **Exit:** all §20 criteria hold; the final package's PR CI is green.

## 20. Exit criteria

EPIC-016 is **Verified** when all of these hold. It moves to **In Progress** when WP1 (PR 1) merges, and to **Done** when the final package (PR 4) merges with green `main` CI, following the repository lifecycle.

**Controls and accessibility**

1. The shared Blade semantic control layer (§7) exists, with Pest-tested contracts for every component and the pagination view.
2. Every generic primary action in the target views uses the ink treatment through `x-ui.button`; no page uses `var(--accent)` as a fill.
3. Every field, button, link, checkbox and pagination link in the target views shows the 2px `focus` outline on keyboard focus in both themes. **No known target field lacks visible keyboard focus.**
4. Labels, errors and field boundaries meet the locked contract:
   - every target field, select, textarea and secondary-button boundary uses `control-edge`;
   - fields follow §7.5 (`label[for]` → DOM id; errors by `error-key`; `aria-invalid="true"` and an `aria-describedby` containing `{id}-error` plus every caller reference);
   - no target page renders a duplicate id;
   - the invoice line-item integrity repair and its regression test are in place.
5. Checkbox selection uses the semantic accent (`accent-accent`); no checkbox uses the legacy accent.
6. Target pagination renders through the Direction D view, follows `data-theme` in both themes, preserves the vendor view's navigation and URLs, and the vendor `@source` line is gone.
7. Destructive triggers use danger semantics (`tone="danger"`); no destructive action renders as ink, accent or green; native confirmations are unchanged.
8. **Accessible names: existing ones preserved, missing ones added.**
   - Every control in the target views with an authored name at `d87b5b1` (its own text or value, a label, a wrapping label, `aria-label` or `aria-labelledby`) keeps it exactly, and visible control text is unchanged.
   - Placeholder-derived names are unchanged, except the three enumerated Finance fields (§7.2 rule 4), whose name becomes their visible label.
   - Every previously unnamed user-facing labelable control has a name, by the §10.2 rules. That includes the invoice line-item `×` ("Remove line item") and the queue selection checkboxes.
9. `time-migration.spec.ts` (including `Start timer` and `/Start Timer/`) is green without edits.

**Semantic state**

10. Ticket status, ticket priority, invoice status, overdue, internal note, role type, alert and live presentation match §9:
    - glyph + label + tone, with the P6 / P11 substitutes recorded;
    - no hex or legacy-variable status styling;
    - the A13.4 contrast debts in scope re-measured and passing AA in both themes: the pills, the `open` / `Built-in` pairing, the dark overdue row, white on indigo and indigo links, field edges, and `--text-muted` text.

**Theme normalization**

11. In the four target workspaces and `errors/403`, **every application presentation colour** is an existing semantic theme utility, a justified new semantic utility, or a documented exception (§11.1). There are no literal colour values and no numeric or named palette colour utilities, except documented exceptions.
12. Raw legacy colour-variable presentation in those views is replaced by semantic Tailwind utilities wherever an equivalent exists (§11.3). The static census shows zero legacy colour variables, or allowlisted ones with reasons, and the browser palette-conformance check passes on every §18.4 target route in both themes.
13. Any new theme token satisfies §11.4: a semantic name, light **and** dark values, an `@theme inline` mapping, Direction D documentation, and a contract test. No existing `--ds-*` value has changed (the PR 3 value pin).
14. Repeated product-level geometry uses the shared conventions where §12.2 applies (component control geometry, the field-group rhythm, the 8px card radius, `min-w-45`). Ordinary layout utilities are unchanged except where §12.3 normalizes them.

**Retirement, guard and validation**

15. Aliases are retired only after the §16 four-part zero-use proof: every §16.1 alias is gone and pinned absent, and every §16.2 candidate is either retired on proof or recorded as kept with its consumer.
16. The permanent two-level guard (§17) is in place:
    - Level A protects the application-wide retirements;
    - Level B protects the target views;
    - every rule fails on its must-catch fixture and passes its near-miss;
    - it passes with an empty allowlist, or with recorded and justified exceptions;
    - it rejects no ordinary spacing or layout utility and no custom property merely for being one.
17. Representative light and dark browser validation passes across all four areas and `errors/403` (§18.4).
18. Representative 390 and desktop validation passes, with no document-level horizontal overflow.
19. Projects and Tasks are visually unchanged: no `resources/js` diff, the reference comparisons match, and their existing specs are green.
20. `./dev check` and `./dev test:e2e` are green on the final package.
21. Hosted PR CI is green on the final package.

## 21. Non-goals

- Blade → React renderer migration for any module (owned by the Helpdesk MVP, Directory and Finance product epics, and by System administration work).
- Route changes; information-architecture, page-structure or navigation redesign, including adopting the Direction D page frame and `DataTable` conventions on Blade pages.
- Workflow redesign: no confirmation dialogs replacing `confirm()`, no new bulk actions, no ticket triage changes.
- Domain or business-rule changes: no status values, transitions, SLA rules, invoice lifecycle, Stripe flow or CRM data-model changes.
- New permissions or authorization changes.
- New Finance, Helpdesk, Directory or System behaviour, including copy changes such as the customer label "Waiting on you" (Direction D §10.4).
- Customer-shell redesign.
- Broad typography redesign and arbitrary full-application layout redesign; any change to the React primitives or to Projects/Tasks.
- Modules outside the four targets, such as Resources (`cms/*`), except where shared alias retirement requires.
- "Make everything green", "make everything teal", or "hide every number behind a token". Colour follows meaning (§8, §9, §11); ordinary layout stays ordinary (§12).
- Changing any existing theme token value, adding a third theme, or changing fonts.

## 22. Deferred items and follow-ups

| Item | Disposition |
|---|---|
| EPIC-013 A13.4 contrast debts in the target views | **Required here** (PR 1–3, §20 #10) |
| EPIC-013 A4.8 badges (`draft`, `open` / `Built-in`) | **Required here** (PR 2) |
| EPIC-015 A6.16 consistency audit | **Done**: the audit ran on `d87b5b1`; this epic is its destination |
| Legacy page-body colour in the four target workspaces (about 470 generic uses at baseline) | **Required here** (PR 3, §11). This supersedes the WP0 draft's deferral |
| Dead `hover:legacy-bg-surface` (EPIC-013 WP1a, A2.3) | **Required here** (PR 3) |
| React `Badge` legacy `info` / `success` / `warning` / `danger` variants (Profile, Time) | **Deferred**: their next React slice; the status families stay |
| React `--text-danger` usages (`form-field-error.tsx`, `board.tsx`, `task-delete-button.tsx` and others) | **Deferred**: React is out of scope (§14) |
| `cms/*` (Resources) colour normalization and `--text-muted` contrast | **Deferred**: Knowledge/CMS evolution |
| Hourglass glyph for `pending_user` (Direction D §10.4) | **Deferred**: needs a shared glyph in both renderers; EPIC-016 uses `dashed` ([P6](#24-plan-level-choices-open-to-review)) |
| Arrow glyph for invoice `sent`, and clock glyph for overdue (Direction D §10.2, §10.4, §10.5) | **Deferred**: added to the shared cross-renderer `Status` vocabulary (React `status.tsx` and `x-ui.status` together) by the future Finance / React slice; until then `half` and `square` ([P11](#24-plan-level-choices-open-to-review)) |
| Blade confirmation dialogs replacing `confirm()` | **Deferred**: the module product epics |
| Direction D page frame and content widths; `PageHeader`; `DataTable` density on Blade pages | **Deferred**: the module product epics ([P13](#24-plan-level-choices-open-to-review), [P15](#24-plan-level-choices-open-to-review)) |
| `@shadcn/lint` evaluation for React | **Post-EPIC-016 tooling follow-up** (§22.1) |

### 22.1 Follow-up: `@shadcn/lint` for the React/Tailwind side

After EPIC-016, evaluate adopting `@shadcn/lint` for React against the semantic Tailwind v4 theme. The evaluation should cover `no-raw-colors`, `no-restyle`, `no-unknown-classes`, `no-inline-styles`, `no-arbitrary-values` and `require-static-classes`, and their fit with the Direction D utilities, the shadcn compatibility aliases and the existing tests.

- **Not a dependency of EPIC-016.** No dependency is added in this epic.
- **It would not replace the Blade guard.** The linter does not read Blade templates, so the §17 guard remains necessary.

## 23. Risks and rollback

| # | Risk | Likelihood | Impact | Mitigation | Rollback |
|---|---|---|---|---|---|
| R1 | Blade class strings drift from the React primitives | Medium | Medium | Pest pins the exact token classes; each component cites its React source; the guard blocks legacy patterns | Fix the component; consumers follow automatically |
| R2 | A caller adds a conflicting colour class that Blade cannot merge | Medium | Low | Guard rule B7 for literal invocations in target views; contract and review elsewhere (§7.2 rule 1) | Remove the caller class |
| R3 | Label and id association changes break a form post | Low | Medium | Existing `name` attributes are unchanged (only the added-row index seed, §7.5); Feature tests post forms as today; the line-item Pest case | Revert the view |
| R4 | A status or label change breaks a selector | Low | Medium | Existing names and visible text are preserved; only unnamed controls gain names (§7.2 rule 4); `time-migration.spec.ts` is a criterion | Revert the partial |
| R5 | Retiring an alias breaks an unmigrated consumer | Low | High | Zero-use proof; contract-test absence pins; CI | Restore the alias definition (additive) |
| R6 | The pagination swap changes navigation or URLs | Low | Medium | Behaviour preserved (§7.4); a Pest URL-parity test | Remove the `Paginator` default |
| R7 | JS-generated line-item rows or time-tracker states diverge from the components | Medium | Low | Server-rendered `<template>` / state toggling (§9.9, [P8](#24-plan-level-choices-open-to-review)) | Revert that view |
| R8 | Scope creeps into IA or workflow redesign | Medium | Medium | §21 non-goals; PR review checks against §12 | Drop the extra change |
| R9 | Projects/Tasks regress through a shared change | Low | High | No React diff; no existing token value changes (PR 3 value pin); reference comparisons (§14) | Revert the `app.css` hunk |
| R10 | Browser fixtures leak records | Medium | Low | The existing `e2e-fixtures` cleanup pattern | Clean-up spec |
| R11 | **Mechanical-diff risk.** PR 3's large conversion (about 545 references, 39 files) creates review noise or hides an unintended change | Medium | Medium | Dedicated PR 3 with commit groups A / B / C; one-to-one §11.3 mapping; automated census before and after; palette-conformance and screenshot review of the §18.4 routes; no business-logic, route, copy or structural edits; the §19 split trigger | Revert the offending commit; the guard is not yet in place, so nothing else depends on it |
| R12 | The time tracker's script loses its hooks during migration | Medium | Medium | `data-*` hooks land in PR 2, before PR 3 touches its classes (§9.9); `time-migration.spec.ts` unedited and a criterion | Revert the tracker view |
| R13 | **Semantic-token overfitting.** A token is invented for every visual value | Low | Medium | Ordinary layout stays ordinary Tailwind (§12.1); a new token needs all five §11.4 conditions, documentation and a contract test; the expected outcome is zero | Remove the token; map to the existing utility |
| R14 | **Unintended Projects/Tasks change** | Low | High | No existing token value changes (PR 3 value pin); React untouched; PR 3 and PR 4 reference comparisons (§14) | Revert the `app.css` hunk |
| R15 | **False-positive guard** blocks legitimate code | Medium | Low | Semantic, targeted rules; no generic `var()` ban; ordinary layout never rejected; must-pass near-miss fixtures; a small reviewed allowlist | Narrow the rule; add a reasoned allowlist entry |
| R16 | Visible light-theme change (Tailwind gray → warm Direction D neutrals, 12px → 8px card radius) surprises users | Medium | Low | Intended adoption (Direction D §2.4.4); same information and behaviour; screenshot review in PR 3 | Revert commit C (geometry) independently of colour |

## 24. Plan-level choices open to review

None blocks WP1. Each is the plan's default, derived from the locked decisions and the reference surfaces. The owner may override any of them when reviewing this document.

| # | Choice | Rationale |
|---|---|---|
| P1 | Components live at `views/components/ui/*` as `<x-ui.*>` anonymous components | Mirrors React `components/ui`; follows the one existing Blade component convention |
| P2 | Links split by role: standalone links and row actions use `accent` (teal, O1); a row's primary identifier uses the reference row grammar (`text-text font-medium hover:underline`); non-link figures are plain text | Teal for links is the owner's ruling and Direction D §2.2. The reference tables draw identifiers in ink, and copying that keeps Blade tables consistent with Projects/Tasks |
| P3 | Checkboxes use `accent-accent`, as the React reference does, not `accent-line` (Direction D §2.2 "checked checkbox fill") | Reference parity. The two differ only in dark (`#7ADDE4` vs `#19E7F2`) |
| P4 | Native `confirm()` is kept; triggers are `secondary`/`ghost` `tone="danger"`; solid `destructive` ships for parity with no Blade consumer | Replacing `confirm()` is workflow change (§21). The trigger matches `projects/edit.tsx:221` |
| P5 | Pagination preserves the vendor view's numbered pages and summary instead of copying React's prev/next-only `Pagination` | "Preserve pagination behaviour". Removing page numbers would be a navigation regression |
| P6 | `pending_user` uses the `dashed` glyph, not Direction D §10.4's hourglass | The shared glyph set has no hourglass. Adding one only to Blade would fork the vocabulary, and adding it to React is out of scope |
| P7 | A cancelled invoice's amount is struck through where the row shows it (Direction D §10.5) | Spec-defined status presentation, not a Finance rule. The owner may drop it |
| P8 | **Owner-approved** (the index rule as a narrow PR 1 integrity repair, with a regression test). Invoice line-item rows added by JavaScript clone a server-rendered `<template>` built from the same components (§7.5); rows after the first get visually hidden labels; the next index is `max(keys) + 1` | Keeps one markup source; fixes unlabelled added rows; submission names are unchanged. The index rule is required for unique ids, and incidentally prevents a latent name collision after a validation failure |
| P9 | Label association and error ARIA are in PR 1, not deferred | Found in WP0 discovery. They sit on the same elements PR 1 rewrites, and leaving fields without a name while fixing their focus would be incoherent |
| P10 | Region-local Update/Apply actions become secondary; per-region primaries are chosen as in §8.3 | Direction D §2.3.5 |
| P11 | **Owner-approved.** **Temporary glyph substitutes for Direction D glyphs the shared `Status` vocabulary lacks.** Invoice `sent` (Direction D §10.5 accent **arrow**) uses `half`. Invoice `overdue` (§10.5 danger **clock**) and ticket SLA overdue (§10.2, §10.4 **clock**) use `square`. The shared vocabulary is not extended in EPIC-016, and these are recorded deviations, not parity. The real arrow and clock join the shared cross-renderer vocabulary (React `status.tsx` and `x-ui.status` together) in the future Finance / React slice (§22) | Same reasoning as P6: a Blade-only glyph would fork the vocabulary, and changing React is out of scope (O5). The substitutes come from repository evidence: `half` is `Status`'s own default for the `info` tone, and the glyph already used for an in-flight state (`in_progress`, §9.1); `square` is the default for `danger`, and Direction D's own shape for "Off track" (§10.1). `dot` was rejected for `sent` because the vocabulary reserves it for `live`. Both keep the five invoice glyphs distinct, and the label always carries the meaning |
| P12 | **Owner ruling (supersedes the earlier bounded P12).** PR 3 normalizes **all** application colour presentation in the four target workspaces and `errors/403` (§11), including ordinary page-body text, borders and surfaces | Theme-first application styling (O8); a raw legacy variable is not normalized just because its value matches a token (§11.1) |
| P13 | The Direction D page frame (`[data-page-frame]`, reading widths) is **not** adopted on Blade pages; the seven `max-w-*` containers stay | Adopting it changes every page's content width and is page-structure work for the module epics (§21) |
| P14 | Card radius `rounded-xl` (12px) → `rounded-lg` (8px) in PR 3 commit C | Direction D §4.2 specifies 8px for cards, and an ordinary Tailwind utility already expresses it; no token is needed |
| P15 | Table cell density is **not** normalized | Direction D §4.1 density comes with the `DataTable` conventions; normalizing the Blade outliers alone would be purity, not a product contract |
| P16 | The prospective implementation branch is renamed `feature/epic-016-blade-theme-control-adoption` | It matches the epic title; no branch exists yet |

## 25. Branch and PR strategy

- **WP0:** documentation only, reviewed uncommitted, then committed directly to `main`. **No branch** is created for it (EPIC-015 §25 precedent). EPIC-016 stays **Planned**.
- **Implementation:** after WP0 is on `main`, **one** branch, **`feature/epic-016-blade-theme-control-adoption`** (P16), is cut from current `main`. It is not created yet.
  - The four PRs go from it to `main`, in order:
    1. Controls and Accessibility;
    2. Status and Semantic State;
    3. Blade Theme Normalization;
    4. Alias Retirement, Guard and Final Hardening.
  - Each depends on its predecessors: PR 2 uses PR 1's components; PR 3 normalizes what PR 1 and PR 2 leave; PR 4's retirement and guard need all consumers migrated.
  - After each merge the branch is synced to current `main` (merge, not rebase of published history). No force pushes.
- **CI:** the PR gate is the merge gate for every package.
- **Lifecycle:** Planned → In Progress (PR 1 merges) → Verified (§20) → Done (PR 4 merges with green `main` CI).
- **Record:** each package's results are recorded as an amendment to this document in the same PR.

---

## Amendment 1: WP1 Controls and Accessibility (PR 1)

> **Status (2026-10-06): WP1 implemented on `feature/epic-016-blade-theme-control-adoption`; uncommitted and unpushed at the time of writing. The independent review (A1.18) approved the architecture and asked for a small remediation, which is applied here.** EPIC-016 stays **Planned** until PR 1 merges (§20). The historical design-gate body text above this amendment is preserved except where an amendment ruling explicitly supersedes it (as for §13.1's time-tracker cell, A1.15 #2); the header lifecycle and status line may be updated as the epic progresses (it now notes WP1 under review; the status remains **Planned**).

### A1.1 Starting point and method

- **Starting SHA:** `d07384d79c67829f3bfbe07a62d59389d328f3d8` (`docs: establish EPIC-016 Blade theme and control adoption`), equal to `main` and `origin/main`; branch `feature/epic-016-blade-theme-control-adoption`; working tree clean, no stash. WP0 committed, WP1 not started.
- **Method.** The component seam first, then the target views by workspace, then the tests, then the browser and visual checks. Every migration was held to "same information, same behaviour": no route, payload, validation rule, permission, workflow transition, redirect, pagination query semantics or product copy changed, and no existing assertion was flipped (A1.11).
- **No new theme token** (§7.2 rule 6, §11.4). Every contract below is expressed in the existing `--ds-*` layer; `app.css` is untouched. No dependency, CI, migration, route, React or `tests/Browser/support` change.

### A1.2 Scope delivered

Delivered (§19 WP1): the eight Blade components, the Direction D pagination view and its `Paginator` default, the §13 PR 1 columns for Helpdesk, Directory, Finance and System plus the `errors/403` buttons, the action hierarchy (§8), the field identity contract (§7.5), every §10.2 name, the three approved Finance name changes, the invoice line-item repair (P8), Pest component, identity, line-item, pagination and target-form tests, and the Playwright control, focus and name checks.

Not in WP1, left for later packages (A1.16): status, priority, alert and tag components and every status pill; the time tracker's markup and script; page-body colour and geometry; alias retirement, the vendor pagination `@source` line and the permanent guard.

### A1.3 The component seam

Anonymous components under `resources/views/components/ui/` (P1), used as `<x-ui.*>`:

| Component | Contract implemented |
|---|---|
| `button` | `variant` `primary` (default; `bg-ink text-on-ink`), `secondary` (`bg-surface border-control-edge text-text`), `ghost`, `destructive` (`bg-destructive text-destructive-foreground`, no Blade consumer); `tone="danger"` on `secondary` / `ghost` only (the destructive **trigger**: `border-danger text-danger`), anything else throws `InvalidArgumentException`; `size` `sm` / `md` / `lg` / `icon` on the shared `controlHeight` scale with `pointer-coarse:` one step up, `icon` requires `aria-label`; `href` renders an `<a>` with the same classes; `disabled` is the `disabled` attribute on a `<button>`, and `aria-disabled="true"` with no `href` on a link, drawn `bg-surface-sunken text-text-muted`; `type` defaults to `button`; every other attribute (`name`, `value`, `form`, `data-*`, `aria-*`, `onclick`, `rel`, `target`) is forwarded |
| `link` | `accent` (default), `quiet` (underlined `text-text-secondary`), `row` (`text-text font-medium hover:underline`, the Projects/Tasks reference grammar); the exact focus ring with `rounded-control` |
| `input`, `select`, `textarea` | `control-edge` boundary on `bg-surface`, `hover:border-text-muted`, `aria-invalid:border-danger`, disabled `bg-surface-sunken text-text-muted border-rule-control`, placeholder `text-text-muted`, `h-9 pointer-coarse:h-11` (textarea `min-h-24 resize-y`), the exact focus ring; `input` covers `search`, `date`, `number`, `email`, `url` and `file` (picker styled with `file:` utilities). No width class is baked in: callers add `w-full` (layout) |
| `checkbox` | native, `size-4 accent-accent`, focus ring, disabled state; with slot content it wraps itself in the naming `<label>` (`has-[:disabled]:text-text-muted`), without it only the `<input>` renders; a wrapped checkbox with a non-simple name (`permissions[]`) needs no `id` |
| `label` | `for` = the DOM id, `text-sm font-medium text-text`, `required` renders the existing `*` in `text-danger`, `class="sr-only"` allowed |
| `field-error` | `for` = the DOM id, `error-key` = the field's key(s) (default: `for`); renders `<p id="{for}-error" role="alert" class="text-sm text-danger">` with the **first** message in key order, nothing when no key has an error |

**Focus.** Every interactive component carries the exact `focusRing` string (`focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus`). None uses `outline-none` or `focus:ring-*`, and none uses `transition-colors` / `transition-all`: Tailwind's colour transition includes `outline-color`, which makes the ring fade in from `currentColor`. The first browser run caught exactly that on `x-ui.link`; it now uses `transition-[color,background-color,border-color]` like the button (pinned by Pest and by the browser walk, which now runs with real transitions).

**Identity helper.** The §7.5 rules live in one place, `App\Support\FieldState` (resolve id and error keys from `name`; throw on a non-simple name without an explicit `id`; an explicit `id` wins; `aria-describedby` = the caller's tokens in order, then `{id}-error` when invalid, de-duplicated; `firstError` over an ordered key list with wildcards). It reads only what it is handed (a name, an id, keys, the shared `$errors` bag). Components never touch the database or the request (Pest asserts zero queries and scans the sources). The epic planned no PHP beyond attribute handling; a 150-line static helper beside `Support\BrandMark` and `Support\Initials` avoids copying these rules into five components (A1.15 #1).

### A1.4 Action hierarchy applied (§8)

- **Primary (ink), one per region:** `New Ticket`, `Submit Ticket`, `Post Reply`, `+ New Contact`, `Create Contact`, `+ New Company`, `Create Company`, `Save Changes`, `Add` (organization member), `New Invoice`, `Create Invoice`, `Mark as Sent`, `Record Payment`, `Pay Now` (client invoice page, payment-page submit), `Invite User`, `Send Invitation`, `New Role`, `Create Role`, `Update Roles`, `+ New Page`, `Create Page`, `Publish`, `Save`, and the two `errors/403` buttons (`Dashboard` / `Sign in`).
- **Search and Filter submits are secondary** on `tickets/index`, `operator/tickets/index`, `crm/*/index`, `billing/invoices/index` and `admin/users/index`; `Clear`, `Reports`, `Export CSV`, `Edit`, `Cancel` (beside a primary), `View Companies`, `View CRM Record`, `Unpublish`, `Promote to Org`, `View Organization` and the attachment download chips are secondary or ghost, never teal and never green.
- **Region-local updates are secondary:** `Update Status`, `Update Assignee`, the queue bulk-bar `Apply`, the report `Apply`; the per-row `Pay Now` on `billing/client/index` is `secondary sm`; `Pay Now` on the operator `billing/invoices/show` is secondary because `Mark as Sent` / `Record Payment` hold that page's primaries (§8.3).
- **Destructive triggers** are `secondary tone="danger"` (`Delete Contact`, `Delete Company`, `Delete Page`, `Delete Role`, invoice `Delete`) and row-level text actions are `ghost tone="danger"` (`Remove` on `organizations/show`, `Delete` on `roles/index`, the line-item `×`). The three danger-zone containers take `border-danger`. **Every native `confirm()` is byte-for-byte unchanged** (the `onsubmit` / `onclick` attributes pass through the component).

### A1.5 Names, labels and errors

- **Labels (§10.2 case a).** Every previously unassociated visible label is now `<x-ui.label for="{id}">`: `crm/contacts/_form` (7), `crm/companies/_form` (5), `operator/cms/_form` (3), the invoice form (header fields and all three columns on every row), `tickets/create` attachments, the report date range (2), the payment form (2). Visible wording is unchanged; the `*` markers are `x-ui.label required`.
- **Names from visible text (case b).** `operator/tickets/show`'s `status` and `assignee_id` selects use `aria-labelledby` on the existing "Status" / "Assignee" headings (`ticket-status-heading`, `ticket-assignee-heading`).
- **Explicit `aria-label` (case c, only where nothing visible names the control):** queue select-all "Select all tickets on this page", row checkbox "Select ticket {number}" (id `ticket-cb-{id}`), queue filters "Status", "Priority", "Assignee", "Submitted from", "Submitted to", bulk bar "Bulk action" and "Assign to", the status filters on `tickets/index` and `billing/invoices/index` "Status", reply `attachments[]` "Attachments" (hint associated through `attachments-hint`), `organizations/show` "Person to add" and "Organization role", the line-item `×` "Remove line item".
- **Unchanged names.** Search fields and reply textareas keep their placeholder-derived names; wrapped checkboxes keep their label text; every button and link in the target views keeps its text. The one exception is the pagination seam, whose two intentional name corrections are recorded in A1.7. `time-migration.spec.ts` (including `/Start Timer/`) is green without edits.
- **The three approved Finance changes** (§7.2 rule 4), placeholders kept as hints: line-item description "Service or product description" becomes **"Description"**; the unit price on JavaScript-added rows "0.00" becomes **"Unit Price"**; payment notes "e.g. Bank transfer" becomes **"Notes"**.
- **Errors.** A field whose key has a server error gets `aria-invalid="true"`, the `border-danger` edge, and `{id}-error` merged after the caller's own `aria-describedby` tokens; the matching `x-ui.field-error` renders `id="{id}-error"` with `role="alert"`. Existing hints (`Up to 10 files, 20 MB each.`, `Use lowercase letters, numbers, and hyphens only.`) gained ids and are referenced. The invite modal keeps `id="invite-email"` with name `email` and `error-key="email"`; `select-all` and `bulk-bar` ids are untouched.

### A1.6 Checkboxes

Seven sites (select-all, queue row, `is_internal` on both ticket pages, the `permissions[]` pair, `roles[]`): `accent-legacy-accent` is gone from every target view; the selection accent is `accent-accent` (not the primary-action ink). The permission and role lists are `role="group"` containers (`permissions-group`, `user-roles-group`) with `aria-labelledby` on the existing heading, one group `x-ui.field-error` after the group (`['permissions', 'permissions.*']` / `['roles', 'roles.*']`) and `aria-describedby` on the container when invalid. Each box stays wrapped in its existing `<label>`.

### A1.7 Pagination

`resources/views/pagination/direction-d.blade.php` and `simple-direction-d.blade.php`, registered in `AppServiceProvider::boot` through `Paginator::defaultView` and `defaultSimpleView`; the nine `->links()` call sites are unchanged. `nav aria-label="Pagination"`; links are `x-ui.button secondary sm`; the current page is `<span aria-current="page">` drawn `bg-ink text-on-ink`; an unavailable direction is omitted; the summary is `text-text-secondary tabular-nums`; previous / next, numbered pages with the ellipsis, the "Showing x to y of z results" summary and the compact mobile previous / next are all kept; the wide layout's prev / next arrow `aria-label`s decode the framework's entities (the vendor view double-escaped them into the name). Only semantic utilities are used, so both themes follow `data-theme` (the vendor view's `dark:` followed the OS). URLs are Laravel's own: a Pest test compares the set of hrefs to the vendor view's for five pages of the same paginator, query string included. **The vendor `@source` line in `app.css` stays; its removal is WP4's** (§19 WP4).

**Accessible-name history.** Two pagination names changed on purpose; neither is "unchanged":

1. The nav landmark was **"Pagination Navigation"** (the vendor view's `aria-label="{{ __('Pagination Navigation') }}"`) and is now **"Pagination"**, as §7.4 requires.
2. The wide layout's previous / next arrow controls (icon-only links, named by `aria-label`) were the literal double-escaped strings **"&laquo; Previous"** and **"Next &raquo;"**: `lang/en/pagination.php` holds `'previous' => '&laquo; Previous'` and `'next' => 'Next &raquo;'`, and the vendor view puts `__('pagination.previous')` / `__('pagination.next')` into the attribute through `{{ }}`, which escapes the ampersand, so the accessible name was the text `&laquo; Previous` / `Next &raquo;`. They are now the rendered **"« Previous"** and **"Next »"** (`html_entity_decode` of the same language strings). The next arrow is therefore "Next »", not "Next &raquo;".

The compact (narrow) previous / next links render the same language strings with `{!! !!}` in both the vendor view and this one, so their visible text and name were already "« Previous" / "Next »" and did not change. Numbered pages keep "Go to page N" and `aria-current="page"`.

### A1.8 Invoice line items (P8)

- One markup source: `billing/invoices/_line_item.blade.php`, included for every server-rendered row and for the `<template id="line-item-template">` the "+ Add line item" button clones (`template.innerHTML.replaceAll('__INDEX__', idx)`); no HTML or colour lives in a JavaScript string any more.
- Per field: name `items[{i}][field]`, id `items-{i}-field`, error key `items.{i}.field`, label `for` the id, error element `{id}-error`. Quantity and unit price gain field errors with the same keys.
- Labels: the first rendered row keeps the visible column labels; every other row, and the template, carries the same text as `sr-only` labels.
- **The index fix.** The next index is `max(integer keys of old('items')) + 1` (computed server-side; `0` for none), never the row count. After a failed submit that removed a middle row (keys `0`, `2`) the count is 2 and would have reused key 2 for both the DOM id and the submitted `items[2][...]` name; the script now starts at 3. Submission names, validation and invoice math are unchanged.
- The remove control is `x-ui.button variant="ghost" tone="danger" size="icon" aria-label="Remove line item"`, found by a `data-remove-line-item` hook instead of a utility class.

### A1.9 Target-area inventory (per file)

| Area | File | Change |
|---|---|---|
| Helpdesk | `tickets/index` | `New Ticket` primary; search, status filter (named), `Filter`/`Clear` secondary; `View` link; paginator |
| | `tickets/create` | 5 fields + file input with labels, `required` markers, field errors, attachments hint; `Submit Ticket` primary, `Cancel` ghost |
| | `tickets/show` | reply textarea, `is_internal` checkbox, named file input with hint and error, `Post Reply` primary; attachment chips secondary; back link quiet |
| | `operator/tickets/index` | search, 3 selects and 2 dates (named), select-all and row checkboxes (named), bulk selects (named) with field errors, `Apply` and `Filter` secondary, `Reports`/`Clear` secondary, `View` links |
| | `operator/tickets/show` | reply, checkbox, file input, `Post Reply` primary, `Update Status` / `Update Assignee` secondary, selects named by their headings |
| | `operator/tickets/reports` | From / To labelled, `Apply` and `Export CSV` secondary |
| | `components/time-tracker`, `tickets/_status_badge`, `tickets/_priority_badge` | **not touched** (WP2; for the tracker, the WP1 review ruling in A1.15 #2 supersedes §13.1's PR 1 cell) |
| Directory | `crm/contacts/*`, `crm/companies/*` | forms (7 and 5 labelled fields, `required`, field errors), index search/`Search`/`Clear`, row links (`row`/`quiet`), `Org ↗` and `View Organization ↗` as link / secondary button, `Promote` secondary with its `confirm()`, delete triggers and danger zone |
| | `organizations/index`, `organizations/show` | links; member selects named "Person to add" / "Organization role" with field errors; `Add` primary; `Make Member/Admin` ghost; `Remove` ghost danger |
| Finance | `billing/invoices/index` | `New Invoice` primary, search, status (named), `Filter` secondary, number links `row` |
| | `billing/invoices/_form`, `_line_item`, `create`, `edit` | see A1.8; header fields labelled with field errors; `Create Invoice` / `Save Changes` primary, `Cancel` secondary |
| | `billing/invoices/show` | `Edit` secondary, `Mark as Sent` primary, `Delete` danger trigger, `Pay Now` secondary, payment fields labelled ("Amount", "Notes"), `Record Payment` primary |
| | `billing/client/index`, `billing/client/show`, `billing/payment/show` | `row` links, `Pay Now` (secondary per row, primary on the invoice page), the Stripe submit as `x-ui.button size="lg"` keeping `id="submit-btn"`; the Stripe iframe and `#payment-message` are untouched |
| System | `admin/users/index` | `Invite User` primary, search, `Search`/`Clear`, `View` links, the invite modal's labelled field and buttons (hand-built modal behaviour unchanged) |
| | `admin/users/show` | role group, `Update Roles` primary |
| | `admin/roles/index`, `create`, `edit` | `New Role` primary, `Edit` link, `Delete` ghost danger; name field (hint, error), permission groups, `Create Role` / `Save Changes` primary, `Delete Role` danger trigger |
| | `operator/cms/index`, `_form`, `create`, `edit` | `+ New Page`, row links, 3 labelled fields, `Publish` / `Save` primary, `Unpublish` secondary, `Delete Page` danger trigger and danger zone |
| Consumer | `errors/403` | `Go back` secondary, `Dashboard` / `Sign in` primary; the accent `403` numeral stays for WP3 |

### A1.10 Static census after WP1 (target views, `errors/403` included)

| Measure | Baseline (`d87b5b1`) | After WP1 |
|---|---:|---:|
| `var(--accent)` primary fills (`#fff` on accent) | 36 (+2) | **0** |
| `accent-legacy-accent` checkboxes | 7 sites | **0** (7 `x-ui.checkbox`) |
| `outline-none` fields with no replacement focus | 44 | **0** |
| `focus:ring-2` fields | 9 | **0** |
| `<a>`, `<button>`, `<select>`, `<textarea>`, visible `<input>` hand-built in a target view | all | **0** (enforced by `BladeControlAdoptionTest`) |
| `var(--accent)` text (figures, status pills, tags, the 403 numeral) | 25 (+1) | 8, all WP2/WP3 sites |
| `$paginator->links()` on the vendor view | 9 | **0** (9 call sites on the Direction D view) |
| Raw `var(--…)` references / inline `style=` in target views | 1,081 / 737 | 639 / 465 (controls only; the rest is WP3) |
| `x-ui` invocations | 0 | button 84, link 46, input 35, select 17, textarea 8, checkbox 7, label 40, field-error 49 |

### A1.11 Tests

| Suite | Tests (assertions) | Covers |
|---|---:|---|
| `Unit/Ui/ButtonAndLinkComponentTest` | 22 (106) | button variants, danger trigger, rejected combinations, icon `aria-label`, sizes, disabled `<button>` and link, `href`, attribute and class passthrough, link variants, transition and focus ring, zero queries |
| `Unit/Ui/FieldComponentsTest` | 37 (160) | input / select / textarea classes and focus ring, identity (simple name, explicit id wins, non-simple name throws), `old()` value, `aria-invalid`, `aria-describedby` merge and de-duplication, `error-key` lookup, ordered list with wildcard, forced `invalid`, disabled, `file`, label `for` / `required` / `sr-only`, field-error id, role, first-message order and absence, checkbox accent / checked / disabled / wrapped / group / invalid, zero queries |
| `Unit/Ui/PaginationViewTest` | 15 (155) | application default for both views, `nav` name, prev / next URLs with the query string, numbered URLs, exactly one `aria-current`, URL parity with the vendor view on 5 pages, ellipsis and summary, compact and wide layouts, omitted unavailable direction, single page renders nothing, simple paginator, no `dark:` / palette / inline style, clean arrow names |
| `Unit/Ui/AccessibilityContractDetectorTest` | 15 (19) | the contract detector catches an unlabeled input / select / textarea / checkbox / file, a duplicate id, a dangling `label[for]`, an error not associated with its field, a missing `aria-invalid`, a dangling `aria-describedby`, an orphan group error, and accepts the four name sources and the placeholder fallback |
| `Unit/Configuration/BladeControlAdoptionTest` | 88 (444) | the PR 1 exit check: 39 target views each with no hand-built control and no legacy accent / checkbox / obsolete focus; the component seam has no hex, variable, inline style, palette, `dark:` or `transition-colors`; the exact focus ring on all six interactive components; no component reads the database or request |
| `Feature/Ui/TargetFormAccessibilityTest` | 44 (175) | the contract on 30 real target pages (with a minimum labelable-control count each), `errors/403`, and 13 failed submissions (ticket create and replies, status, contact, company, invoice with row 2 invalid, payment, role create / edit, user roles, CMS page, organization member) |
| `Feature/Ui/TargetAccessibleNamesTest` | 12 (211) | every §10.2 name, the preserved names, the three approved Finance changes, groups, and that the ticket time tracker markup is untouched |
| `Feature/Billing/InvoiceLineItemFormTest` | 12 (95) | deterministic ids and names, first-row visible labels, remove name, `__INDEX__` template, errors lined up by key and old values kept, the **removed-middle-row regression** (next index 3, nine unique names, the pre-fix seed really collides), next index for four key shapes, unchanged submission, script has no inline style or row count |
| **New total** | **245 (1,365)** | |

Existing suites, unedited: Helpdesk (`tests/Feature/Tickets`), invoices (`Billing`), CRM, admin, CMS, `BladeShellTest`, `ShellContractTest`, `NavigationBuilderTest` and `InvitationTest`: with the new line-item suite, **387 passed (2,412 assertions)**, 96 s. **No existing assertion was flipped**: none pinned legacy style (§3.6), and no product or security assertion was touched. The theme contract tests (`DirectionDThemeContractTest`) are unchanged: no alias is retired.

**`./dev check`** (run alone, after the focused evidence was stable): **All checks passed, exit 0.** CLI self-tests **196 assertions**; `git diff --check` pass (the untracked files, which it does not read, were scanned separately: no trailing whitespace); Pint pass (**319 files**, the new tests were reformatted once); frontend `npm run check` pass (`wayfinder:generate`, `tsc --noEmit`, ESLint, Prettier, **Vitest 99 files / 1,684 tests**, `vite build` **424 modules**); full Pest **2,169 passed (12,116 assertions)**, 559 s, which includes the 245 new tests above; no existing test changed.

### A1.12 Mutation checks

Each defect was introduced on its own, the matching tests were run and confirmed to fail, and the file was restored (verified byte-identical with `cmp` against a scratchpad copy; no scratch artifact is left in the tree):

| Mutation | Result |
|---|---|
| Removed `for="title"` from the `Subject` label in `tickets/create` | 3 tests fail: the target-page contract (`unnamed <input> name="title"`), the failed-submission contract, and the new-ticket name test |
| Removed `focus-visible:outline-focus` from `x-ui.input` | 3 tests fail: the exact-focus-ring test, the field class contract, and the seam rule scan |
| Made `x-ui.input` drop `{id}-error` from `aria-describedby` | 13 tests fail: the identity and merge tests, the error-key and wildcard tests, the detector's truthful-form test, and all 7 failed-submission contract cases |

Separately, the first browser run proved the focus walk has teeth: it found the `x-ui.link` outline fade (A1.3).

### A1.13 Browser evidence

`tests/Browser/blade-theme-controls.spec.ts` (new, 15 tests): every route in **light and dark at 1440 and 390**; **no document-level horizontal overflow**; Tab through `main` with every focus stop required to show the 2px solid `focus` outline in the current theme's token (real transitions on); every visible field resting on `control-edge` over `surface`; primary actions computing to `ink` / `on-ink`; secondary to `surface` with `control-edge`; destructive triggers to `danger` and not `ink`; checkbox `accent-color` equal to the `accent` token and not `ink`. Colours are compared against the live `--ds-*` values per theme. Every target-control lookup is scoped to `#main-content` (the shell's utility bar has controls of its own, such as the timer pill, and a parallel spec can leave one running), and every page-header action (`New Ticket`, `+ New Contact`, `+ New Company`, `New Invoice`, `New Role`, `+ New Page`) is checked in every mode to be inside the viewport, on one line inside its own box, and reachable at its centre (A1.18). Routes:

| Area | Routes |
|---|---|
| Helpdesk | `/operator/tickets` (filters, select-all and bulk bar), `/operator/tickets/reports`, an existing operator ticket page (reply, status, assignee), member `/tickets`, `/tickets/create` |
| Directory | `/crm/contacts`, `/crm/contacts/create`, `/crm/companies`, `/crm/companies/create`, `/organizations`, a created company's edit page (danger trigger, danger-zone border, **the native `confirm()` text** and dismissal), a created contact's edit page |
| Finance | `/billing/invoices`, `/billing/invoices/create` (the approved name "Description"), the line-item add / remove / add flow (unique ids, names `0, 2, 3`, "Unit Price", "Remove line item", focus on an added row), a created invoice's page (`Mark as Sent` ink, `Delete` danger, `Record Payment` ink, the approved name "Notes"), member `/my/invoices` |
| System | `/admin/users` and the invite modal (id `invite-email` kept), `/admin/roles`, `/admin/roles/create` (the permission group and the selection accent), `/operator/cms`, `/operator/cms/create`, a created page's edit page |
| Pagination | 26 companies created through the form: numbered pages, `aria-current` as the ink segment, summary, query string preserved on page 2, focus ring on a pagination link; the compact previous / next at 390 |
| Required consumer | `errors/403`: the member requesting `/admin/users` (HTTP 403, `Go back` secondary, `Dashboard` ink) |

**Recorded run** (`./dev test:e2e` with the default 3 workers): `blade-theme-controls.spec.ts` (15), `time-migration.spec.ts` and `blade-shell.spec.ts`: **3 specs, 39 tests, 39 passed, 3 workers, 3.4 minutes**. `time-migration.spec.ts` was **not edited** and its `/Start Timer/` names resolve (the ticket page that hosts the tracker changed; the tracker did not). **Product-data counts, before and after: projects 2 / 2, tasks 1 / 1, time entries 2 / 2 (unchanged)**; the spec's own records (companies, contacts, invoices, CMS pages, 26 pagination companies) are deleted through the application's DELETE routes in `afterEach` and the development database holds 0 companies, 0 contacts, 0 invoices, 0 CMS pages and 0 `e2e-wp1` roles afterwards. HTTP over the whole working window (iterations, screenshots and the recorded run): **0 `5xx`, 0 `429`, 0 `419`**; 15 expected `403`s (the 403 case and `blade-shell`'s); 54 `499`s, which are navigations aborted by the next `goto`. Earlier iterations of this spec failed for test reasons only (a shell link sharing a name, the date input's native calendar stop, a regex against un-normalised text) plus the real `x-ui.link` focus-fade defect fixed in A1.3.

### A1.14 Visual evidence

Screenshots (light and dark, 390 and 1440; the scratchpad, **not** in the repository) of: Helpdesk new-ticket form, member list and operator queue; Directory contact form, list and company edit with its danger zone; Finance invoice form, list and invoice page; System users list and role form (44 images). The author initially inspected 10 of the 44 images; the independent reviewer (A1.18) inspected all 44 (11 surfaces x light / dark x 1440 / 390). Reviewer findings: `New Ticket` and `New Invoice` overflowed their fixed-height button at 390 (fixed in the remediation, A1.18 R1); every other reviewed surface passed; field edges are visible in both themes; no generic blue or indigo control remains. The screenshots remain outside the repository. Reviewed: ink primary and semantic secondary buttons, `control-edge` field boundaries, the 2px focus ring (cyan in dark, teal in light), the danger trigger, no indigo or blue on any generic action, layout preserved. Not reviewed, by design: page-body colour (the legacy gray cards and text remain in both themes until WP3), status pills (WP2).

### A1.15 Findings and deviations

Deviations (recorded, not defects):

1. **`App\Support\FieldState`.** A small static helper holds the §7.5 rules for five components (A1.3); the epic expected "no PHP beyond attribute handling".
2. **The ticket time tracker is untouched.** §13.1's PR 1 column lists its start/stop buttons, but §9.9, §19 WP2 and R12 assign the script rework (`data-*` hooks, server-rendered states) to WP2, and migrating the buttons alone would rebuild markup through the script's inline-colour `innerHTML`. `time-migration.spec.ts` is green unedited.
   **WP1 review ruling: the embedded ticket time tracker is WP2 scope. The earlier §13.1 PR 1 cell is superseded by this ruling.** Reasons: (a) its buttons were not among the 44 missing-focus sites that WP1's focus exit criterion counts; (b) they did not use the generic accent fill that WP1 retires (the `var(--accent)` primary-fill census excludes them); (c) partially migrating the Blade button markup in WP1 would leave the JavaScript-created tracker markup (the `innerHTML` strings) inconsistent with it; (d) leaving the tracker untouched does not prevent any WP1 exit criterion. The tracker code and `time-migration.spec.ts` are unchanged.
3. **Field errors where a page had none.** Where an `x-ui` field is invalid-aware, its `{id}-error` must exist, so a `field-error` was added next to fields whose page never showed an inline error: `body`, `attachments`, `status`, `assignee_id` on the two ticket detail pages, the bulk bar's `action` / `assignee_id`, `user_id` / `role` on `organizations/show`, `amount` / `notes` on `billing/invoices/show`, and the header, quantity and unit-price fields of the invoice form. The message text is Laravel's own and unchanged, but a validation message that was previously swallowed on the member ticket page and `organizations/show` is now visible. Owner awareness, not a rule change.
4. **A wrapped checkbox with a non-simple name needs no `id`** (the §7.3 checkbox row), so `checkbox` alone passes `requireId: false`; `input`, `select` and `textarea` still throw.
5. **Visible column labels follow the first rendered row** (`$loop->first`), not `$i === 0`: after row 0 is removed and the submit fails, the old rule rendered no visible labels at all.
6. **`min-w-45` on the operator queue search** (§12.3 assigns it to PR 3) because the inline `min-width:180px` style left with the field's inline styles.
7. **The `<template>` row carries `__INDEX__` in `name`, `id` and `label[for]` but no `aria-describedby`**: a template row never has an error or a hint, so there is nothing to describe (the script replaces every `__INDEX__`, so a future hint would be covered).
8. **`Pay Now` on the operator invoice page is secondary** (the literal reading of §8.3); it is primary where it is the page's own action (`billing/client/show`).
9. **A WP1-scoped residue test**, `BladeControlAdoptionTest`, checks controls only (the PR 1 exit). It is not the §17 guard, which stays PR 4.

Pre-existing defects found and **deliberately preserved** (each would be a behaviour change):

- **`admin/roles/edit`: the `Delete Role` form is nested inside the update form (PRE-EXISTING, HIGH SEVERITY, OUT OF EPIC-016 WP1 SCOPE).** The earlier WP1 description of this defect ("the Delete Role button's form is the update form, so that button submits the update, not the delete") was inaccurate and is replaced by the following. Mechanism: Chromium parses one effective form; the nested form's boundary, and with it its `onsubmit` `confirm()`, are lost. The submitted payload contains both `_method=PUT` (the outer form's, first) and `_method=DELETE` (the inner form's, later), and PHP resolves the duplicate `_method` to the **last** value, `DELETE`. Because update and delete share `/admin/roles/{role}`, for a `roles.admin` user on a custom role **both "Save Changes" and "Delete Role" issue `DELETE`, with no confirmation**: an unassigned custom role can be deleted unexpectedly, and an assigned role refuses deletion ("Cannot delete ... assigned to N user(s)") instead of saving. Evidence: the independent review reproduced the parse and the payload in Chromium with a static fixture (no application submission, no database) and confirmed PHP's last-wins `parse_str` behaviour. WP1 did not introduce the nesting and did not change behaviour (the migration kept the structure and the `confirm()` exactly); it only makes `Delete Role` visually more prominent (the danger trigger). **Destination:** a dedicated System / admin behavioural hotfix **before WP2 starts**. Expected fix: move the delete form outside the update form, or use a valid external form through the `form=` attribute, with browser regression evidence for Save versus Delete and for the confirmation. **Resolution (2026-10-06): CLOSED** by the dedicated hotfix recorded in [A1.19](#a119-carried-finding-the-delete-role-form-hotfix).
- The queue filter placeholders read "All Statuss" / "All Prioritys" (copy; unchanged). Destination: a Helpdesk copy follow-up.
- At 390 the invoice line-item quantity and unit-price columns are narrow (`col-span-2` / `col-span-3`); the geometry is as before. Destination: Finance product work.

Non-blocking observations from the independent review (recorded, **not fixed** in WP1):

| Observation | Destination |
|---|---|
| The "Choose Files" text sits high in the `h-9` file input (cosmetic) | WP3 geometry / field polish |
| Bulk-bar field errors (`action`, `assignee_id`) render inside the bulk bar, which stays hidden after the redirect; the page-level error banner already shows `$errors->first()` | Helpdesk product work |
| On `organizations/show` the `role` field-error could also surface a member role-update error (a hidden-input value; practically unreachable) | Directory product work |
| The `mailto:` link on `crm/contacts/show` uses the `row` link variant, which adds `font-medium` | WP3 review |

### A1.16 Left for the next packages

- **WP2:** `x-ui.status` / `priority` / `alert` / `tag` and every status, priority, overdue, internal-note, role-type, flash and `#payment-message` presentation; the time tracker (`data-*` hooks, live semantics, `Start Timer` / `Stop` as `x-ui.button`); report figures; the MFA "Enabled" status.
- **WP3:** every remaining raw colour variable and inline style in the target views (639 / 465 after WP1), the dead `hover:legacy-bg-surface` classes, the 403 numeral, the invite-modal scrim, the 12px to 8px card radius, `errors/403` colour, the `--ds-*` value pin and the palette-conformance and reference-comparison browser checks.
- **WP4:** alias retirement and the contract-test absence pins, the vendor pagination `@source` line, the permanent two-level guard and the final census.

### A1.17 Files changed

**New:** `app/Support/FieldState.php`; `resources/views/components/ui/{button,link,input,select,textarea,checkbox,label,field-error}.blade.php`; `resources/views/pagination/{direction-d,simple-direction-d}.blade.php`; `resources/views/billing/invoices/_line_item.blade.php`; tests `tests/Unit/Ui/{ButtonAndLinkComponentTest,FieldComponentsTest,PaginationViewTest,AccessibilityContractDetectorTest}.php`, `tests/Unit/Configuration/BladeControlAdoptionTest.php`, `tests/Feature/Ui/{TargetFormAccessibilityTest,TargetAccessibleNamesTest}.php`, `tests/Feature/Billing/InvoiceLineItemFormTest.php`, `tests/Support/UiHtmlHelpers.php`, `tests/Browser/blade-theme-controls.spec.ts`.
**Changed:** `app/Providers/AppServiceProvider.php` (pagination defaults); 36 existing target views (A1.9, the §13 PR 1 inventory).
**Docs:** this amendment (including A1.18). EPIC-016 stays **Planned**. The review remediation changed two of the 36 views (`tickets/index`, `billing/invoices/index`: `class="shrink-0"`) and the Playwright spec; the path count is unchanged.
**Scope check against `d07384d`:** 60 paths (47 views, 10 test files, `FieldState`, `AppServiceProvider`, this document); nothing under `resources/js`, `resources/css`, `routes`, `database`, `config`, `.github` or any manifest, and neither the time tracker nor the status badge partials.
**Not changed:** `app.css`, any token, any file under `resources/js`, routes, controllers, policies, validation, models, migrations, dependencies, CI, `docs/epics/README.md`, the roadmap, the time tracker, the status badge partials.

### A1.18 Independent review and remediation

**Verdict: needs small remediation.** The review approved the architecture (the `x-ui` components, `App\Support\FieldState`, the names, errors and focus contracts, the action hierarchy, checkboxes, pagination, the invoice line-item index fix), upheld the ruling that `Pay Now` is secondary on the operator invoice page (A1.15 #8) and approved `min-w-45` on the queue search (A1.15 #6). Its screenshot findings are in A1.14, its Delete Role finding in A1.15, its pagination-name finding in A1.7, its non-blocking observations in A1.15, and the time-tracker ruling in A1.15 #2.

**Independent evidence.** The reviewer's Pest run: **236 passed (954 assertions)**. The reviewer's Playwright run: **27 passed, 1 failed**; the failure was `getByLabel('Description')` resolving to 3 elements, including the shell timer pill "Stop timer: First paint regression with a long description" created by `time-migration.spec.ts` running in parallel (a test-locator defect, not a product defect).

**Remediation applied.**

- **R1, 390px header actions (production).** `New Ticket` (`tickets/index`) and `New Invoice` (`billing/invoices/index`) wrapped to two lines inside the fixed `h-9` button at 390. Each now passes `class="shrink-0"` (a layout class only), the pattern `Invite User` already used. `New Role`, `+ New Page`, `+ New Contact` and `+ New Company` were measured with the new assertion first, on the unfixed markup, in all four modes (light / dark x 1440 / 390): they do not wrap, so they are unchanged. The new assertion failed on the unfixed `New Ticket` and `New Invoice` (label on 2 lines, expected 1) and passes after the fix.
- **Browser assertion.** `expectHeaderActionIntact` in `blade-theme-controls.spec.ts`, called for all six header actions in every mode: the control is visible, its left edge is at or after 0 and its right edge at or before the viewport width, the label occupies exactly one line box, the label lies inside the control's top and bottom edges, and `elementFromPoint` at the control's centre is the control.
- **R2, locator interference (test).** Every `page.getBy*` lookup of a target control in the spec (96) is now `inMain(page).getBy*`, with `inMain = page.locator('#main-content')`, so shell controls (timer pill, `Start timer`, navigation, user menu) cannot collide; the shell-level assertions the spec makes (`#main-content` visibility, the theme attribute, `hasHorizontalOverflow`) stay on `page`. The new-ticket description lookup also asserts the field is the `textarea` named `description` with id `description`. No `.first()` / `.nth()` was added, and no retry, sleep or worker change.
- **R3 to R7, documentation only:** A1.5, A1.7, A1.14, A1.15 (time tracker, Delete Role, observations) and the status block above, as recorded in those sections.

**Validation after remediation.** `git diff --check` clean. Focused Pest (`tests/Unit/Ui`, `BladeControlAdoptionTest`, `tests/Feature/Ui`, `tests/Feature/Billing`): **264 passed (1,409 assertions)**, the 245 new tests (1,365 assertions) plus the 19 existing Billing tests; with `tests/Feature/Tickets` added, 425 passed (2,009 assertions). Focused Playwright (`blade-theme-controls.spec.ts`, `time-migration.spec.ts`, default workers, which was 2): **28 passed, 0 failed, 0 skipped, 3.1 minutes**; product-data counts before and after: projects 2 / 2, tasks 1 / 1, time entries 2 / 2 (unchanged). `./dev check` (alone): **All checks passed, exit 0**; CLI self-tests 196 assertions; `git diff --check` pass; Pint pass (319 files, no changes); frontend `npm run check` pass (Vitest 99 files / 1,684 tests, `vite build` 424 modules); full Pest **2,169 passed (12,116 assertions)**. The test counts in A1.11 are unchanged: the remediation added assertions to the existing Playwright spec (still 15 tests) and no Pest test.

### A1.19 Carried finding: the Delete Role form hotfix

> **Status (2026-10-06): CLOSED by `hotfix/system-role-delete-form`**, a short-lived branch from `origin/main` (`6dfc115`, after WP1 merged), delivered before WP2. It is a behavioural hotfix to a pre-existing System defect and is **not** part of any EPIC-016 package; no WP2, WP3 or WP4 work is in it.

**Root cause (verified before editing).** `admin/roles/edit` rendered the `Delete Role` `<form>` as a child of the update `<form>`. Forms do not nest in HTML: the parser drops the inner start tag, so the page had one effective form owning both buttons, the update form's `_method=PUT` and the delete form's `_method=DELETE` (PHP keeps the last), and no `onsubmit` for the confirmation. `PUT` and `DELETE` share `/admin/roles/{role}`, so for a `roles.admin` user on a custom role both buttons deleted the role without the confirmation. The hotfix reproduced it in a real browser before the fix: on the unfixed markup, Save Changes submitted `_method=PUT` **and** `_method=DELETE`.

**Fix.** Markup only, in `resources/views/admin/roles/edit.blade.php`: the delete form is a sibling after the update form (`id="role-delete-form"`, same action, `@method('DELETE')`, the same native `confirm()` with the same wording), and the existing `Delete Role` button stays in the action row, bound to it with `form="role-delete-form"`. Layout, the WP1 `x-ui.button` danger-trigger styling, the `@can('roles.admin')` and built-in-role conditions, routes, controller, validation, redirects and flashes are unchanged.

**Contract.** Save Changes submits the update form only (`PUT`, no confirmation, existing validation, authorization and redirect); Delete Role submits the delete form only (`DELETE`, after the native confirmation); built-in roles and assigned roles are still refused by the controller; a built-in role, or a user without `roles.admin`, still gets no delete control.

**Regression evidence.**
- **Structural evidence (Pest), `tests/Feature/Admin/RoleEditFormTest.php`** (8 tests, 55 assertions). It proves the corrected markup, not Chromium's old parsing: two valid sibling forms (nesting depth 1), each button owned by the right form (nearest ancestor `<form>`, or the `form=` attribute), method isolation (the update form owns `_method=PUT` only, the delete form `_method=DELETE` only), the confirmation on the delete form only, accessible names and unique ids, no delete control for built-in roles or without `roles.admin`, the save-only and delete-only request paths (checked through the activity log), and assigned-role refusal leaving the role and user untouched. Of the three tests that fail on the previous markup, **one directly catches the malformed nesting** (the depth check, which the form-structure test and the ownership test now both make); the other two fail because `#role-delete-form` does not exist there, and are supporting structural evidence rather than independent proof of the root regression.
- **Behavioural evidence (Playwright), `tests/Browser/role-delete-form.spec.ts`** (new), the authoritative proof. It drives the real controls in Chromium on a throwaway role: Save Changes sends `PUT` only (the collision, `_method=PUT` plus `_method=DELETE` from Chromium's collapse of the nested form, is what it caught on the old markup), raises no dialog, and the change persists; `Delete Role` raises the native `confirm()`, dismissing it by pointer and by keyboard (`Enter`) deletes nothing, and accepting it sends `DELETE` and removes the role; the role is removed afterwards through the application's own route. **It fails on the previous markup** (`['PUT', 'DELETE']` received where `['PUT']` is expected).
- Not covered in the browser: the assigned-role refusal (it needs a second account; the Pest suite pins it).
- Maintainability note (accepted, non-blocking): the `@can('roles.admin')` and `@if (! $isBuiltIn)` conditions appear twice in `edit.blade.php` (around the button and around the separate delete form), so editing one without the other could leave the button pointing at a missing form. Left as is to keep the hotfix diff minimal.
