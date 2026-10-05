# Direction D — Design System Specification

**Status:** Canonical design direction (approved 2026-09-25; revision 2, 2026-09-25: migration safety, timer scope, navigation semantics, optimistic presentation, brand asset, avatars). This is a design contract for implementation planning, not an implementation epic.
**Visual reference:** Claude Design canvas "Intechral Portal — Visual Directions", rows **D** (D1–D5) and **D · State studies** (D6–D9). Rows A–C are historical exploration only.
**Repository home:** `docs/design/direction-d-design-system.md`
**Reference token file:** `docs/design/direction-d-tokens.css` — reference artifact only; not the production stylesheet.
**Reference token file:** `direction-d-tokens.css`, the exact stylesheet every D artboard is built from. It is a **reference artifact only**, not the production stylesheet. Its class names (`.rail`, `.st`, `.bar` …) are mockup scaffolding. Production tokens are implemented in `src/resources/css/app.css` using the semantic names in §2, and production components are built from the specification, not copied from the reference CSS.

Wording follows `docs/product/README.md`: **Current** = implemented today, **Target** = chosen and intended, **Future** = plausible later. Mockups mark Next/Future features with `NEXT` / dashed `FUTURE` tags; implementation must not ship those tags or imply unbuilt features exist.

---

## Contents

1. [Locked decisions](#1-locked-decisions)
2. [Tokens](#2-tokens)
3. [Typography](#3-typography)
4. [Space, radius, rules, elevation](#4-space-radius-rules-elevation)
5. [Shell dimensions and responsive states](#5-shell-dimensions-and-responsive-states)
6. [Operator shell anatomy](#6-operator-shell-anatomy)
7. [Customer shell anatomy](#7-customer-shell-anatomy)
8. [Navigation and selection rules](#8-navigation-and-selection-rules)
9. [Content widths and inspectors](#9-content-widths-and-inspectors)
10. [Status and priority semantics](#10-status-and-priority-semantics)
11. [Progress: staged and continuous](#11-progress-staged-and-continuous)
12. [Global timer](#12-global-timer)
13. [Account menu](#13-account-menu)
14. [Focus and keyboard](#14-focus-and-keyboard)
15. [Loading, empty, error, disabled, pending](#15-loading-empty-error-disabled-pending)
16. [Motion](#16-motion)
17. [Brand motifs](#17-brand-motifs)
18. [Component inventory for the foundation](#18-component-inventory-for-the-foundation)
19. [Implementation order](#19-implementation-order)
20. [Open questions](#20-open-questions)

---

## 1. Locked decisions

| Area | Decision |
|---|---|
| Operator shell | 64px **rail** + optional 248px **contextual drawer** + 48px **utility bar** |
| Customer shell | 60px **top bar**, no rail, no drawer, centred content column |
| System | One token set and one component library shared by both shells |
| Type | **IBM Plex Sans** (UI), **IBM Plex Mono** (IDs, time, money, counts), **Newsreader** (entity names and customer display headings only) |
| Light theme | Warm paper: `#F6F5F1` canvas, white surfaces, ink `#1A1B1E` |
| Dark theme | Teal-black: `#0D1416` canvas, `#142023` surfaces |
| Accent | Light: deep teal `#0B6A73` (accessible). Dark: softened cyan `#7ADDE4` for links |
| Brand cyan `#19E7F2` | Reserved for **live/current** state, mainly in dark mode |
| Primary action | **Ink** button in both themes (dark on light, light on dark); never a cyan fill |
| Progress | **Staged** for named lifecycles; **continuous** for quantities |
| Motifs | Strata and steps are rare and structural (§17) |
| Layout philosophy | Structure over boxes; cards only for bounded objects, decisions, summaries, interactions |
| Inspectors | Optional and surface-specific, never a shell fixture |
| Migration | Incremental, using compatibility aliases; no intentional regressions to existing screens (§2.4) |
| Brand assets | Canonical full and compact owner-supplied SVGs are tracked under `src/resources/images/brand/` and are authoritative (§17.1) |

---

## 2. Tokens

### 2.1 Implementation shape

- Semantic CSS custom properties on `:root[data-theme="light"]` and `:root[data-theme="dark"]` (the existing no-flash `data-theme` contract from EPIC-011A is kept).
- Exposed to Tailwind v4 through `@theme inline` so utilities read `bg-surface`, `text-muted`, `border-rule` and so on.
- Components adopting Direction D consume **semantic** tokens only. Raw palette values appear in the theme layer, nowhere else.
- The existing indigo `--color-brand-*` scale, the gray-based raw variables (`--bg-*`, `--text-*`, `--border-*`, `--surface-*`) and the shadcn aliases (`background`, `foreground`, `primary`, `muted`, `border`, `ring`, …) are **kept as compatibility aliases** during migration (§2.4). They are not deleted when the new layer lands.

### 2.2 Colour tokens

| Token | Light | Dark | Use |
|---|---|---|---|
| `canvas` | `#F6F5F1` | `#0D1416` | Page background behind content |
| `rail` | `#EBE8E1` | `#091012` | Operator rail; customer top bar |
| `drawer` | `#F1EFEA` | `#10191C` | Contextual drawer |
| `surface` | `#FFFFFF` | `#142023` | Cards, popovers, inputs, table body where raised |
| `surface-sunken` | `#EFEDE7` | `#0F181A` | Group headers in tables, footers of popovers |
| `surface-hover` | `#E8E5DD` | `#162427` | Hover on nav rows and list rows |
| `surface-selected` | `#FFFFFF` | `#18292D` | Selected nav item surface (drawer, rail) |
| `rule` | `#E3E0D8` | `#1E2D31` | Row separators, panel borders |
| `rule-control` | `#D2CEC3` | `#2A3D42` | Structural control-adjacent hairlines: chip and tag borders, table header rule, unfilled priority bars, disabled control edges. **Not** the boundary of an interactive control (see `control-edge`); at about 1.5:1 it is too faint to identify one |
| `control-edge` | `#8B877C` | `#617679` | Resting boundary of interactive controls that have no fill of their own to identify them: text inputs, textareas, native selects, secondary buttons, the rail account button. At least 3:1 against `canvas`, `surface`, `drawer`, `surface-sunken` and the legacy page and card backgrounds in both themes (WCAG 1.4.11). Never for table rules, section rules or decorative separators |
| `rule-strong` | `#1A1B1E` | `#C8D7D9` | Section title rule; strongest strata line |
| `text` | `#1A1B1E` | `#E6EEEF` | Primary text |
| `text-secondary` | `#3F4248` | `#C3D1D3` | Secondary body text, nav labels |
| `text-muted` | `#5C5F66` | `#98AEB2` | Metadata, column headers (meets 4.5:1 on canvas and surface) |
| `text-faint` | `#8A8D93` | `#6D8388` | Decorative glyphs and non-informational affordances only. **Never** for information-bearing text: not placeholders, and not the label or value of a disabled control |
| `accent` | `#0B6A73` | `#7ADDE4` | Links, informational status, accent text |
| `accent-hover` | `#084E55` | `#B2F4F7` | Link hover |
| `accent-soft` | `#E3EEEE` | `#12292C` | Selected-row tint, active filter chip, decision card (customer) |
| `accent-line` | `#0B6A73` | `#19E7F2` | Drawer selection strip, checked checkbox fill |
| `live` | `#0B8792` | `#19E7F2` | Running timer dot, current stage, live row time |
| `live-soft` | `#E4F2F2` | `#0E2629` | Row tint for "running here", timer dot halo, count badge |
| `live-text` | `#0B6A73` | `#19E7F2` | Running times, current-stage labels, count-badge text (text-safe live: 5.2:1+ on `live-soft` in light) |
| `ink` | `#1A1B1E` | `#E6EEEF` | Primary button fill, selected segmented control |
| `on-ink` | `#FFFFFF` | `#0D1416` | Text on `ink` |
| `danger` | `#B42318` | `#FF8A7A` | Overdue, off track, destructive, errors |
| `danger-soft` | `#FBEAE7` | `#2A1715` | Error banners, destructive-hover backgrounds |
| `warning` | `#8A5A0B` | `#F2B45A` | Warning **text** |
| `warning-glyph` | `#B7791F` | `#F2B45A` | Warning **glyphs and fills** (at-risk triangle, overage segment) |
| `warning-soft` | `#FAF0DC` | `#2A2013` | Warning banners |
| `success` | `#2B7A4B` | `#6FD39A` | Success text |
| `success-glyph` | `#2E8B57` | `#4CCB86` | Success glyphs |
| `progress-fill` | `#1A1B1E` | `#C8D7D9` | Continuous bar fill; completed stage |
| `progress-track` | `#E4E1D9` | `#1E2D31` | Bar track |
| `stage-future` | `#C9C5BA` | `#34494E` | Dashed outline of planned stages |
| `focus` | `#0B6A73` | `#19E7F2` | Focus ring |
| `scrim` | `rgba(26,27,30,.18)` | `rgba(0,0,0,.45)` | Modal dialogs and mobile sheets only |

**Shadows**

| Token | Light | Dark |
|---|---|---|
| `shadow-card` | `0 1px 2px rgba(26,27,30,.05), 0 0 0 1px #E3E0D8` | `0 0 0 1px #213136` |
| `shadow-overlay` | `0 14px 36px rgba(26,27,30,.16), 0 0 0 1px #E3E0D8` | `0 14px 36px rgba(0,0,0,.55), 0 0 0 1px #2A3D42` |

### 2.3 Semantic usage rules

1. **Accent vs live.** `accent` means "interactive or informational". `live` means "happening now" (a running timer, the current stage). Never use `live` for links or buttons, and never use `accent` to indicate that something is running.
2. **Cyan discipline.** Pure `#19E7F2` appears only as `live`, `accent-line` and `focus` in dark mode. It is never a large fill, background or button colour.
3. **Live text uses `live-text`.** `live` is for glyphs, dots, segments and bars only. In light mode `live` (`#0B8792`) measures 4.29:1 on white and 3.73:1 on `live-soft`, which fails AA for text. All live-coloured **text** (running times, the current-stage label, the `+N` badge) uses `live-text`, which is deep teal in light and cyan in dark.
4. **Warning has two tokens.** Text uses `warning`; shapes use `warning-glyph`, because the lighter amber that reads well as a shape fails as text on paper.
5. **Ink is primary.** One primary (ink) button per region. Secondary actions are outline (`surface` + `control-edge`). Ghost buttons are for toolbars and icon actions.
6. **Soft tints are rare.** `*-soft` backgrounds are for selected rows, running rows, active filters, banners and the customer "waiting on you" card. They are not decorative.
7. **Text-faint is non-informational.** If removing the text would lose information, it must not be `text-faint`. That includes placeholders (WCAG requires 4.5:1 for them) and the label or value of a disabled control, which use `text-muted`.
8. **Interactive boundaries use `control-edge`.** A control whose visible boundary is what identifies it (an input, a select, a secondary button) draws it 1px in `control-edge`, which meets the 3:1 non-text contrast of WCAG 1.4.11. `rule-control` and `rule` are structural hairlines and are not used for that role. Hover strengthens the edge to `text-muted`, an invalid field recolours it `danger`, and focus adds the 2px `focus` outline, so rest, hover, invalid and focus are all distinguishable.
9. **Future themes** add a new `[data-theme]` block with the same token names. No Direction D component may reference a hex value.

### 2.4 Migration compatibility (implementation constraint)

The redesign must be **incrementally shippable without intentionally destabilising existing screens**. This is a hard constraint on the foundation epic.

1. **Add, don't swap.** Introduce the Direction D semantic tokens alongside the existing variables. Landing the token layer must not, by itself, change what existing React and Blade screens depend on in ways that break them.
2. **Keep legacy aliases.** The shadcn aliases, the raw `--bg-*` / `--text-*` / `--border-*` / `--surface-*` variables, the status `surface-*`/`border-*`/`text-*` families and the `brand-*` scale remain defined for as long as anything consumes them.
3. **Map aliases to compatible values.** Where it's safe, point legacy aliases at the nearest Direction D value (for example `background` → `canvas`, `foreground` → `text`, `muted-foreground` → `text-muted`, `border` → `rule-control`, `ring` → `focus`) so older screens move toward the new look without restructuring. Where a mapping would reduce contrast, break a control's affordance, or make an element disappear against its background, keep the legacy value until that consumer migrates.
4. **Adoption is deliberate and per surface.** A page, layout or component "adopts Direction D" when it is intentionally rebuilt against the semantic tokens and components in this spec. Unmigrated screens are expected to look transitional; they do not need to be pixel-identical to today.
5. **What must not break:** readability, text/background contrast (WCAG AA), visible control boundaries, focus visibility, layout integrity (no overlaps, clipping or collapsed regions), and dark-mode legibility on both renderers. Knowingly shipping any of these regressions because "the theme layer landed" is not acceptable.
6. **Known undefined or hard-coded values** in the current UI (for example `--surface-accent`, `--surface-elevated` and `--accent-success` in Blade badges and board columns, and hard-coded hex badge colours) should get compatible definitions or be left untouched. They must not silently resolve to transparent or unreadable values.
7. **Retire aliases only after their consumers migrate.** Each alias is removed in the slice that migrates its last consumer, with a search showing no remaining references. Alias retirement is tracked work, not a side effect.
8. **Verification per slice:** the existing gate (`./dev check`) plus a visual pass of representative unmigrated React and Blade screens in both themes after any token-layer change.

---

## 3. Typography

### 3.1 Families

| Role | Family | Weights | Fallback |
|---|---|---|---|
| UI | IBM Plex Sans | 400, 500, 600 | `system-ui, sans-serif` |
| Data | IBM Plex Mono | 400, 500 | `ui-monospace, monospace` |
| Display | Newsreader (opsz) | 400, 500 | `Georgia, serif` |

Mono always uses `font-variant-numeric: tabular-nums`. The current Inter and JetBrains Mono fonts (loaded from fonts.bunny.net) are replaced at the base layer. Under §2.4, the font swap on unmigrated screens is acceptable only if it causes no clipping, truncation of critical labels, or broken layouts. Font hosting is an open question (§20).

### 3.2 Scale

| Style | Family | Size / line-height | Weight | Tracking | Use |
|---|---|---|---|---|---|
| `display-xl` | Newsreader | 46 / 1.05 | 400 | -0.015em | Customer entity headline |
| `display-lg` | Newsreader | 34 / 1.05 | 400 | -0.015em | Operator entity header (project, organization, person) |
| `display-md` | Newsreader | 23–24 / 1.3 | 400 | 0 | Customer decision text; compact entity header on wide surfaces |
| `title` | Plex Sans | 26 / 1.2 (24 on dense surfaces) | 600 | -0.02em | Operator page title (Home, Tasks, Helpdesk…) |
| `lead` | Plex Sans | 17 / 1.5 | 400 | 0 | Customer status sentence |
| `summary` | Plex Sans | 14 / 1.5 | 400 | 0 | Operator page summary sentence |
| `section` | Plex Sans | 14.5 / 1.3 | 600 | -0.005em | Section titles (over `rule-strong`) |
| `body` | Plex Sans | 14 / 1.55 | 400 | 0 | Body, forms, conversation (14.5–15 at reading width) |
| `row` | Plex Sans | 13–13.5 / 1.35 | 400 / 500 | 0 | Table and list rows; 500 for the primary cell |
| `meta` | Plex Sans | 12.5 / 1.4 | 400 | 0 | Secondary row lines, captions |
| `label` | Plex Sans | 12 / 1.3 | 500 | 0 | Column headers, drawer group headings, field labels |
| `overline` | Plex Mono | 11.5 / 1.2 | 400 | 0.05em, uppercase | Date and ID overlines above titles |
| `tag` | Plex Mono | 9.5–10 / 15px box | 400 | 0.06em, uppercase | NEXT/FUTURE markers, source tags |
| `num-lg` | Plex Mono | 22–24 | 500 | -0.02em | Monitoring figures |
| `num` | Plex Mono | inherits | 400 / 500 | 0 | IDs, elapsed times, money, counts |

### 3.3 Rules

- **Newsreader is only for identity and customer voice:** the name of the entity a page is about, the customer's headline, and customer decision prompts. It is never used for page titles like "Tasks", section headings, tables, buttons or numbers.
- Operator page titles stay at 24–26px. No oversized dashboard headings.
- Money, time, IDs and counts are always mono. Right-align numeric columns.
- Uppercase is only for `overline`, `tag` and the short card eyebrow labels ("DECISION WAITING").
- The minimum text size is 12px. Only 9.5–10px `tag` markers are smaller, and they are never the only carrier of meaning.

---

## 4. Space, radius, rules, elevation

### 4.1 Spacing

Base unit 4px. Scale: `2, 4, 6, 8, 10, 12, 14, 16, 20, 24, 28, 32, 40, 44, 56`.

| Context | Value |
|---|---|
| Dense row height (tables, queues) | 36px (Compact), 44px (Comfortable, Future density preference) |
| List row with two lines | 52–56px |
| Mobile row | ≥ 64px, 44px touch targets |
| Section gap (vertical) | 26–28px |
| Column gap (content grids) | 44px operator, 28–32px customer |
| Page padding (operator) | 40 (XL) / 32 (L) / 24 (M) / 16 (S) |
| Page padding (customer) | 32 horizontal, 40 top |

### 4.2 Radius

| Radius | Use |
|---|---|
| 1px | Stage segments, bar markers |
| 2–3px | Progress bars (half the height), tags |
| 4px | Checkboxes, count badges, compact identity tiles in dense rows (§13.1) |
| 5px | Buttons, inputs, chips, nav rows, timer pill |
| 6px | Rail items and the rail account button, kanban cards, segmented controls |
| 7px | Large buttons (38–44px) |
| 8px | Cards, popovers, menus, overlays, dialogs |
| 50% | Person avatars (default at every size), dots |

Never nest a rounded container inside another rounded container that has a visible edge. Cards inside cards are not allowed.

### 4.3 Rules (lines)

| Line | Token | Use |
|---|---|---|
| Hairline | `rule` 1px | Row separators, panel edges, shell borders |
| Control | `rule-control` 1px | Table header underline; chip and tag borders (structural, not interactive boundaries) |
| Control edge | `control-edge` 1px | Resting boundary of inputs, selects, textareas and secondary buttons (§2.3.8) |
| Section | `rule-strong` 1px | Under every section title (`section` style) |
| Strata | 1px `rule-control` + 1px `text-faint` @ 70% + 2px `rule-strong`, 2px apart | **Only** under entity headers (§17) |

### 4.4 Elevation

| Level | Treatment | Examples |
|---|---|---|
| 0 Flat | No shadow, rules only | Canvas, tables, lists, sections, drawer (docked) |
| 1 Card | `shadow-card` on `surface` | Decision cards, kanban cards, customer summary cards (requests, budget) |
| 2 Overlay | `shadow-overlay` on `surface` | Popovers, menus, timer tray, overlay drawer, bulk-action bar, tooltips (ink) |
| 3 Modal | Overlay + `scrim` | Confirm dialogs, mobile sheets |

**When a card is allowed:** the content is a bounded object (a kanban card, a person chip), a decision (approval or change request), a self-contained summary (the customer budget) or an interaction (the composer). **Not allowed:** wrapping lists, tables, sections or single numbers ("KPI tiles") in cards.

---

## 5. Shell dimensions and responsive states

### 5.1 Dimensions

| Element | Size |
|---|---|
| Rail | 64px wide; items 52px wide, ~46px tall (18px icon + 10px label); logo 28px; account button 40×40 (radius 6) holding a 28px circular avatar |
| Drawer | 248px wide (docked or overlay); header 32px; rows 32px |
| Utility bar | 48px tall |
| Customer top bar | 60px tall |
| Inspector (optional) | 360–392px |
| Context rail on detail pages | 320–340px |
| Reading width | 700px (conversation), 640px (forms), 720px (account/security), 760px (knowledge article) |
| Customer content column | max 1080px, centred |

### 5.2 Width classes (workspace = viewport width)

| Class | Width | Operator shell | Customer shell |
|---|---|---|---|
| **XL** | ≥ 1360 | Rail + drawer **docked or collapsed**, per the workspace default or the remembered state | Top bar, full nav |
| **L** | 1024–1359 | Rail; drawer **collapsed by default**, opens as an **overlay**; pin it to dock (remembered) | Top bar, full nav |
| **M** | 768–1023 | Rail; drawer is **overlay only** (pin is hidden); the inspector becomes a sheet | ≥ 900: top bar; < 900: menu sheet |
| **S** | < 768 | Rail becomes a 56px top bar with a menu button that opens a nav sheet (workspaces + current drawer content); tables reflow to two-line rows (D9) | Menu sheet |

### 5.3 Drawer defaults and persistence (refined rule)

The drawer is **not** universally pinned. Each workspace declares a default, and the user's choice is remembered **per workspace**.

| Workspace | Default at XL | Why |
|---|---|---|
| Home | Open | Queues, decisions and watch lists are the navigation |
| Projects (list, Overview, Milestones, Activity) | Open | Views and recent projects help while switching between projects |
| Projects (Board, Tasks tab, Time tab) | Collapsed | Wide canvas surfaces |
| Tasks | Collapsed | The table is the product; views stay reachable from the breadcrumb view switcher |
| Helpdesk | Open | Queues are the primary navigation |
| Time | Collapsed | Timesheet and allocation need width |
| Directory | Open | People / Organizations / Relationship views |
| Finance | Open | Billing / Invoices / Retainers / Rates sections |
| System | Open | Admin sections |

**Persistence rules**
1. The state is stored per `(user, workspace)` as `open | collapsed`. A per-surface override (such as Board inside Projects) is stored separately only if the user changes it there; otherwise the surface default applies.
2. The remembered state applies at **XL**. At **L**, "open" means docked only if the user pinned it at L; otherwise the drawer opens as an overlay.
3. At **M/S**, the preference is ignored and the drawer is always overlay or sheet.
4. State must be available **before first paint** to avoid layout shift. Recommended: a cookie read by Laravel and shared as an Inertia prop, mirroring the theme's no-flash contract. The server stays authoritative only for data, not this preference.
5. A workspace with exactly one surface and no views has **no drawer**; its rail item links straight to the surface.

> **Forward note (2026-10-05, EPIC-015 WP5).** As implemented and ruled by the owner, these rules are refined as follows; the table and numbered rules above are historical and are not rewritten.
> - **Per-surface defaults.** Projects declares stable surface keys (`projects.board`, `projects.tasks`, `projects.time`), all collapsed; the projects list, Overview and Milestones keep the workspace default (open). A key names a kind of page, never a project or URL. A choice made on a surface is remembered under its surface key only; a workspace choice does not override a surface default. Earlier workspace-level choices are **not** migrated into surface keys.
> - **Where the remembered state is written (rule 1, refined).** Only an explicit open/collapse **at XL** writes the remembered state. Below XL, opening and closing the overlay or sheet (including Esc, outside click and following a drawer link) is transient and never changes the XL preference.
> - **The L pin (rule 2, refined).** The pin is per **workspace** and authoritative at L: a pinned workspace is docked on every one of its pages, whatever a surface default or an XL surface choice says. Pinning at L writes no XL value. Collapsing the drawer while it is docked at L releases the pin; there is no separate Unpin control.
> - **M and S (rule 3).** The pin and the remembered state are both ignored for rendering at M and S.
> - **Before first paint (rule 4).** Implemented without a cookie: the server stamps `data-workspace`, `data-drawer-default` and `data-drawer-surface` on the HTML root, and the inlined shell bootstrap resolves `data-drawer` from `localStorage` before paint (EPIC-013 S1). The preference is therefore per browser, not per user.
> - These are **shared-shell** rules: they apply to every workspace that uses the drawer, not only Projects. One cross-renderer limit is accepted while unreachable: a Blade page has no pin control, so it cannot release a pin set from a React page of the same workspace; no current workspace has both. See [EPIC-015 A5.4 and A5.15](../epics/EPIC-015-projects-ux-expansion.md#a515-independent-review-owner-rulings-and-remediation).

### 5.4 Drawer states (D1, D2, D6)

| State | Appearance | Controls |
|---|---|---|
| **Docked** (D1, D3) | In the layout flow, pushing content; `drawer` background; right border `rule` | Header "collapse" button (panel icon) with `aria-expanded="true"`; `Ctrl+\` |
| **Collapsed** (D2) | Absent; rail shows a toggle button under the logo; the current view name stays in the breadcrumb as a menu button ("All open ▾") | Rail toggle, `Ctrl+\`, breadcrumb view menu |
| **Overlay** (D6) | Floats over content at 248px with `shadow-overlay`, **no scrim**; content stays visible | Header **Pin** (docks and remembers) and **Close**; `Esc`, outside click and navigation close it; focus moves into the drawer on open and returns to the toggle on close |

---

## 6. Operator shell anatomy

```
┌──────┬────────────┬──────────────────────────────────────────────────┐
│ Rail │ Drawer     │ Utility bar: breadcrumb · search · timer · bell  │
│ 64   │ 248        ├──────────────────────────────────────────────────┤
│ logo │ header     │ Page header (title | entity header)              │
│ ⌂    │ views      │ Page tabs (entity pages)                         │
│ ▭    │ queues     │ ─ section rule / strata (entity only) ─          │
│ ✓    │ saved      │ Content: wide canvas | grid | reading width      │
│ …    │ watching   │ (+ optional inspector / context rail)            │
│ acct │            │                                                  │
└──────┴────────────┴──────────────────────────────────────────────────┘
```

- **Rail:** the logo (fades on dark, as in the brand SVG; solid ink on light), a drawer toggle (only while the drawer is collapsed), the workspace items visible to the user's capabilities (Home, Projects, Tasks, Helpdesk, Time, Directory, Finance, System), and the account button at the bottom (a rounded-square rail tile containing the circular avatar, §13.1). There are no counts on rail items; counts live in the drawer.
- **Drawer:** a header (workspace name + collapse/pin), then views, queues, saved views, and watched or recent entities. Group headings use `label` style. Counts are right-aligned mono.
- **Utility bar:** a breadcrumb (it carries the current view menu when the drawer is collapsed), search / command (Ctrl+K), the global timer pill, notifications (Future), and at most one primary page action on narrow widths. It never carries workspace navigation.
- **Page header:**
  - Operator pages have an overline (date or ID), a `title`, an optional one-line summary, and actions on the right (one ink primary action).
  - Entity pages have an overline (ID · owner org), a `display-lg` entity name, a status line (health + key facts), actions, then page tabs over the strata.
- **Navigation data** still comes from the server `NavigationBuilder`; its output changes shape to `workspaces[] → { key, label, icon, href, drawer: { default, sections[] } }`, filtered by capability.

---

## 7. Customer shell anatomy

```
┌────────────────────────────────────────────────────────────────────┐
│ Top bar 60: mark · Intechral | Org ▾ · Home Projects Support Invoices · Ask for help · (DW) │
├────────────────────────────────────────────────────────────────────┤
│                 ┌──────────── max 1080 ────────────┐               │
│                 │ breadcrumb                        │               │
│                 │ display-xl entity headline        │               │
│                 │ lead status sentence              │               │
│                 │ ═ strata ═                        │               │
│                 │ stage path                        │               │
│                 │ Waiting on you (card) | Requests  │               │
│                 │ Recent updates        | Budget    │               │
└─────────────────┴───────────────────────────────────┴───────────────┘
```

- **Same tokens, components, status language, progress components, account menu and ink primary.** It differs in frame, density and vocabulary.
- Nav has **≤ 5** workspaces in plain language (Home, Projects, Support, Invoices; Knowledge later). The labels are provisional per the IA document.
- The selected nav item uses a 2px `accent-line` underline (cyan in dark).
- **Reading order:** status sentence → what's waiting on you → what happened → your requests → money → your team.
- Customers never get a drawer, a global timer, internal notes, bulk actions, dense tables or operational vocabulary ("SLA", "queue").
- The organization switcher appears only if the person belongs to more than one organization (Open).
- Customer surfaces must work well on a phone (product principle); the menu sheet opens below 900px.

---

## 8. Navigation and selection rules

| Place | Selected treatment | Notes |
|---|---|---|
| Rail item | `surface-selected` tile + 1px `rule` ring, `text` colour, 600 label | No strip |
| **Drawer item** | `surface-selected` bounded row + 1px `rule` ring + **2px `accent-line` strip** inset at left | **The only place the strip is used.** At most one strip per screen |
| Page tabs | 2px `text` (ink) underline, 600 weight | Tabs sit directly on the section/strata rule |
| Customer top nav | 2px `accent-line` underline | |
| Segmented controls | `ink` fill with `on-ink` text | Scope toggles, theme choice |
| Table rows (selected) | `accent-soft` background + checked checkbox | No strip |
| Running row | `live-soft` background + time in `live-text` | Distinct from selection |
| Filter chip (set) | `accent-soft` + `accent-line` border + remove affordance | |

**Rules**
- There is one way to show "you are here" at each level: rail (workspace), drawer (view or entity), tabs (section), and the breadcrumb (full trail).
- The breadcrumb is always present in the operator utility bar and always ends with the current page.
- `aria-current="page"` on the selected rail item and the selected drawer item; tabs use `role="tab"` / `aria-selected`.
- Do not add a permanent third navigation level. Use tabs, the view menu or filters instead.

> **Forward note (2026-10-05, EPIC-015).** Page tabs that each change the **route** use link semantics, not ARIA tabs. The project workspace navigation (Overview · Board · Tasks · Milestones, plus Time where the server offers it) is a labelled `nav` ("Project") of ordinary links with `aria-current="page"` on the current page, and has no `role="tablist"`, `role="tab"` or `aria-selected`, because each item is a separate page ([EPIC-015 §11.4, P3](../epics/EPIC-015-projects-ux-expansion.md#114-tabs)). The visual treatment above (2px ink underline, 600 weight, on the strata) is unchanged, and the strip scrolls horizontally at narrow widths rather than wrapping. `role="tab"` / `aria-selected` remain the rule for in-page tabs that switch panels without navigating. This note records the deviation; the rule text above is historical and is not rewritten.

---

## 9. Content widths and inspectors

| Surface class | Width behaviour | Examples |
|---|---|---|
| Canvas | Full width minus page padding | Tasks table, Board, Time, queues, Finance tables |
| Grid | Full width with a 1fr + 340–360px column split | Home, Project Overview, Directory record |
| Reading | Content column max 640–760px; context rail beside it | Ticket conversation, forms, account pages, knowledge |
| Customer | Centred column max 1080px | All customer pages |

**Inspector (optional, surface-specific)**
- Allowed on queue-like surfaces where triage benefits from preview-and-act: the Helpdesk queue (Target), Home "My work" (optional), and a Tasks peek (NEXT).
- Width 360–392px, docked right, flat (`rule` left border, level 0). It opens on row selection and closes with `Esc`.
- Opening an inspector at **L** collapses a docked drawer for the session (it is not remembered).
- At **M/S** the inspector becomes a sheet or a full page.
- Detail pages (Ticket, Project) use a **context rail**: metadata beside a reading column. That is not an inspector and is always present on that page.

---

## 10. Status and priority semantics

Every status uses **glyph + text label + colour**. Colour alone is never the signal. Statuses render as inline glyph+label (`st`), not filled pills. Pills are reserved for tags and filter chips.

### 10.1 Health (projects; derived signal)

| State | Glyph | Colour | Current? |
|---|---|---|---|
| On track | ● filled circle | `success-glyph` / `success` | Target |
| At risk | ▲ filled triangle | `warning-glyph` / `warning` | Target |
| Off track | ■ filled square | `danger` | Target |
| Not started | ○ hollow circle | `text-muted` | Target |
| Complete | ✓ in circle | `success-glyph` | Target |

Health is separate from **project lifecycle status** (Current: `active`, `on_hold`, `completed`, `archived`), which renders as plain text, with a dashed hollow glyph for On hold and muted text for Archived.

### 10.2 Tasks

| State | Treatment |
|---|---|
| Open | Hollow ring (the Complete control itself) |
| Done | Ring filled with a check (`success-glyph`); row text `text-muted` |
| Overdue | Clock glyph + due date in `danger`, weight 500 |
| Due today | Plain "Today" |
| Running | Row `live-soft`, time in `live-text` |
| Source | Mono tag: `BOARD`, `TICKET`, `STANDALONE` (Current kinds) |

Board-task status stays column-authoritative (Current). The UI shows the column name as status text.

### 10.3 Priority (Current values: low, medium, high, critical)

Three ascending bars plus a text label. Low = 1 bar, Medium = 2, High = 3, Critical = 3 bars in `danger` with the label in `danger`. Unfilled bars use `rule-control`.

### 10.4 Helpdesk tickets (Current statuses)

| Status | Glyph | Colour |
|---|---|---|
| Open | Hollow circle | `accent` |
| In progress | Half-filled circle | `accent` |
| Pending (`pending_user`); customer label "Waiting on you" | Hourglass | `text-muted` |
| Resolved | Check in circle | `success` |
| Closed | Check | `text-muted` |

**Derived signals** (not statuses): "Waiting on us · 26 h" (chat glyph, `warning`); SLA breach (Future: clock, `danger`).

### 10.5 Other domains

| Domain | States |
|---|---|
| Incident severity (Future) | Mono label `SEV1`–`SEV4`; SEV1–2 `danger`, SEV3 `warning`, SEV4 muted; plus a lifecycle label |
| Invoice (Current: draft, sent, paid, overdue, cancelled) | Draft: dashed hollow, muted · Sent: accent arrow · Paid: success check · Overdue: danger clock · Cancelled: muted with strike-through amount |
| Approval (Future) | Awaiting: outline diamond, `accent` · Approved: filled diamond, `success` · Declined: diamond with ×, `danger` · Changes requested: diamond, `warning` |
| Billing lock (Current) | Lock glyph + "Billed", muted; controls disabled with the reason |
| Internal (Current) | Lock glyph + "INTERNAL NOTE" in `warning`; dashed `warning-glyph` border; `warning-soft` background (see the B ticket study; carried into D) |

---

## 11. Progress: staged and continuous

### 11.1 When to use which

| Use **staged** when… | Use **continuous** when… |
|---|---|
| Steps are **named, ordered and discrete** (project phases, onboarding, change-request lifecycle, invoice run) | The value is a **quantity** (% complete, hours, money, counts) |
| Each step has a date or owner | The value is comparable to a plan or estimate |
| ≤ 7 steps | Any value, including > 100% |

**Never** segment a quantity ("26 of 42 tasks" is continuous). **Never** draw a lifecycle as a percentage bar.

### 11.2 Staged path (`StagePath`)

- A grid of N equal columns, 4px gap (6px in customer contexts).
- Segment heights step up from 6px by +3px per step (6, 9, 12, 15, 18…; cap at 24). This is the logo's strata, rising.
- States:

| State | Segment | Label |
|---|---|---|
| Done | `progress-fill` | Name + "Done · date" (muted) |
| Current | `live` | Name + "In progress · due date" in `live-text` |
| Planned | Transparent with 1px dashed `stage-future` | Name + "Planned · date" |
| Blocked (Target) | `warning-glyph` + triangle glyph before the name | "Blocked · reason" |

- Name: 13–14px, weight 500. Meta: 12–12.5px.
- Semantics: `role="list"`; each stage a `listitem`; the current one has `aria-current="step"`; state is announced in text, not colour.
- Compact variant (in a list row): segments only, 14px max height, with an `aria-label` ("Milestones: 3 of 5 reached").

### 11.3 Continuous bar (`ProgressBar` / `Meter`)

- Height 4px (inline/list) or 6px (summary). Radius half the height. Track `progress-track`; fill `progress-fill`.
- **Always paired with the number** (mono), usually right-aligned or above.
- **Expected marker (optional):** a 2px-wide, 10px-tall mark in `text` at the expected position (for example budget expected at the current % complete).
- **Overage:** only the portion past the marker takes `warning-glyph`, and the number takes `warning` text.
- **Over 100%:** the bar is full, the overflow is shown as a `danger` end-cap segment (the final 4%), and the label reads e.g. "112%" in `danger`.
- Semantics: `role="meter"` (or `progressbar` for task completion) with `aria-valuenow/min/max` and `aria-valuetext` that includes the expected value: "82% of budget used; 61% expected".
- The fill is **never** brand cyan. Cyan is only for "live", and quantities aren't live.

---

## 12. Global timer

**Current behaviour to respect (EPIC-011D and `routes/web.php`):**
- Timers are server-authoritative, and multiple concurrent timers are supported.
- Existing endpoints:
  - `GET /time/timers/active` (the active set)
  - `POST /time/timer/start`
  - `POST /time/timer/{entry}/stop`
  - `PATCH /time/timer/{entry}/description`
  - `GET /time/context-options` (the existing cascading start selector).
- There is **no pause** and **no stop-all endpoint**.
- A start is never automatically retried; stop is idempotent.
- `TimerProvider` reconciles on layout init and after mutations, with sequenced refreshes so stale reads cannot remove a running timer.

The current `RunningTimerBar` (a full-width strip under the header) is **replaced** by the pill and tray below. `TimerProvider` and its reconciliation stay as they are.

### 12.0 Scope boundary

The shell foundation delivers the **global timer affordance**, not a Time product redesign. Anything marked **NEXT** below belongs to the Timer UX workstream on the roadmap, unless an existing endpoint already supports it and it costs no new domain work. The concepts remain part of the design direction; they are just not foundation requirements.

| Capability | Scope |
|---|---|
| Zero / one / several running states in the pill | **Foundation** |
| Newest (most recently started) timer shown compactly in the pill, with `+N` for others | **Foundation** |
| Tray listing all active timers | **Foundation** |
| Individual Stop (pill and tray) | **Foundation** |
| Editable description in the tray | **Foundation** (existing endpoint) |
| Contextual start/stop on task, project and ticket surfaces | **Foundation** (existing endpoints) |
| Pending and error states | **Foundation** |
| Existing `TimerProvider` reconciliation | **Foundation** (unchanged) |
| Starting a timer from the zero state | **Foundation**: link to the existing start flow on Time; embedding the **existing** cascading selector in the tray is allowed as direct reuse |
| Stop all | **NEXT** (no endpoint; client-side fan-out of individual stops is not a substitute without product review) |
| Long-running warning and its threshold | **NEXT** (the threshold is open, §20) |
| Richer global context search / quick start ("Start another…" search) | **NEXT** |
| "Today N logged" summary in the tray header | **NEXT** (no endpoint in the active-timer payload) |
| Switch (stop others, start this) | Future |

### 12.1 The pill (utility bar, right)

| State | Pill | Inline action | Scope |
|---|---|---|---|
| **None** | Ghost button "Start timer" with a clock icon | Opens the tray: "No timers running" + contextual guidance + link to the start flow on Time | Foundation |
| **One** | Live dot + elapsed `H:MM:SS` (mono 500) + context label (truncated to ~28 characters) | Stop (labelled with the context) | Foundation |
| **Several** | As One, showing the **most recently started** timer, + count badge `+N` (`live-soft` background, `live-text`) + ▾ | Stop acts on the shown timer; the pill body opens the tray | Foundation |
| **Pending start/stop** | Hollow live ring + "Starting…" or "Stopping…"; no elapsed time until the server confirms | Disabled | Foundation |
| **Failed** | Keeps the last confirmed state; adds "Couldn't stop" (or "Couldn't start") in `danger`; `danger` border | Retry for stop only; start is never retried automatically | Foundation |
| **Narrow (S)** | Dot + `H:MM` + `+N` only | Tap opens the tray as a sheet | Foundation |
| **Long-running** | Clock glyph + amber elapsed + "Running since yesterday · check"; `warning-glyph` border | Stop | **NEXT** |

**Rules**
- There is exactly one global timer affordance. It never shows more than one timer inline and never grows beyond about 360px.
- The pill never shows totals, charts or lists.
- Elapsed time is computed client-side from the server's start time and ticks every second in tabular mono so the width is stable. The ticking value is **not** in a live region.

### 12.2 The tray (popover from the pill)

- A non-modal popover (Radix Popover), 400–420px wide, level 2.
- **Foundation contents:**
  - Header: "N running".
  - Rows, newest first: live dot · title · context line (type, parent, org) · editable description (inline input, saves on blur/Enter via the existing endpoint, with pending and error feedback) · elapsed · Stop.
  - Footer: **Open Time →**.
- **NEXT additions:** Stop all in the header, "Today N logged" beside the count, and **Start another…** (global context search) in the footer. D7 draws these, so treat those parts of D7 as NEXT.
- Keyboard: normal Tab order through the rows' controls; `Esc` closes and returns focus to the pill. Arrow-key row movement is allowed only if the list is deliberately built as a composite widget with matching semantics (§14.3).
- Announcements: `aria-live="polite"` for "Timer started on …" and "Timer stopped, 0:47 logged".

### 12.3 Contextual controls (rows, cards, entity headers) — Foundation

| State | Control |
|---|---|
| Idle | Ghost play button, `aria-label="Start timer: <title>"` |
| Running here | Stop button on a `live-soft` background; row/card time in `live-text` |
| Pending | Hollow live ring; control disabled |
| Unavailable | Disabled with a tooltip giving the reason (billed, no permission, archived project) |

Starting from a context never stops other timers (Current behaviour).

---

## 13. Account menu

Personal only. **Never** administrative items (Users, Roles, Pages and Settings live under System).

| Order | Item | Meta on the right |
|---|---|---|
| — | Header: avatar, name, email (operator) or organization (customer) | |
| 1 | Profile | |
| 2 | Security & MFA | "MFA on" (`success`) / "Not set up" (`warning`) |
| 3 | Connected accounts | Provider names |
| 4 | Sessions | "N active" |
| 5 | Appearance | Inline segmented control: Light · Dark · System (System is NEXT; Current has Light/Dark) |
| 6 | Notifications | Disabled, FUTURE |
| — | Divider | |
| 7 | Keyboard shortcuts (operators) | `?` |
| 8 | Sign out | |

- It is the same menu component in both shells. It anchors to the rail account button (opens up and right) or the top-bar avatar (opens down, right-aligned).
- It is a Radix DropdownMenu with `role="menuitem"`. The Appearance row is a `radiogroup` inside the menu. A dropdown menu is a composite widget, so its arrow-key behaviour is appropriate (unlike the primary navigation, §14.3).
- Items link to account pages at reading width (720px). The current single Profile page splits into these sections without changing Fortify behaviour.

### 13.1 Avatars and account triggers

**Identity rule**
- Person avatars are **circular by default**, at every size, in both shells.
- Avatar shape **never encodes role**. Operators, customers and other people all get the same circular identity treatment.
- A **compact identity tile** (small-radius square, 4px) is allowed only where a component specifically calls for one, for example the dense assignee cell in a Compact table row. It is a component-level variant, not a second identity language.
- Contents: a photo if one exists, otherwise initials (Plex Sans 600) on a neutral surface (`surface-selected` / `surface-sunken`) with `text` colour. No per-person hue coding.

**Shell treatment**

| Shell | Account trigger |
|---|---|
| Customer top bar | The circular avatar (34px) **is** the trigger button |
| Operator rail | A **rounded-square rail button** (40×40, radius 6, rail-item geometry, `surface` + `control-edge` ring) **containing** a circular 28px avatar |

Both triggers are `<button>` elements with `aria-haspopup="menu"`, `aria-expanded`, and an accessible name ("Account menu: <name>").

Mockup note: D1–D3, D6 and D8 draw the operator rail trigger as a rounded-square tile with initials directly inside, not a circular avatar inside a tile. The rule above is canonical; the mockups don't need redrawing.

---

## 14. Focus and keyboard

### 14.1 Focus

- `:focus-visible` uses a 2px `focus` outline with a 2px offset, and inherits the element's radius.
  - Light: deep teal. Dark: cyan.
  - Inside dense tables, the row takes an inset focus outline (no offset) so it isn't clipped.
- Never remove focus styles. Never rely on colour change alone for focus.
- Include a **skip link** ("Skip to content") as the first focusable element in both shells.
- Landmarks:
  - Operator: `nav` (rail, "Workspaces"), `nav` (drawer, "<Workspace> views"), `header` (utility bar), `main`, optional `aside` (inspector or context rail).
  - Customer: `header` with `nav`, then `main`.

### 14.2 Focus management

| Event | Focus goes to |
|---|---|
| Drawer overlay opens | First item (or the current item) in the drawer |
| Drawer overlay closes | The toggle that opened it |
| Popover / menu / tray | The first item; `Esc` returns focus to the trigger |
| Dialog | The first field, or the least destructive button; focus is trapped; `Esc` closes unless an action is in flight |
| Inertia page visit | The page `h1` (via `tabindex="-1"`), announced. This is new behaviour (not Current); validate it against the existing EPIC-011 keyboard/a11y test expectations when introduced |
| Row completed / removed | The next row |

### 14.3 Keyboard map (operators)

| Keys | Action | Status |
|---|---|---|
| `Ctrl/⌘ K` | Search / command | Search NEXT; command palette Future |
| `Ctrl/⌘ \` | Toggle drawer | Target |
| `?` | Shortcut sheet | Target |
| `J` / `K` | Next / previous row in lists and queues | NEXT (Tasks) |
| `X` | Select row | NEXT |
| `E` | Complete / Reopen the focused task | NEXT |
| `T` | Start/stop the timer on the focused item | NEXT |
| `Enter` | Open the focused item | NEXT |
| `Esc` | Close overlay / inspector / clear selection | Target |

Single-key shortcuts are inactive while focus is in a text field, and can be turned off (WCAG 2.1.4). Every drag interaction has a menu alternative (the EPIC-011E keyboard Move precedent).

**Primary navigation semantics**
- The rail, drawer, customer top nav and breadcrumb are **standard navigation**: `<nav>` landmarks containing ordinary `<a href>` links, reached with normal **Tab** order.
- They **do not** use roving tabindex, and arrow-key navigation is **not** required.
- Keep: the skip link, `:focus-visible` styling, landmarks, `aria-current="page"`, focus return for overlays (overlay drawer, popovers, tray, menus), `Esc` to close overlays, and the optional global shortcuts above.
- Arrow-key navigation may be added later **only** for a component deliberately built as a composite widget with matching ARIA semantics (for example `menu`, `tablist`, `listbox`, `grid`), never retrofitted onto plain navigation links. Radix Tabs and DropdownMenu already qualify.

---

## 15. Loading, empty, error, disabled, pending

### 15.1 Loading

- The shell (rail, drawer, utility bar, page header) renders immediately. Only regions load.
- Skeletons match the real geometry: rows at their real height, with bars for the primary cell and meta. There is no generic card skeleton and no spinner for regions.
- The shimmer is a subtle opacity sweep, and it is static under reduced motion.
- Buttons with in-flight actions keep their width and show a small spinner. Labels become "Saving…" and so on.
- The timer pill never shows a skeleton. It shows the last confirmed state, or nothing on first load.

### 15.2 Empty

- Distinguish **truly empty** from **filtered empty**:
  - Truly empty: one sentence explaining what will appear, plus the primary action ("No tasks yet. Tasks you're assigned will appear here." + New task).
  - Filtered empty: "No tasks match these filters." + Clear filters.
- Left-aligned inside the region, not centred illustrations. No mascot art. An optional small line glyph is allowed.
- Customer empty states use plain language and point to the next useful action ("Nothing needs your attention right now").

### 15.3 Error

| Scope | Treatment |
|---|---|
| Field | `danger` text under the field + `danger` border; `aria-describedby`; server message verbatim |
| Region | Inline banner (`danger-soft`, danger glyph, message, Retry) replacing the region content; the rest of the page keeps working |
| Page | The page header stays; body message + Retry + link back |
| Permission denied | Its own neutral state (lock glyph): "You don't have access to …"; never shown as an error colour |
| Stale / conflict (for example billing lock) | Show the server's canonical message, refresh affected data, and never mutate locally (EPIC-011D rule) |
| Session expired | A dialog to re-authenticate; keep unsaved form input |

### 15.4 Disabled

- **Hide** controls the user can never use (capability).
- **Disable** controls blocked by the current state, with a tooltip or visible reason (billed, archived, waiting for the server).
- Disabled controls drop their fill to `surface-sunken` and keep their label or value at `text-muted` (never `text-faint`: it is still information), keep their layout, and are `aria-disabled` so the reason is discoverable.

### 15.5 Optimistic vs confirmed

**The server is always authoritative** for provenance-bearing state: status, completion, assignment, time, money and approvals. The client never becomes the source of truth; it renders permitted state and requests changes (product principle 2).

Two different things are distinguished:

1. **UI preferences** (drawer state, filters, sort, theme, density) are client-owned and may apply immediately.
2. **Optimistic presentation of a server mutation** is permitted. The UI may show the expected result before the server confirms, **only when all** of these hold:
   - the operation is **reversible** (moving a board card, toggling a checklist item, reordering);
   - there is an **authoritative server mutation** behind it;
   - reconciliation is **explicit**: the server response (or a canonical reload) replaces the presented state;
   - **stale responses cannot overwrite newer state** (sequencing or version checks, as in EPIC-011D timer refresh ordering and EPIC-011E board and checklist handling);
   - **failure restores or reloads canonical state** and shows the error where the action happened;
   - the UI **represents pending state where it matters** (a subtle pending mark on the row, or disabled repeat actions).

These rules preserve the existing hardened patterns: project board moves, checklist toggles and timer refresh sequencing.

**Not eligible for optimistic presentation**, and always shown as pending until confirmed:
- money and invoicing;
- time entries and timer start (starts are never retried automatically);
- approvals and decisions;
- anything whose failure would be costly or confusing to show as undone, such as state transitions with side effects like notifications. Status changes and completion are eligible only if they meet every condition above.

**Feedback**
- On success: a quiet confirmation (the row settles; an optional toast for off-screen effects).
- On failure: canonical state is restored and the error is shown in place.

---

## 16. Motion

| Token | Duration | Easing | Use |
|---|---|---|---|
| `motion-fast` | 120ms | `cubic-bezier(.2,0,0,1)` | Hover, press, focus, checkbox |
| `motion-base` | 160ms | same | Popover, menu, tray, tooltip (tooltip delay 500ms) |
| `motion-panel` | 200ms | same | Drawer docking/collapse (width), overlay drawer slide 12px + fade, inspector |
| `motion-sheet` | 240ms | same | Mobile sheets, dialogs |
| Exit | 70% of enter | `cubic-bezier(.4,0,1,1)` | All exits |

- Motion explains change: row reorder (160ms position), a completed row fading and collapsing, the drawer pushing content.
- The live dot has a static 3px `live-soft` halo. It may breathe very slowly (2.4s, opacity only), and is static under reduced motion.
- `prefers-reduced-motion`: remove translation and scaling; keep opacity fades ≤ 120ms; no shimmer.
- No parallax, bounce, spring overshoot or decorative animation. Nothing loops except the optional live-dot breathing.

---

## 17. Brand motifs

| Motif | Appears | Never |
|---|---|---|
| **Logo mark** (triangle, strata, stepped T) | Rail top (28px); customer top bar (26–28px); auth pages; favicon | In content, as a watermark, or as a background pattern |
| **Mark colour** | Light: solid ink. Dark: brand cyan with the SVG's upward fade | Recoloured to status colours |
| **Strata rule** (three lines, heavier at the bottom) | Under **entity headers** only: Project workspace, customer project headline, Directory record (Target), Ticket header (optional) | Section headings, tables, cards, dialogs, Home, list pages |
| **Steps** (heights rising) | `StagePath` segments only | Continuous bars, charts, loaders, decoration |
| **Cyan** | Live dot, current stage, drawer strip and focus in dark, customer nav underline in dark | Buttons, fills, backgrounds, charts, links in light theme |

The motifs are deliberately rare. If a new surface wants one, it must represent identity (strata) or lifecycle (steps).

### 17.1 Canonical brand assets

The canonical owner-supplied Intechral brand assets are tracked in the repository at:

- `src/resources/images/brand/intechral-logo.svg` — full/default logo
- `src/resources/images/brand/intechral-logo-compact.svg` — compact mark for constrained shell contexts

These files are the authoritative source assets for application branding. Implementers must use them directly, or derive theme presentation from them by changing only appropriate presentation attributes such as stroke/fill/colour where the SVG structure permits it. They must **not** reconstruct, trace, redraw or approximate the Intechral mark from screenshots, mockups or the Direction D artboards. The artboards may contain simplified illustrative versions and are not source artwork.

Default usage:

- **Full logo:** contexts with sufficient horizontal space where the complete identity is appropriate.
- **Compact logo:** constrained identity contexts such as the operator rail, favicon/app-icon treatment, and other compact shell placements.
- **Customer top bar and authentication surfaces:** use whichever canonical variant best fits the available space and hierarchy; do not create a third independently redrawn mark.

Theme treatment follows the Direction D brand rules:

- **Light:** solid ink treatment.
- **Dark:** canonical cyan treatment / upward fade where supported by the source artwork.
- Do not recolour the mark to status, warning, danger or arbitrary accent colours.

The favicon, operator rail mark, customer top-bar branding and authentication-page branding must all derive from these canonical repository assets. If a specialized output format is required for delivery (for example a favicon file), it must be generated from one of these sources rather than recreated independently.

The brand-asset prerequisite for shell implementation is now **satisfied**. The shell/design-system foundation epic should verify the appropriate asset-delivery mechanism for React and remaining Blade surfaces, but must not redefine or replace the source artwork.

---

## 18. Component inventory for the foundation

Only what the shell and first slices need. Radix is already installed for dialog, dropdown-menu and slot.

| Component | Status | Notes |
|---|---|---|
| `ThemeProvider` / tokens | Rework | New token layer; keep no-flash; add System (NEXT) |
| `AppShell` (operator) | New | Rail + Drawer + UtilityBar + Main; width-class aware |
| `Rail`, `RailItem` | New | Capability-driven from `NavigationBuilder` |
| `Drawer`, `DrawerSection`, `DrawerItem` | New | Docked / collapsed / overlay; per-workspace persistence |
| `UtilityBar`, `Breadcrumb`, `ViewSwitcher` | New | |
| `CustomerShell`, `TopNav` | New | |
| `AccountMenu` | Rework | From the current dropdown; remove the "Manage" section |
| `Avatar`, `AccountTrigger` | New | Circular avatar; compact tile variant; rail button vs top-bar trigger (§13.1) |
| `BrandMark` | New | Selects the canonical full or compact repository SVG according to context; never redraws or approximates the mark (§17.1) |
| `TimerPill`, `TimerTray`, `TimerControl` | New | Replace `RunningTimerBar`; reuse `TimerProvider`; Foundation scope only (§12.0) |
| `Button`, `Input`, `Textarea`, `Label` | Restyle | Variants: primary (ink), secondary, ghost, destructive; sizes sm/md/lg |
| `Tabs` | New (Radix Tabs) | Ink underline |
| `Tooltip`, `Popover` | New (Radix) | |
| `Select`, `Combobox` | New | Replace `native-select` where searching is needed |
| `Chip` / `FilterChip`, `FilterBar` | New | |
| `Status` (glyph + label) | New | Replaces colour-only `badge` usage for status |
| `Priority` | New | Bars + label |
| `Tag` | New | NEXT/FUTURE/source |
| `ProgressBar` / `Meter` | Rework | Continuous spec §11.3 |
| `StagePath` | New | Staged spec §11.2 |
| `Section`, `PageHeader`, `EntityHeader`, `Strata` | New/rework | |
| `DataTable` conventions | New | Header, group rows, selection, dense rows, reflow at S |
| `BulkBar` | New | Floating level-2 toolbar |
| `EmptyState`, `ErrorState`, `Skeleton` | New | §15 |
| `Card` | New | Only for §4.4 allowed uses |
| `Dialog`, `ConfirmationDialog` | Restyle | Confirm only when the action is consequential |

---

## 19. Implementation order

Direction D is implemented through a **dedicated shell and design-system foundation epic**. That epic ends when the operator shell, the token and component foundation, the global timer (Foundation scope) and Home are in place. **The broader Tasks overhaul, Projects expansion, Timer UX enhancements (the NEXT items in §12) and customer product work stay outside the foundation epic**, as separate epics sequenced by the Product Roadmap.

**Constraints that apply to every step**
- Token migration uses **compatibility aliases**. Intentional regressions to existing React or Blade screens are **not** accepted (§2.4).
- Each step is an independently shippable slice (product principle 13) that passes `./dev check`.
- The server stays authoritative (§15.5). Navigation truth stays in `NavigationBuilder`.

**Prerequisite**
0. **Brand assets — satisfied.**
   - Canonical assets are present at:
     - `src/resources/images/brand/intechral-logo.svg`
     - `src/resources/images/brand/intechral-logo-compact.svg`
   - The foundation epic verifies the delivery/theming mechanism and uses these files as the source of truth; it does not recreate the artwork.

**Foundation epic**
1. **Tokens and type.**
   - Add the Direction D semantic tokens to `src/resources/css/app.css` via Tailwind v4 `@theme inline`, with light and dark blocks on the existing `data-theme` no-flash contract.
   - Keep all legacy aliases and map them to compatible values where safe (§2.4).
   - Load Plex Sans/Mono and Newsreader.
   - Visually check representative unmigrated React and Blade screens in both themes.
2. **Primitive restyle and additions.**
   - Components: Button, Input, Tabs, Tooltip, Popover, DropdownMenu, Dialog, Avatar, Status, Priority, Tag, ProgressBar, StagePath, Skeleton, EmptyState.
   - Restyling shared primitives must keep their existing consumers readable and usable.
   - Unit tests for semantics (roles, `aria-current`, `aria-valuetext`).
3. **Navigation data.**
   - Reshape the `NavigationBuilder` output to workspaces + drawer sections + drawer defaults, filtered by capability.
   - Retire the "Manage" group from the account menu.
   - Add the drawer-state cookie and shared prop.
   - Pest tests for capability filtering.
4. **Operator shell (React).**
   - AppShell with Rail, Drawer (docked / collapsed / overlay), UtilityBar and Breadcrumb.
   - Width classes; skip link and landmarks; standard link navigation (§14.3); personal account menu with the rail account button (§13.1).
   - Until the customer shell is adopted, customer users see this shell filtered by capability.
5. **Blade parity.**
   - Update `layouts/app.blade.php` + `partials/nav.blade.php` to render the same rail and utility bar from the same navigation data, so the two renderers don't diverge.
   - A simplified drawer is acceptable in Blade until those areas migrate.
6. **Global timer (Foundation scope, §12.0).**
   - TimerPill and TimerTray replace `RunningTimerBar` (React) and the Blade tracker's global strip.
   - Contextual TimerControl on the existing task and ticket surfaces that already start timers.
   - No Stop all, long-running warning or global quick-start search.
7. **Page frame patterns and Home.**
   - PageHeader, EntityHeader + Strata, Section, DataTable conventions, FilterBar, and loading/empty/error states.
   - The Dashboard → operator **Home** conversion is the first adopting surface.

**After the foundation epic**
8. **CI baseline.** Lands **after** the shell/Home foundation and **before** the Tasks overhaul starts (per the Product Roadmap's delivery-engineering direction).
9. **Separate product epics**, each adopting Direction D on its own surfaces and retiring the aliases those surfaces consumed:
   - the Tasks overhaul (D2 / D9 patterns);
   - Timer UX (the §12 NEXT items);
   - Projects expansion (D3);
   - customer product work, including adopting the customer shell (D4, §7);
   - later Helpdesk, Directory and Finance.

---

## 20. Open questions

These remain open. None blocks the shell and design-system foundation.

1. **Customer routing topology:** same routes with capability-aware pages (the IA default) or a distinct route namespace. The shell design (§7) works either way.
2. **System theme option** in Appearance (NEXT): confirm before adding a third state to the no-flash theme contract.
3. **Multi-organization customer switcher:** whether, where and how a customer belonging to several organizations switches context.
4. **Long-running timer threshold:** the value (10 h is only a working proposal) and whether it's a setting. The warning itself is NEXT (§12.0).
5. **Font hosting:** self-hosting Plex and Newsreader vs the current external provider (performance, privacy, cPanel-compatible deployment).

Resolved in this revision: **avatar and account-control shape** (§13.1), unless the mockups reveal a visual problem during implementation.
