# EPIC-011D: Time Tracking and Persistent Timer Migration

**Status:** Verified
**Parent epic:** [EPIC-011: React Frontend Migration](./EPIC-011-react-frontend-migration.md)
**Prerequisites:** [EPIC-011A: React Foundation and Coexistence Contract](./EPIC-011A-react-foundation-coexistence.md), [EPIC-011B: Dashboard and Profile Migration](./EPIC-011B-dashboard-profile.md), [EPIC-011C: Authentication and Invitation Migration](./EPIC-011C-authentication-invitations.md)
**Decision record:** [ADR-007](../architecture/adr/ADR-007-inertia-react-frontend.md)
**Domain foundations:** [EPIC-007: Time Tracking](./EPIC-007-time-tracking.md), [EPIC-010C: Billed Time-Entry Locking](./EPIC-010C-billed-time-entry-locking.md)

---

## 1. Goal

Migrate the complete Phase D time-tracking presentation surface to Inertia 3, React 19, and TypeScript:

- the personal Time page;
- the allocation page and adjustment workflow;
- operator time reports;
- manual time-entry interactions; and
- the real React active-timer bar inside the persistent authenticated `AppLayout`.

Laravel remains authoritative for timer records, timestamps, duration rounding, allocation redistribution, validation, permissions, ownership, tenant boundaries, and billing locks. React owns presentation, local form state, pending/error state, and a derived ticking display.

This phase is the architectural proof that:

- timer UI state persists naturally across Inertia navigation because its provider lives in the persistent layout;
- a hard reload or Blade/Inertia transition reconstructs active timers from Laravel;
- ordinary React context, state, and reducers are sufficient;
- the legacy Blade timer remains safe and independent on Blade pages; and
- no TanStack or global state dependency is needed.

Behavioral parity is required, but existing authorization, accessibility, reconciliation, and reporting defects identified in this plan must be corrected instead of copied.

## 2. Parent Scope Finding

The parent [Phase D](./EPIC-011-react-frontend-migration.md#phase-d-time-tracking-timer-allocation-and-operator-reports) explicitly assigns all of the following to EPIC-011D:

- personal time index;
- time allocation page;
- operator time reports;
- React active timer bar;
- React time-entry interactions; and
- allocation editing and drag behavior.

Operator reports are therefore **in scope**. The CSV export remains a normal download response rather than an Inertia page. Projects/tasks, tickets, and the embedded time trackers on their still-Blade detail pages remain owned by later migration phases.

## 3. Live Architecture Inventory

### Backend and domain

| Concern | Current owner | Current behavior |
|---|---|---|
| Pages and HTTP validation | `TimeEntryController` | Personal entries, manual mutations, timer JSON, context options, allocation |
| Operator reporting | `Operator\TimeReportController` | Filtered report page and CSV download |
| Domain mutations | `TimeEntryService` | Log/update/delete, timer lifecycle, block finalization/reallocation, summaries/export |
| Timer/entry state | `TimeEntry` | Canonical running scope, billing lock, duration helpers, model invariants |
| Allocation state | `TimeEntryBlock` | 15-minute UTC-day slots and allocation percentages |
| Billing immutability | `TimeEntry::isLockedForBilling()` plus service row locks | `billed = true` or non-null `invoice_id` locks the entry |
| Authorization | Route permission middleware plus owner checks | `time.log` for personal routes; `time.view_all` for operator reports |

There is no dedicated Time policy or Form Request. Route model binding is global, followed by explicit owner checks for entries and blocks. TimeEntry has no generic organization scope; personal data is scoped by `user_id`, while operator reports intentionally span all users behind `time.view_all`.

### Current presentation

- `time/index.blade.php` contains the timer-start form, manual-entry form, filters, paginated table, deletion, and inline JavaScript.
- `time/allocation.blade.php` contains the date filter, Chart.js canvas, read-only block table, and global chart data.
- `operator/time/index.blade.php` contains filters, summaries, a 50-row paginated table, and CSV link.
- `layouts/partials/timer-overlay.blade.php` plus `timer-overlay.js` render active timers on authenticated Blade pages.
- `components/time-tracker.blade.php` starts/stops a context-bound timer from Blade task and ticket detail pages.
- `allocation-chart.js` manages Chart.js, drag redistribution, debounced persistence, and error reload.

No existing JavaScript tests cover the timer or allocation scripts. Existing Pest coverage establishes core timer, duration, permission, allocation, and billing-lock behavior.

## 4. Exact Route and Response Scope

All 13 registered routes retain their URI, name, middleware, and controller ownership.

| Method | URI | Route name | Controller action | Current response | EPIC-011D role |
|---|---|---|---|---|---|
| `GET` | `/time` | `time.index` | `TimeEntryController@index` | Blade `time.index` | **Inertia page** `time/index` |
| `POST` | `/time` | `time.store` | `TimeEntryController@store` | Redirect back | **Mutation endpoint**, retained redirect/Inertia validation semantics |
| `GET` | `/time/allocation` | `time.allocation` | `TimeEntryController@allocationView` | Blade `time.allocation` | **Inertia page** `time/allocation` |
| `GET` | `/time/timers/active` | `time.timers.active` | `TimeEntryController@activeTimersJson` | JSON array | **Focused JSON endpoint retained** |
| `GET` | `/time/context-options` | `time.context.options` | `TimeEntryController@contextOptions` | JSON array | **Focused JSON endpoint retained** |
| `POST` | `/time/timer/start` | `time.timer.start` | `TimeEntryController@timerStart` | JSON timer | **Focused JSON mutation retained** |
| `POST` | `/time/timer/{entry}/stop` | `time.timer.stop` | `TimeEntryController@timerStop` | JSON duration | **Focused JSON mutation retained** |
| `PATCH` | `/time/timer/{entry}/description` | `time.timer.description` | `TimeEntryController@updateTimerDescription` | JSON acknowledgement | **Focused JSON mutation retained** |
| `PATCH` | `/time/blocks/{block}/allocation` | `time.blocks.allocation` | `TimeEntryController@updateBlockAllocation` | JSON acknowledgement | **Focused JSON mutation retained**, response made authoritative |
| `PUT` | `/time/{entry}` | `time.update` | `TimeEntryController@update` | Redirect back | **Mutation endpoint retained** |
| `DELETE` | `/time/{entry}` | `time.destroy` | `TimeEntryController@destroy` | Redirect back | **Mutation endpoint retained** |
| `GET` | `/operator/time` | `operator.time.index` | `Operator\TimeReportController@index` | Blade `operator.time.index` | **Inertia page** `operator/time/index` |
| `GET` | `/operator/time/export` | `operator.time.export` | `Operator\TimeReportController@export` | CSV download | **Export endpoint retained** |

Personal routes remain behind `auth` and `can:time.log`; operator routes remain behind `auth` and `can:time.view_all`. JSON routes must not be converted into Inertia responses.

## 5. Timer Domain Contract

### Canonical states

| State | `timer_started_at` | `stopped_at` | Notes |
|---|---|---|---|
| Running | Non-null | Null | Returned by `running()` and active-timers JSON |
| Stopped timer | Null | Non-null | Duration persisted; allocation blocks finalized |
| Manual entry | Null | Usually null | No live interval |
| Billing-locked | Null | Either stopped/manual | Must never be running through normal writes |

### Required behavior

- Multiple active timers per user are intentional and must remain supported.
- `timer_started_at` is the authoritative start timestamp.
- Start creates a user-owned row with zero stored minutes, the current UTC date, `now()`, and optional context/description/billable values.
- Active timers are returned oldest first and only for the authenticated user.
- Stop row-locks and reloads the record, making retries idempotent.
- A stopped duration adds existing stored minutes and rounds any positive partial minute up using `ceil(seconds / 60)`.
- Stop clears `timer_started_at`, sets/preserves `stopped_at`, and finalizes 15-minute allocation blocks transactionally.
- Description edits are allowed only while the timer is running and unlocked.
- Billing-locked entries reject stop and description writes.
- Unknown, inaccessible, mismatched, or multiple contexts must be rejected server-side.

### Context contract correction

The current forms expose one context type at a time, but request validation accepts arbitrary existing project, task, or ticket IDs and allows multiple context IDs in one payload. The still-Blade embedded tracker also starts timers from any task or ticket detail page the user is authorized to view, including valid resources that the narrower Time-page option lists may omit. Before React consumes the contract:

- permit at most one of `project_id`, `task_id`, and `ticket_id`;
- authorize a project through its existing view policy/membership boundary;
- authorize a task through its parent project view boundary;
- authorize a ticket through its existing view policy;
- ensure context options never expose records outside that authorized submission boundary, while allowing an authorized embedded tracker to submit a legitimate resource omitted from the convenience list;
- apply the same rules to manual create, manual update, and timer start; and
- make update semantics clear all non-selected context columns and allow nullable descriptions/contexts to be cleared rather than retaining values through null-coalescing;
- test guessed cross-user/cross-tenant IDs.

This is a correctness boundary for the existing feature, not a new tenant model. Do not broaden `time.view_org` or invent organization-level Time semantics.

### Concurrency

- Starting multiple distinct timers remains legal. The React control prevents accidental double submission but does not add a single-timer uniqueness constraint.
- Stop is server-idempotent and row-locked.
- Description updates are row-locked and server-confirmed.
- Allocation updates lock every block and entry in the affected user/date/slot before enforcing locks and redistributing.
- Unknown network outcomes reconcile from the active-timers endpoint or a page partial reload.

No client-side domain calculation may replace these server rules.

## 6. Timer DTO

Do not serialize `TimeEntry` models directly. Retain the current JSON keys for Blade compatibility and explicitly map:

```ts
type TimerContextDto = {
    type: 'Project' | 'Task' | 'Ticket';
    label: string;
    url: string | null;
};

type ActiveTimerDto = {
    id: number;
    started_at: string; // ISO-8601 UTC instant
    server_now: string; // additive; used to estimate browser/server clock offset
    description: string | null;
    context: TimerContextDto | null;
};
```

`GET /time/timers/active` continues returning an array so `timer-overlay.js` remains compatible. The additive `server_now` member is ignored by legacy JavaScript. Timer start returns the same DTO. Description success should return `{ id, description }`; stop should return `{ id, duration_minutes, duration_human }`.

The DTO excludes user records, billing data, invoice IDs, raw related models, and unrelated tenant data.

## 7. React Timer State Architecture

Add a narrow `TimerProvider` inside the persistent `AppLayout`, mounted only when the authenticated user has `time.log`.

Provider state:

- `timers: ActiveTimerDto[]`;
- initial loading/error status;
- per-timer stop/description pending state;
- timer-start pending state where needed; and
- a measured server/browser clock offset.

Provider operations:

- `refreshTimers()`;
- `startTimer(payload)`;
- `stopTimer(id)`; and
- `updateDescription(id, description)`.

A reducer is appropriate for replacing the authoritative timer set and applying per-ID mutation successes. It must not become a general application store.

The timer records hold timestamps, not incrementing elapsed counters. A single display tick updates a local `now` value once per second; each elapsed value is derived from:

```text
adjusted browser now - authoritative started_at
```

Estimate adjusted browser time from `server_now` when timers hydrate or start. Use monotonic elapsed time for the display where practical. Do not persist timer state to localStorage and do not poll merely to make the clock tick.

After an HTTP failure whose server outcome is uncertain, call `refreshTimers()`. If the server returns a different timer set, replace local state without attempting a client merge.

## 8. Active Timer Initialization Decision

| Option | Benefits | Costs |
|---|---|---|
| Shared Inertia prop | No second request on hard Inertia load | Queries timers on unrelated Inertia responses; prop churn can overwrite provider state; does not help Blade |
| Existing active-timers endpoint | One contract shared by Blade/React; fetched once per React layout mount; simplest reconciliation path | One small request after each hard Inertia document load |

**Decision: use `GET /time/timers/active` when `TimerProvider` mounts.**

The existing endpoint is already permission-protected and purpose-built. Persistent-layout navigation does not remount the provider, so it does not create a request per Inertia visit. Hard reloads and returns from Blade correctly perform a fresh request. Do not add active timers to global shared Inertia props and do not add TanStack to cache this one request.

## 9. Persistent AppLayout Behavior

- Replace the empty `#active-timer-slot` with `TimerProvider` and `RunningTimerBar`.
- Place the bar immediately below the sticky header and above flash/page content, matching the Blade shell's functional placement.
- Render one compact, horizontally scrollable item per active timer with elapsed time, context, editable description, and stop control.
- Use Lucide icons and accessible labels for icon-only actions; do not rely on color or animation alone.
- Starting a timer from the Time page calls the provider and inserts the server-returned DTO immediately after success.
- Stopping or editing globally updates every consumer through provider state.
- Inertia navigation among Dashboard, Profile, Time, allocation, and operator reports preserves the provider and clocks.
- A hard reload creates a new provider and reconstructs state from Laravel.
- A document navigation to Blade discards React state; the Blade overlay fetches the same server records.
- Returning from Blade creates React again and re-fetches.
- Logout does **not** stop server timers; the layout unmounts and a later login recovers them, preserving current domain behavior.
- On `401` or `419`, clear misleading local pending state and force a document-level auth/session recovery.

No custom browser event is needed within React. No event crosses a full-document boundary.

## 10. Blade Timer Coexistence

The existing Blade timer stack remains in place for Projects, Tasks, Tickets, Billing, CRM, CMS, and administration pages:

- `layouts/partials/timer-overlay.blade.php`;
- `timer-overlay.js`;
- its import from `app.js`; and
- `components/time-tracker.blade.php` on task/ticket detail pages.

Blade pages never mount `TimerProvider` or a React timer root. Inertia pages never render the Blade overlay. Both use the same endpoints and server records.

`timerStarted` and `timerStopped` remain useful only inside a Blade document: page-specific Blade scripts notify the Blade overlay, and the overlay notifies other legacy listeners. Keep them until the final Blade consumer is migrated; do not mirror them in React.

Only additive backend-contract corrections may touch the Blade timer in this phase. Removal belongs to the later phase that eliminates its final Blade consumer.

## 11. Personal Time Page

### Current behavior

- Start-timer form with context type, asynchronous context options, description, and billable flag.
- Manual form with UTC date, decimal hours in 0.25-hour steps, context, description, and billable flag.
- Project/date filters and filtered total.
- User-owned entries ordered by date/id and paginated 25 per page.
- Context links to still-Blade project/task/ticket pages.
- Locked/running markers and deletion for mutable stopped entries.
- An update route exists and is tested, but the Blade page exposes no edit control.

### React direction

Use a restrained operational layout:

1. Page header and Entries/Allocation tabs.
2. Compact timer-start section connected to `TimerProvider`.
3. Manual-entry form.
4. Filter row and filtered total.
5. Responsive entry table/list with pagination.

Add an edit action for mutable stopped entries because the backend update contract already exists. Use an inline expansion or focused dialog, not a second page. Keep delete behind the existing confirmation dialog. Running entries are controlled from the persistent timer bar rather than duplicated as editable rows.

The current total query applies date filters but omits the selected project/ticket filter. Build the entry list and total from the same normalized filter contract so the displayed total covers all filtered rows, not merely a broader date range.

Represent:

- date;
- context label/link;
- description;
- human duration;
- billable state;
- billed/invoice-linked lock state;
- running state; and
- edit/delete availability.

Use a semantic table on wider screens and a compact stacked row treatment on narrow screens. Do not introduce dashboards, charts, approvals, configurable lock windows, or new billing behavior.

## 12. Cascading Context Selector

The current selector is a two-step **type then record** selector, not a project-to-task hierarchy:

| Type | Current option scope |
|---|---|
| Project | Active projects where the user is a member or creator |
| Task | Up to 50 non-done tasks assigned to the user |
| Ticket | Up to 50 open/in-progress tickets assigned to the user |

The endpoint returns only `{ id, label }`. There is no organization/company dimension and no project parameter for task filtering.

Implement `TimerContextSelector` with local React state:

- selecting a type fetches that type's options through the Wayfinder-generated URL;
- changing type clears the selected record and stale request state;
- cancel stale requests with `AbortController`;
- optionally retain already loaded options in a component-local map for the life of the page;
- expose loading, empty, and retry states; and
- submit exactly one context ID.

Use the same selector in timer, create, and edit forms where practical. Keep the JSON endpoint rather than using Inertia partial reloads for this small interactive lookup. Do not create a generic query/cache abstraction.

## 13. Manual Entry Forms

Map the existing Wayfinder helpers:

- `time.store` for create;
- `time.update` for edit; and
- `time.destroy` for delete.

Use Inertia form/router mutations and server-confirmed results. Do not optimistically insert, edit, or delete entries. Preserve:

- native `date` input with no future dates;
- decimal-hours input with `max=24`, and a create-versus-edit distinction: **new manual entries** keep `min=0.25` (15 minutes) and `step=0.25`, while **editing an existing entry** uses `min=0.01` and `step=any` so any whole-minute timer duration can be re-saved (7 minutes is seeded as `0.12` hours, 50 minutes as `0.83`); Laravel enforces the same split (`min:0.25` on create, `min:0.01` on update, both `max:24`) and rejects any value that would round to zero stored minutes;
- optional single context;
- optional description up to 500 characters; and
- billable defaulting to true.

Keep create and edit processing/error state isolated so one form does not display another form's validation errors. Disable repeat submission while processing, focus the first invalid field, associate errors with controls, and retain submitted values after validation failure.

For update, distinguish an omitted field from an explicitly null field. Selecting a new context must clear the other context foreign keys, and selecting None or clearing a description must actually persist null.

Delete requires an accessible confirmation dialog. A stale page that displays mutable controls may still receive the canonical billing-lock validation response; show it without removing or mutating the row locally.

## 14. Billed and Invoice-Linked Entries

EPIC-010C is a hard invariant:

```text
locked = billed is true OR invoice_id is not null
```

Page DTOs should expose a derived `locked` boolean and a display-safe reason/status, not the raw invoice model. Locked entries remain visible but:

- cannot be edited or deleted;
- cannot have running descriptions changed;
- cannot be stopped if a malformed legacy row is both running and locked; and
- make their entire allocation slot read-only when redistribution would affect them.

The UI displays a visible Locked badge and suppresses/disables mutation controls, but Laravel remains authoritative. Regression tests must submit stale mutations after the database lock state changes and prove no write occurs.

## 15. Allocation Architecture

### Current implementation

- Chart.js is declared as `^4.4.0` and resolves to 4.5.1.
- `chartjs-plugin-dragdata` is declared as `^2.2.5` and resolves to 2.3.1.
- Blade emits an array of entries with labels, links, descriptions, lock state, and a sparse `blockNumber -> { id, pct }` map.
- The chart expands this into 96 UTC-day slots and stacked line datasets.
- Dragging one point redistributes the remainder across sibling entries locally.
- The server endpoint independently locks and redistributes the entire slot.
- The current script then schedules a PATCH for every locally changed sibling. Those redundant requests each trigger another server redistribution and can race.
- Failure alerts and reloads the full page.
- The table reports values but offers no non-pointer mutation control.

### React recommendation

Use **direct Chart.js** in a React component with `useRef` and `useEffect`:

- register the existing drag plugin once;
- create the chart after the canvas mounts;
- update datasets intentionally when props change;
- destroy the instance during cleanup;
- lazy-load the chart component if bundle analysis shows value; and
- keep chart-specific mutable interaction state inside the component.

Do not add `react-chartjs-2`. The current imperative plugin already operates on the Chart instance, and a wrapper would add an abstraction without removing the lifecycle work.

On drag end, send **one PATCH for the dragged target block**, because the backend owns sibling redistribution. Make that endpoint return the authoritative adjusted slot, including block IDs, percentages, and lock state. Replace the optimistic slot with the response. On validation, billing conflict, or network uncertainty, restore/reload the allocation page's authoritative data.

Backend redistribution should deterministically preserve the slot invariant, including rounding remainder handling and a defined single-entry-slot result. Tests, not client calculations, own this rule.

## 16. Allocation Accessibility

The chart remains a useful visual and pointer/touch interaction, but it cannot be the sole editor.

Add a form-based allocation editor alongside the chart/table:

- group controls by 15-minute slot;
- label each numeric percentage input with time and entry context;
- constrain values to 0-100 with the supported precision;
- explain that saving one value redistributes the remainder;
- submit one target block through the same endpoint;
- announce success/error and updated sibling values;
- allow full keyboard operation; and
- disable the whole slot when any affected entry is billing-locked.

The accessible form and chart consume the same page DTO and mutation function. They are two views of one server-owned operation, not separate allocation algorithms. On mobile, prioritize the form/table and treat the wide chart as a secondary, scrollable visualization.

## 17. Operator Reports

Operator reports are in scope and remain protected by `time.view_all`.

Current functionality:

- filters for user, project, from/to date, and billable state;
- completed entries only;
- newest-first 50-row pagination;
- summaries by project and user;
- a headline total;
- query-string persistence; and
- CSV export of all filtered records.

Migrate the page to `operator/time/index` using normal Inertia props and GET visits. Use native select/date controls, preserve query strings and pagination, and update filters with `replace`/preserved state where appropriate. Keep CSV as a document download using the generated export URL with the same normalized filters.

The current CSV is assembled with string concatenation and does not consistently escape every user-controlled column. Preserve the download contract while switching export serialization to `fputcsv` or an equivalent structured writer, and neutralize spreadsheet-formula prefixes in names, labels, ticket numbers, and descriptions. Stream/chunk only if the live data volume justifies it; frontend migration alone does not require a new export pipeline.

Do not add TanStack Table. The report needs server pagination, straightforward filters, and a semantic table, not client-side sorting/grouping machinery.

Characterization tests must resolve two current ambiguities before JSX is written:

- `totalMinutes` currently sums only the current paginator page while grouped summaries cover the full filtered result; make the headline total consistently represent the full filtered result.
- An explicitly empty `billable` query can currently be cast to false; normalize empty to “all entries” and accept only `0` or `1` as actual filters.

These are report correctness fixes, not new reporting features. Do not add approvals, operator edits, task summary UI, invoice creation, or audit trails.

## 18. Timezones and Clock Correctness

The live application is fixed to UTC:

- `config/app.php` uses `UTC`;
- MariaDB has no connection-level timezone override;
- Eloquent timestamps are interpreted through Laravel's UTC application timezone;
- timer JSON uses ISO-8601 instants; and
- time-entry/block dates and 15-minute block numbers are currently UTC calendar values.

EPIC-011D preserves this contract. Parse `started_at` as an instant, estimate server/browser offset from `server_now`, and derive elapsed display without changing the persisted timestamp. Never derive the final duration in React.

Native date inputs continue submitting `YYYY-MM-DD` values interpreted by Laravel as UTC application dates. Because there is no user timezone preference, do not silently switch dates or allocation slots to browser-local time. DST does not alter the current UTC block model.

Periodic server polling is not required. Reconcile on layout initialization, successful/failed mutations, visibility recovery if testing demonstrates stale display after long suspension, and full-document transitions. A future user-timezone feature requires a separate product/domain decision.

## 19. Failure and Recovery

| Condition | Required behavior |
|---|---|
| Timer start rejected | Keep form values, show safe validation/error state, do not add a timer |
| Timer start network uncertainty | Disable duplicates while pending, then refresh active timers |
| Timer stop rejected | Keep timer visible, restore control, show error |
| Timer stop network uncertainty | Refresh active timers because stop may have committed |
| Description rejected | Retain authoritative description and show error |
| Stale active set | Replace it from `refreshTimers()` |
| Session expired / CSRF expired | Clear misleading pending UI and perform document-level auth recovery |
| Navigation during timer mutation | Keep provider pending state; block duplicate action; reconcile on completion |
| Manual entry mutation rejected | Preserve form and render Laravel validation errors |
| Billed/locked stale mutation | Show canonical lock error and refresh affected page data |
| Allocation rejected | Replace optimistic slot from authoritative page/response data |
| Server allocation differs | Server response wins without client-side correction loops |

Do not globally retry mutations. Stop is idempotent, but start intentionally permits multiple timers and must not be replayed automatically.

## 20. Why No TanStack/Global State Library Is Required

The live requirements consist of:

- one small active-timer resource initialized once per hard React load;
- four focused timer operations;
- local ticking presentation;
- ordinary Inertia page/filter/form state; and
- one interactive allocation component.

`TimerProvider` plus a reducer handles shared timer state. Inertia handles page/server state and pagination. Focused `fetch` calls handle existing JSON contracts. Component state handles selectors, forms, dialogs, and chart interaction.

There is no normalized entity graph, cross-tab synchronization requirement, independent polling fleet, offline queue, or large family of independently cached resources. TanStack Query, Redux, Zustand, and similar libraries would add duplicate cache/invalidation concepts without solving a demonstrated problem. Do not add them in EPIC-011D.

## 21. Shared Components

| Component | Classification | Guidance |
|---|---|---|
| `TimerProvider` | Time-module specific, mounted app-wide | Narrow timer API only |
| `RunningTimerBar` | Time-module specific, mounted app-wide | Multiple compact timers |
| `DurationDisplay` | Potentially app-wide | Extract only because timer/list/report repeat formatting |
| `TimerContextSelector` | Time-module specific | Reuse across timer/create/edit |
| `TimeEntryForm` | Time-module specific | Shared create/edit fields if duplication is real |
| `TimeEntryRow` | Time-module specific | Owns display/actions, not mutations |
| `LockedBadge` | Time-module specific initially | Use existing Badge primitive |
| `AllocationChart` | Time-module specific | Direct Chart.js lifecycle |
| `AllocationEditor` | Time-module specific | Accessible server-backed alternative |
| `DateRangeFilter` | Not initially abstracted | Two simple forms do not justify a generic API |
| Generic data table | Not warranted | Use semantic tables directly |

## 22. shadcn and Library Additions

Already available:

- Button;
- Input;
- Label;
- Badge;
- Alert;
- confirmation Dialog;
- Radix dropdown/dialog primitives; and
- Lucide icons.

No new shadcn component or npm library is required by the current plan. Prefer native `select`, `date`, number, and checkbox controls. A custom Calendar/Popover would add complexity without a date-range requirement beyond native dates. Use visible locked text and accessible labels rather than adding Tooltip solely to explain core state.

Continue using the installed Chart.js and drag-data plugin directly. Do not add `react-chartjs-2`, another chart library, or TanStack Table.

## 23. Wayfinder and Data Flow

Use generated helpers for every URL/mutation:

- `@/routes/time`: index, store, allocation, update, destroy;
- `@/routes/time/timer`: start, stop, description;
- `@/routes/time/timers`: active;
- `@/routes/time/context`: options;
- `@/routes/time/blocks`: allocation; and
- `@/routes/operator/time`: index and export.

Do not hand-build `/time/...` strings in React.

Page DTOs must be explicit:

- personal entries: display fields, derived lock/running state, minimal context, pagination;
- allocation: selected UTC date and entry/block matrix with display labels and lock state;
- report: normalized filters, filter options, summaries, full filtered total, minimal paginated rows.

After migration, mark Time and Time Reports navigation destinations as Inertia visits. Update the Dashboard Log Time quick action similarly. Context links to still-Blade project/task/ticket routes remain document visits.

## 24. Security and Privacy

Implementation must verify:

- every personal query is scoped to the authenticated `user_id`;
- entry stop, description, update, and delete reject another user's ID;
- block allocation rejects another user's block;
- context options expose only allowed records;
- submitted context IDs pass the existing resource/parent view boundary, including authorized embedded Blade tracker requests;
- only one context relationship can be attached;
- operator report/export remain behind `time.view_all`;
- billing and invoice-linked locks are re-read inside row-locked transactions;
- allocation checks every sibling entry before changing any block;
- stale UI cannot bypass server locks;
- timer and page DTOs contain no unrelated user/tenant/model attributes; and
- descriptions and context labels are rendered as React text, never injected HTML; and
- CSV export consistently quotes fields and neutralizes spreadsheet-formula input.

Do not infer authorization from a React prop or hidden control. Do not introduce mass assignment of request payloads into `TimeEntry`.

## 25. Blade Cleanup Strategy

Remove after route conversion and reference searches:

- `resources/views/time/index.blade.php`;
- `resources/views/time/allocation.blade.php`;
- `resources/views/operator/time/index.blade.php`;
- inline scripts owned by those pages; and
- `allocation-chart.js` after the React allocation component is active and no references remain.

Retain:

- `layouts/partials/timer-overlay.blade.php`;
- `timer-overlay.js`;
- its `app.js` initialization;
- `components/time-tracker.blade.php`; and
- Chart.js/plugin packages, now used by React allocation.

Do not remove the Blade overlay merely because all Time pages are React; remaining authenticated Blade modules still depend on it.

## 26. Testing Strategy

### Pest

Timer contract:

- permission and unauthenticated responses;
- exact minimal active/start DTO shape;
- multiple active timers and oldest-first order;
- start, stop, description, and active recovery;
- authoritative ISO timestamps and server clock field;
- stop partial-minute rounding and idempotent retry;
- invalid/multiple/inaccessible contexts;
- owner boundaries for every ID mutation;
- double-stop/concurrent stop behavior; and
- no partial state after rejected mutations.

Entries:

- Inertia component and minimal props for `time/index`;
- filters, pagination, and full filtered total;
- create/update/delete and validation;
- clearing/changing nullable description and context fields;
- decimal-hour conversion;
- another-user rejection;
- billed and invoice-linked locks; and
- stale lock change between page render and mutation.

Allocation:

- Inertia component and explicit allocation DTO;
- UTC date selection;
- valid redistribution and deterministic 100% rounding;
- invalid percentage/single-entry behavior;
- owner boundary;
- target and sibling billing locks;
- stale allocation/lock state;
- one mutation updates the whole slot atomically; and
- authoritative slot response.

Operator report:

- `time.view_all` boundary;
- Inertia component/props;
- user/project/date/billable filters, including empty billable;
- full-result totals independent of current page;
- pagination/query preservation;
- CSV permission, filter parity, content type, quote/newline handling, and formula neutralization.

### Vitest and React Testing Library

- elapsed display derived from timestamps with fake timers;
- server clock offset and non-negative elapsed behavior;
- TimerProvider hydration/reducer and per-ID pending state;
- start/stop/description success and reconciliation failure paths;
- persistent layout timer rendering;
- context type changes, stale request cancellation, options, and errors;
- manual create/edit validation presentation;
- locked/running action states;
- allocation chart lifecycle cleanup;
- one allocation request per completed drag;
- authoritative rollback/replacement on failure;
- keyboard/form allocation editor; and
- report/time filters and pagination links.

Do not duplicate PHP duration, redistribution, permission, or lock calculations in frontend tests.

### Playwright

1. Log in and start two timers.
2. Navigate Dashboard -> Profile -> Time with Inertia and verify the bar persists and elapsed time progresses.
3. Edit a timer description and stop one timer.
4. Navigate to a legacy Blade project/task or ticket page and verify the Blade overlay reconstructs the remaining timer.
5. Start/stop from an embedded Blade tracker, return to Inertia, and verify React reconstructs server state.
6. Create, edit, and delete a manual entry.
7. Render a billed/invoice-linked entry with unavailable controls and prove a stale request is rejected.
8. Adjust allocation by pointer and keyboard/form controls, then reload and verify persistence.
9. Filter operator reports and download matching CSV.
10. Exercise timer and tables at a mobile viewport.

Avoid exact millisecond assertions. Assert stable IDs/timestamps and reasonable elapsed progression.

## 27. Performance

- Active timers fetch once per persistent layout mount, not per Inertia visit.
- Context options load only after choosing a type; cap task/ticket results as today and avoid generic caching.
- Personal entries remain paginated at 25 and reports at 50.
- Summaries remain aggregate queries; do not send unbounded report rows to React.
- Allocation emits sparse block data and constructs 96-point datasets client-side.
- Destroy Chart.js instances on unmount and avoid rebuilding on each one-second timer tick.
- Keep TimerProvider state outside page props so Time page refreshes do not churn the timer.
- Use Inertia partial reloads for filter/date refreshes only where they measurably simplify payloads.

Do not add polling, deferred props, virtualization, or memoization until profiling demonstrates a need.

## 28. Work Packages

### WP1: Characterize and harden backend contracts

**Intent:** Freeze route behavior, explicit DTOs, context authorization, report filters/totals, and allocation response semantics before React depends on them.

**Likely files:** `TimeEntryController.php`, `Operator/TimeReportController.php`, `TimeEntryService.php`, focused request/rule classes if useful, Time feature tests.

**Tests first:** DTO shape, context boundaries, report totals/billable normalization, allocation atomic response/rounding, existing lock tests.

**Gate:** All backend contracts are explicit; no raw model response; existing Blade consumers remain compatible.

### WP2: Build TimerProvider and persistent timer bar

**Intent:** Implement focused endpoint hydration, reducer/actions, derived elapsed display, and AppLayout placement.

**Likely files:** `layouts/app-layout.tsx`, new `components/time/timer-provider.tsx`, `running-timer-bar.tsx`, `duration-display.tsx`, shared timer types/tests.

**Tests first:** hydration, multiple timers, fake-clock display, mutation reconciliation, session failure, AppLayout persistence.

**Gate:** Dashboard/Profile Inertia navigation preserves timer state; hard reload reconstructs it.

### WP3: Prove Blade/Inertia coexistence

**Intent:** Verify both renderers use the same server records across full-document transitions.

**Likely files:** Playwright specs; Blade timer only if an additive DTO field exposes an incompatibility.

**Tests first:** React -> Blade -> React start/stop/recovery flows.

**Gate:** Never two timer UIs in one document; no event bridge or React island on Blade.

### WP4: Migrate personal Time page

**Intent:** Convert index response, forms, selector, filters, entries, edit/delete, and navigation visit mode.

**Likely files:** `TimeEntryController.php`, `pages/time/index.tsx`, Time components, navigation/dashboard link builders, Pest/RTL tests.

**Tests first:** Inertia props, create/edit/delete validation, locks, context selector, filters/pagination.

**Gate:** Personal Time is fully usable in React and timer operations update the global provider.

### WP5: Migrate allocation

**Intent:** Convert page DTO, direct Chart.js component, single-request drag persistence, and accessible form editor.

**Likely files:** `pages/time/allocation.tsx`, allocation components/tests, controller/service endpoint tests.

**Tests first:** atomic redistribution, lock conflicts, chart lifecycle, single PATCH, keyboard editor, failure reload.

**Gate:** Pointer and keyboard users can perform the same server-backed adjustment; reload matches server state.

### WP6: Migrate operator reports

**Intent:** Convert report to Inertia while retaining server pagination and CSV download.

**Likely files:** `Operator/TimeReportController.php`, `pages/operator/time/index.tsx`, report tests.

**Tests first:** permissions, filters, totals, pagination, minimal props, export parity.

**Gate:** Report and export produce consistent filtered results without a client table library.

### WP7: Remove superseded Time presentation

**Intent:** Delete only page-owned Blade/JavaScript after all references are gone.

**Likely files:** three Blade pages, `allocation-chart.js`, `app.js`.

**Tests first:** reference searches, asset build, retained Blade timer smoke coverage.

**Gate:** No migrated page references remain; global Blade timer and embedded tracker still work.

### WP8: Browser, responsive, and recovery hardening

**Intent:** Validate end-to-end coexistence, mobile layouts, session expiry, network failures, and stale billing locks.

**Likely files:** Playwright specs and focused component tests; production code only for observed defects.

**Tests first:** complete critical-flow matrix.

**Gate:** Full Pest, frontend checks/build, Playwright, Pint, and `git diff --check` pass.

## 29. Rollback and Coexistence Safety

- Keep all timer JSON URIs and server records stable.
- Keep the Blade overlay operational and independently hydratable.
- React never writes authoritative elapsed duration or allocation totals.
- A React timer failure cannot corrupt a timer; reloading recovers from Laravel.
- Page responses can be reverted from Inertia to Blade without schema/data rollback.
- Other Blade modules keep their shell, timer partial, embedded tracker, and bundle.
- Billing locks remain enforced in row-locked service methods regardless of UI.
- CSV remains a plain Laravel response.
- Deployment still requires Node only for asset build; no SSR/runtime Node process is added.

## 30. Out of Scope

- Projects, kanban, milestones, and task page migration
- Ticket or operator ticket migration
- Billing, invoice authoring, Stripe, or time-to-invoice workflows
- CRM, organizations, CMS, or administration migration
- PDF/document generation
- Approvals, audit trails, operator time editing, or configurable edit lock windows
- New organization-level Time semantics
- Broad time/allocation domain redesign
- User timezone preferences or retroactive timezone conversion
- Cross-tab/live multi-browser synchronization
- Provider reauthentication or other authentication work
- TanStack Query/Table, Redux, Zustand, or another global state library
- `react-chartjs-2` or another charting library
- SSR, separate API/Sanctum architecture, CI, or runtime Node
- Removal of the global Blade timer before its final Blade consumer migrates

## 31. Genuine Open Decisions

No decision blocks implementation:

- Multiple simultaneous timers are explicitly intentional in code, tests, and EPIC-007.
- Operator reports are explicitly assigned to Phase D.
- Direct Chart.js is the simpler live-code-compatible approach.
- The accessible allocation form should be a first-class alternative rather than making drag keyboard-emulated.
- Active timer initialization should use the existing focused endpoint.

User-local timezone presentation remains a future product/domain decision. EPIC-011D must preserve the existing UTC contract rather than silently choosing browser-local behavior.

## 32. Acceptance Criteria

- [x] Personal Time, allocation, and operator report pages render through Inertia/React.
- [x] TimerProvider hydrates once per persistent layout mount and supports multiple timers.
- [x] Timer elapsed display is derived from server timestamps and corrected for browser/server offset.
- [x] Start, stop, and description mutations reconcile with Laravel authority.
- [x] React timer persists across Inertia navigation and reconstructs after hard/Blade transitions.
- [x] Blade pages retain exactly one legacy timer UI using the same endpoints.
- [x] Manual create/edit/delete and filters preserve validation and permissions.
- [x] Context options and submitted IDs enforce existing resource/parent view boundaries without breaking authorized embedded Blade trackers.
- [x] Billed and invoice-linked entries remain immutable, including stale-page attempts.
- [x] Allocation drag sends one mutation and consumes the authoritative adjusted slot.
- [x] Allocation has an equivalent keyboard/form workflow.
- [x] Operator filters, full-result totals, pagination, and CSV agree.
- [x] No TanStack/global state/chart wrapper dependency is introduced.
- [x] Superseded Time Blade pages and allocation script are removed only after reference checks.
- [x] Global Blade timer files remain until later module phases retire their consumers.
- [x] Pest, Vitest/RTL, Playwright, TypeScript, ESLint, Prettier, Vite build, Pint, and diff checks pass.

---

## 33. Post-Review Hardening Notes

An independent review of the implemented phase found no authorization, billing-lock, timer, allocation-data, or CSV-injection defect. The following correctness and accessibility gaps were then closed without changing the architecture or adding dependencies. A final domain-correctness pass then resolved sub-15-minute timer-entry editing and the slot allocation invariant (below). With every acceptance criterion satisfied and the Pest, Vitest, Playwright, TypeScript, ESLint, Prettier, build, Pint, and diff-check gates passing, the epic is **Verified**.

- **Timer refresh ordering.** `TimerProvider` sequences refreshes: only the newest may publish, and a read that began before a successful start/stop/description mutation is re-read instead of applied, so a stale response can no longer remove a running timer. There is still no client-side merge and no query cache.
- **Description draft.** The running-timer description draft is seeded from the current authoritative description each time editing begins and is never overwritten mid-edit.
- **Allocation chart.** The direct Chart.js instance is created once per mounted dataset and updated in place; drag callbacks read the latest entries, processing slots, and `onAdjust` through a ref. Unrelated page renders no longer destroy it.
- **Allocation editor.** Rows are keyed by block ID, adopt server-redistributed values without remounting, and use `readOnly`/`aria-disabled` while a slot saves (a disabled focused control drops keyboard focus to `<body>` in Chromium). Enter submits. The status message names the adjusted entry's new value and the redistributed siblings.
- **Allocation concurrency.** In-flight slots are claimed synchronously in a ref, so two same-tick actions send one request. Per-slot version counters stop an older authoritative reload from clearing a slot mutated after the reload began; a successful reload clears stale client overrides for the slots it covers.
- **Operator report.** Export mirrors the server-applied `filters` prop rather than unapplied draft controls; rows sort by `date` then `id` so pagination is deterministic; `from`/`to` are always serialized (as `null` when absent).
- **Personal `ticket_id` filter.** The Blade page never exposed a ticket control, so it stays backend-only. The React page now carries an active `ticket_id` through Apply instead of silently dropping it.
- **Partially stopped legacy rows.** A row with both `timer_started_at` and `stopped_at` is a corrupt legacy shape (see EPIC-007). It still lists, but its total is excluded until `stopTimer()` normalizes it; this is pinned by a test rather than redefined.
- **Project context options.** Options now list only projects the user is a member of, matching `ProjectPolicy::view`, so a project the creator has since left is not offered only to be rejected on submit.

Deferred as out of scope for the hardening passes: context-options N+1, UTF-8 BOM in CSV, unbounded operator selectors, stacked-axis drag-value semantics, drag-time sibling preview, and user-local timezones.

### Short timer entries

`duration_minutes` stores whole minutes, and a timer can legitimately stop after a few seconds (stored as 1 minute) or a few minutes (7). Manual creation intentionally keeps its 15-minute minimum, but that minimum must not apply to re-saving an existing entry, or a short timer entry could never have its description, context, or date changed without being inflated to 15 minutes. Editing therefore accepts any positive whole-minute duration up to 24 hours: `hours` must be at least `0.01` (0.6 minutes, which converts to 1), and `TimeEntryService` refuses to store anything that converts to zero minutes. Two-decimal hours round-trip every whole minute exactly, because the rounding error (at most 0.3 minutes) is under half a minute. Timer stopping is unchanged. A timer that ran longer than 24 hours can still only be edited down to 24 hours or less; that is the existing maximum, not new behavior.

### Allocation semantics and invariant

`time_entry_blocks.allocation_pct` is the share of one user's 15-minute UTC slot attributed to one finalized timer entry. **The invariant is: for one user, date, and slot, the allocation percentages of all blocks sum to exactly 100%.** The evidence is consistent across the domain: the migration documents that concurrent allocations "for each block sum to 100%", `TimeEntryBlock::minutesAllocated()` treats the value as a share of the 15-minute block, `updateBlockAllocation()` redistributes the whole `(user, date, slot)` group to 100 and rejects any single-entry slot other than 100, and the allocation page's stacked 0–100 chart and "Total" legend assume it. Independent 100% allocations per entry were **not** intended.

The defect was in finalization: it only rebalanced entries whose wall-clock intervals overlapped the timer being stopped, so sequential short timers inside one slot each finalized at 100% (200–400% totals) until a manual adjustment normalized the slot. It also rewrote unlocked-looking blocks of entries that had since become billing-locked. Nothing outside the allocation UI consumes the percentage: reports, the dashboard, and CSV export use `duration_minutes`, and `InvoiceService` does not read time entries, so the over-100% state affected only the allocation display and the editor, never attributed time, billable totals, or invoices.

#### Slot lifecycle rules

`allocation_pct` and `is_overridden` follow these rules, implemented once in `TimeEntryService` and covered by `AllocationInvariantTest` and `AllocationLifecycleTest`.

- **Invariant.** For one user, date, and slot, surviving blocks sum to exactly 100%, except where immutable billed history consumes part or all of the slot.
- **`is_overridden` means** "a user's explicit allocation choice for the set of entries currently in this slot". It is sticky while membership is unchanged: an ordinary manual adjustment is not a membership change, marks every block of the slot as overridden, and other activity (edits, description changes, a retried stop, entries finalizing in other slots) leaves it alone.
- **A structural change invalidates it.** A mutable entry joining a slot (a timer finalizing into it) or leaving it (the entry being deleted) makes earlier manual choices stale, because they were made for a different set of entries. The affected slot's mutable blocks are recomputed, their override flag is cleared, and the user may override the new composition again. Slots the change does not touch keep their overrides. This stops an old 100% override from forcing every later sibling to 0% merely because that sibling did not exist when the choice was made.
- **Billed and invoice-linked blocks are immutable history**, not preferences, and stay frozen through joins and leaves (EPIC-010C). Only mutable entries share `max(0, 100 − frozen total)`. A billed 100% block therefore leaves a joining timer at 0%, and a slot holding only locked blocks keeps whatever they total. Locked history is never moved to manufacture room.
- **Weighting.** Mutable entries share the remainder by the seconds each spent in the slot, measured identically for every entry from its persisted interval (`stopped_at − duration_minutes … stopped_at`, at least one second), so the result depends only on the set of entries and never on stop or delete order.
- **Deleting an entry rebalances** every slot it occupied. The slots are identified and locked before its blocks disappear and the survivors are recomputed in the same transaction; deleting an entry with no blocks, or the last member of a slot, simply removes it. A failure during redistribution rolls the deletion back. A billed or invoice-linked entry still cannot be deleted.
- **One engine.** `allocateSlot()` is the single primitive: frozen participants keep their percentage and the rest share the remainder through `distribute()`, which owns the rounding (two decimals, last participant by id takes the remainder, no negative share). Manual editing calls it with the edited block pinned and siblings weighted by their current shares; finalization and deletion call it through `structuralRows()` with only billed history frozen and seconds weights. There is no separate deletion algorithm.
- **Locking.** Stop, adjust, and delete all take a row lock on the owning user first, then the user's blocks, then sibling entries, so one user's allocation writes queue instead of deadlocking. Parallel processes stopping, deleting, and manually adjusting one user's slot against MariaDB produced no errors and exactly 100% slots.
- **Self-healing.** A legacy over- or under-allocated slot is normalized the next time an entry joins or leaves it. No data migration was added.

Known related gap, deliberately not changed: a timer that runs under one second stores no allocation block because blocks are built from whole seconds.

### Browser-test data hygiene

The Playwright suite shares the development database, so every record a test creates must be deleted again, including when the test fails. `tests/Browser/support/e2e-fixtures.ts` provides an automatic per-test teardown: it records timer entry IDs from the start responses, registers a fixture project as soon as it exists, and deletes them through the application's own endpoints (deleting an entry cascades its allocation blocks; deleting the project cascades its columns and tasks). The Blade-tracker test uses a throwaway operator project and task rather than a ticket because tickets have no delete route. Tests also generate their own allocation data instead of depending on another test's leftovers. Fortify allows five login attempts per minute per email and a full run signs in more often than that, so `tests/Browser/support/sign-in.ts` waits out a `429 Retry-After` instead of failing whichever test signed in sixth. Repeated back-to-back full-suite runs leave every table unchanged except `sessions`. Those rows are deliberately not cleaned: the suite signs in as the shared seeded development accounts (`operator@intechral.test`, `user@intechral.test`), whose sessions cannot be told apart from a developer's own browser sessions, and many rows are anonymous guest sessions created by visiting `/login`. Deleting them could log the maintainer out, so they are left to Laravel's session garbage collection (`SESSION_LIFETIME`, 480 minutes).
