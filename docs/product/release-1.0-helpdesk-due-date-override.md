# Release 1.0: Help Desk due-date overrides

Operators can move a ticket's working due date when work is delayed (waiting on a customer or an external dependency). The original SLA
deadline is never changed.

## Model

- `tickets.sla_due_at`: the original SLA deadline, set at creation. **Immutable**: `Ticket` refuses an update that changes it.
- `tickets.due_override_at`: nullable, operator-set. Not mass-assignable; written only by `TicketService::addReply()`.
- **Effective due date** = `due_override_at ?? sla_due_at` (`Ticket::effectiveDueAt()`).
- `ticket_due_date_changes`: one row per adjustment (previous and new override, actor, time). `reply_id` is NOT NULL and UNIQUE, and
  (`ticket_id`, `reply_id`) is a composite foreign key to `ticket_replies (ticket_id, id)`, so a change always has exactly one
  explaining reply or note and that reply belongs to the same ticket. A null new value means "restored to the SLA deadline".

## Behaviour

- An adjustment is an optional part of the operator reply/note form (`due_action` = keep | set | restore, and `due_at` for set). It is
  never a separate edit. The message is required, including for a restore. It may accompany a public reply or an internal note.
- Authorization: `TicketPolicy::adjustDueDate` (operator = `tickets.assign` and not a restricted customer, on a ticket they can
  view). The controller checks it before validation whenever a due-date field is sent, so a requester gets a 403 and nothing is written;
  `TicketService::addReply` refuses a customer author again.
- Rules, checked on the row locked by `addReply`: ticket still open; a new date is in the future and at most 365 days ahead; not the
  same date again; restore only when an override exists (and allowed even if the SLA deadline is already past).
- Atomic: reply, attachments, files, the override and the history row commit together under the existing row lock, or none does.
- Time zone: the `datetime-local` value is read in `app.timezone` (UTC, pinned by `RuntimeProfile`), named beside the field.
  `DueDateInput` parses strictly and refuses a wall-clock time that does not exist; an ambiguous one means its first occurrence.
- Visibility: the change line appears only on the operator ticket page. The requester presenter, props and reply mail carry no due data.

## Operator composer controls (HD-C1)

The reply form's controls are presentation over the unchanged contract above. They are two Blade components
(`x-ui.visibility-toggle`, `x-ui.due-date-control`) driven by `resources/js/shell/blade-ticket-composer.ts`; the page is still Blade, with no
new endpoint, no auto-save and no due-date-only submit. The submit button reads **Post Update**.

- **Visibility.** A pressed-state button, "Visible" (eye) or "Hidden" (eye-off), posting `is_internal` = `0` / `1`. Pressed means Hidden, an
  internal note. It is rendered only for an actor who passes `TicketPolicy::viewInternal`; the reply controller still demotes the flag for
  any actor who does not, so the control can grant nothing.
- **Due date.** One button showing the effective date and zone (or "No due date"). Clicking it opens a small **editor panel anchored
  beneath the button** (a popover, "Change due date", with the zone, one native `datetime-local` field and a muted relative-time line);
  the joined controls never change size or position while it is open. The script infers the existing fields: untouched → `keep` (no
  `due_at`); a different date → `set` + that wall-clock text; the explicit "Restore SLA" button (offered only while an override exists and
  a restore is not already pending) → `restore` (no `due_at`). Returning to the original date clears the change. The change stays pending,
  shown on the button by a dashed edge and a "Pending" mark, until **Post Update**; the message stays required. **Undo change** (a small
  text action in the panel, shown only while something is pending) drops the pending change; it is distinct from Restore SLA, which is a
  pending change in its own right.
- **Editor.** Enter finishes the local edit (it never submits the form); Escape cancels it and returns focus to the button; focus leaving
  it, or pressing the button again, commits it. It is detached from the form (`form` names no form), so it is never posted and the browser
  never blocks a plain reply on it. An unfinished or refused value (half-typed, past, beyond the horizon) keeps the panel open with the
  reason beside the field, and a post made while it is open finishes or stops. The panel is `absolute`, so opening it moves nothing; the
  script only keeps it inside the viewport (two custom properties, and it opens upward when there is no room below).
- **Zone.** A typed date is read in `app.timezone` (an IANA name sent as `data-timezone`) by `lib/due-deadline.ts`, which mirrors
  `DueDateInput::parse`: the browser's zone is never consulted. "Now" is the browser clock corrected by the server's clock at page load.
- **Urgency** of the effective or pending deadline: **green** more than 4 h left; **yellow** from 4 h before through 1 h after the deadline,
  inclusive; **red** more than 1 h overdue; **neutral** no deadline. Each level has its own glyph, and the exact relative time ("Due in 3
  hours", "Overdue by 30 minutes") is the tooltip/description. It re-reads every 30 s while the tab is visible.
- **After a refused post** the visibility, the pending date or restore and the server's reason are redisplayed (`old()`); a date is kept as
  long as it is a real wall-clock time, so the request is not silently lost.

## Display and reporting

- Operator queue and ticket page say "Due". Without an override the date carries a small "SLA" tag; with one, the tag is gone and the
  original SLA is disclosed ("Adjusted" disclosure in the queue, a visible line on the ticket page).
- "Overdue" and queue ordering use the effective due date (`isOverdue()`, `scopeOverdue`, `scopeOrderByEffectiveDue`).
- CSV export: `SLA Due` is unchanged (the original); `Effective Due` is appended.
- Not in 1.0: SLA compliance reporting, pause/resume, business hours, new notifications, task due dates.

## Compatibility

Additive migration (`2026_11_04_120000_add_ticket_due_date_override`), no backfill. The Release 0.9 build ignores the new column and
table. Rehearsed on populated data: see [migration rehearsal](../testing/migration-rehearsal.md).
