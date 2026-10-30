# Notification Delivery: Eligibility, SMTP Bounds and Retries (R0.9 WP1)

**Authority:** the Release 0.9 shared-hosting runtime gate (database cache, sessions and queue; no Redis, no persistent
worker; a five-minute cron drain is acceptable). This document records WP1 only: what a queued mail re-checks when the
worker runs it, how long a send can take, how often it is retried, and what it still cannot promise.
**Companions:** [Account Lifecycle](./account-lifecycle.md) (the suspension check) · [Authentication Flow](./auth-flow.md)
(invitations).

Five notifications are queued (`ShouldQueue`): `InvitationNotification`, `TicketCreatedNotification`,
`TicketRepliedNotification` and, since S1 (EPIC-017 O3), `NewTicketAlertNotification` and `TicketStatusNotification`
([section 6](#6-the-o3-notifications-s1)). Password reset is **not** queued and remains synchronous: it is sent inside the request, with no
retries and no send-time check of its own. It does, however, use the same SMTP transport and so **inherits the new
`MAIL_TIMEOUT`** (a request that sends a reset mail to a silent server now waits about 40 s at the default instead of about
120 s, and then fails with the same transport error).

## 1. Send-time eligibility

Laravel calls `shouldSend($notifiable, $channel)` and then fires `NotificationSending` immediately before each channel
delivers, which for a queued notification is inside the job. Anything that can change between "decided to send" and "sent"
is therefore decided there, from persisted state read fresh, never from the serialized model.

| Notification | Dropped at send time when | Rule |
|---|---|---|
| `InvitationNotification` | the invitation was re-issued, accepted, expired by time, or deleted | the row is re-read and must satisfy `Invitation::isPending()`, the rule the accept link itself uses. `InvitationService::invite()` marks every earlier pending invitation for the address `expired`, so a re-issued link is dead and is not mailed. A deleted row is discarded (`#[DeleteWhenMissingModels]`). |
| `TicketRepliedNotification` | the ticket or reply is gone; the reply is not on this ticket; the reply is an internal note; the recipient may no longer **view** the ticket | `Gate::forUser($recipient)->allows('view', $freshTicket)`: the `TicketPolicy` every ticket screen uses (owner, or `tickets.assign` and not a restricted customer, so an assignee who keeps `tickets.assign` but loses `portal.operator` is cut off). The reply's `ticket_id` must equal the ticket being authorised. No access rule of its own. |
| all three | the recipient was suspended after queueing | unchanged: `SkipSuspendedRecipients` on `NotificationSending` (R0.9-1C). |
| both ticket notifications | the ticket (or reply) was deleted | unchanged: `#[DeleteWhenMissingModels]` discards the job; no `failed_jobs` row. |

Visibility boundaries are preserved, not widened. `TicketService::replyRecipients()` still decides at dispatch who is
queued (no one for an internal note; owner and assignee for a public reply, minus the author and suspended accounts). The
send-time check can only remove a recipient, never add one. An owner can always view their own ticket, so losing a role
permission never cuts an owner off.

**Ineligible versus unavailable.** A rule returning `false` is a decision: the mail is dropped and the job completes. A
database error while evaluating the rule is **not** a decision. `shouldSend()` does not catch it, so the attempt fails, the
job is released with its backoff and is retried. A transient outage can therefore never silently discard an invitation or
reply that the recipient is still entitled to. Both paths are tested with a real database queue and worker.

## 2. SMTP timeout

`MAIL_TIMEOUT` (seconds, default **20**) sets `mail.mailers.smtp.timeout`. Before WP1 it was `null`, which falls back to
PHP's `default_socket_timeout` (60 s). A value that is not a positive number (blank, non-numeric, zero, negative) falls back to
the 20 s default; a positive value below 1 is raised to 1 s. It never means "wait forever".

**What `MAIL_TIMEOUT` is: a per-read idle limit, not a bound on the whole send.** It is applied with `stream_set_timeout`
and enforced by the kernel socket. Each blocking read or write may wait that long, and every successful read starts the clock
again. It needs no `pcntl`, which makes it the one bound that holds on a host where worker signals are unavailable. It does
not cap the total time of a send. Measurements against an isolated SMTP server (Symfony Mailer 7.4; evidence kept in
`backups/dev/r09-wp1-notification-safety/evidence/` on the development machine), all at `MAIL_TIMEOUT=20`:

| Peer behaviour | Send duration | What it shows |
|---|---|---|
| greets, then goes silent | 40.1 s (three runs: 40.1, 40.1, 40.1) | two idle waits: the `EHLO` read times out, Symfony retries with `HELO`, and that read times out too. This is why the earlier gate saw ~120 s at the 60 s default |
| never greets | one idle wait (timing test at a 1 s limit: 1.08 s) | the greeting read times out; there is no `HELO` retry on this path |
| answers every command, each reply just inside the limit (19 s) | 95.2 s, delivered | the idle limit never fires; the send is long but legitimate |

These are measurements of two tested behaviours, **not a formula and not a ceiling**. The number of reads in a send is not
fixed: `STARTTLS` and `AUTH` add round trips, a connection reused across messages adds a `NOOP`, a failed command adds a `RSET`,
and Symfony retransmits or falls back on some failures (`EHLO` to `HELO`). A server that is responsive, or that drips bytes
slowly enough never to idle for a full interval, can keep a connection open for far longer than any multiple of
`MAIL_TIMEOUT`. DNS resolution, the TLS handshake and unusual transport behaviour are outside the idle limit altogether (the
operating system's resolver timeouts apply). Do not read `MAIL_TIMEOUT` as "a mail job never runs longer than N seconds".

**Worker `--timeout` is a second, weaker bound.** It is a `pcntl` alarm, so it works only where signals work, and PHP handles
the signal only when the blocked call returns. In one run the default `--timeout=60` let a slow peer's send run for about
96 s before the worker was killed (exit code 1), leaving the job **reserved** until `retry_after`. A worker `--timeout` is
therefore a useful safeguard where signals are supported, not a hard limit, and is not part of this package's behaviour:
whether and how to set it belongs to the host-runtime decision (decided in [Cron Execution](./cron-execution.md): `--timeout=120` with `--max-time=120`, an external `timeout` of 255 s plus 15 s, and `retry_after` 300).

## 3. Retries

Each of the three notifications carries `#[Tries(3)]` and `#[Backoff(60, 180)]` (the Laravel 13 queued-notification
attributes, read by `SendQueuedNotifications`): three attempts, the second 60 s after the first fails and the third
180 s after the second. These attributes take precedence over a worker's `--tries`. A third failure moves the job to
`failed_jobs` with the transport exception. With a five-minute cron drain, each retry is simply picked up by the next run
after it becomes available, so the real spacing is rounded up to the cron interval.

## 4. Reservation window (`DB_QUEUE_RETRY_AFTER`)

The database queue reserves a job when a worker takes it and makes it available again after `retry_after` seconds. If a job
is still running when that expires, a second worker takes it and the **same mail is sent concurrently**.

**The configuration objective** is:

> `retry_after` > the worker's execution bound, plus an allowance for delayed interruption and blocking I/O.

This is an objective to configure towards and validate on the production host, **not an unconditional guarantee**, because
neither term is a hard number. The send itself has no total bound (section 2). The worker's own bound is a `--timeout`, which
works only with functioning signal support and is acted on late: a blocked read has to return first. The allowance has to
cover that delay and any DNS, TLS or slow-peer time on top.

What was measured at `MAIL_TIMEOUT=20`:

| Run | Result |
|---|---|
| silent peer after greeting, `retry_after=300`, a second worker polling every 2 s through three attempts | 40.1 s per attempt, connections never overlapped, third failure reached `failed_jobs` |
| slow peer (every reply 19 s), worker `--timeout=240` | 95.2 s, delivered, job deleted |
| slow peer, default worker `--timeout=60` | worker killed after ~96 s, job left reserved |
| **control:** silent peer with `retry_after=30` | a second worker started the same job at +34.5 s, **while the first was still sending** (overlapping connections) |

`DB_QUEUE_RETRY_AFTER` defaults to **300** (owner-approved default, 3.1 times the slowest measured send). Treat it, `MAIL_TIMEOUT=20`
and the worker settings as defaults **subject to production host validation**: re-check them on the real host (SMTP provider
latency, signal support, resolver behaviour) before relying on them, and re-check whenever `MAIL_TIMEOUT` changes.

## 5. What this does not guarantee

Delivery is **at least once**, not exactly once. SMTP gives the sender no way to learn that a message it has fully
transmitted was accepted if the reply is lost. The tested case: a server took the whole message (logged `accepted`) and
never sent the final `250`; the client timed out after 20.2 s, the job was retried 66 s later, and the server accepted the
**same message again**. The same ambiguity follows any worker death between the server's acceptance and the job's deletion
(killed process, host restart, a `pcntl` timeout, an exhausted reservation). Mitigations are bounded, not eliminating:
the timeout shortens the window, the three attempts cap the repetition, and the recipient-visible content is idempotent
(a repeated invitation carries the same live link; a repeated reply notice names the same ticket). Do not build on the
assumption that a mail is sent once.

Other residual risks: a responsive or byte-dripping peer can hold a send longer than any idle limit, and past `retry_after`
if that is set too low (section 4);
`shouldSend()` narrows but cannot close the window between its check and the SMTP acceptance; a hung peer consumes one of
the three attempts, so three consecutive silent-peer hangs at the default timeout fail the job after about 6 minutes (40 + 60 + 40 + 180 + 40 s).

## 6. The O3 notifications (S1)

Closes EPIC-017 O3, option (c) ([EPIC-017 section 11](../epics/EPIC-017-helpdesk-mvp-customer-shell.md#11-email-and-notifications)):
nobody was told when a request arrived (G-E1), and a customer was not told when it needed them or was resolved (G-E2). Same retry policy as every
queued mail here: three attempts, 60 s then 180 s apart, a vanished ticket discarded rather than failed.

| Event | Recipient | Sent when | Not sent when |
|---|---|---|---|
| A customer raises a request | **one** mailbox, `HELPDESK_NOTIFY_ADDRESS` (an address, not an account) | after the ticket and its attachments commit | the address is unset or unusable (logged, the request still succeeds); the creation rolls back |
| Status becomes `pending_user` ("Waiting on you") | the requester | after the transition commits | the requester made the change; the account is suspended; they may not `view` the ticket |
| Status becomes `resolved` | the requester | as above | as above |

Never mailed by these: the assignee, any operator, any `tickets.assign` holder (that is the O3 option (d) the owner did not choose), anyone on a
customer reply (`pending_user` to `in_progress`, `resolved` to `open`) or on `tickets:auto-close` (to `closed`), or on any other status.

**After commit, including inside a caller's transaction.** The requester's confirmation, the operator alert and the status mails are each handed over with
`DB::afterCommit` (through `TicketService::handOffAfterCommit`), not by position in the code. `TicketService::transition()` also runs inside the bulk action's batch
transaction, which commits or rolls back as one, so a rolled-back batch announces nothing and a committed one announces every ticket once. Replies keep their original
placement: after the service's own transaction.

**A hand-over that fails does not undo, or hide, a commit.** By the time the callback runs the ticket change is committed, so an exception from the `jobs` insert (or from
the policy lookup that decides who to tell) would surface from the commit, make the request answer 500 for something that succeeded, and stop every later callback, which in a
bulk update is every remaining ticket's mail. `handOffAfterCommit` therefore catches the `Throwable`, calls `report()` and carries on: the customer sees success, the next
notification is still handed over, and the failure is in the log. Each hand-over is its own callback, so on ticket creation the customer confirmation and the operator alert
fail independently. This is the dispatch side only. The queue worker is unchanged: a database error inside `shouldSend()` still propagates, fails the attempt and is retried
(60 s, then 180 s). A failed hand-over is not retried (nothing was queued); the log line is the record, and for an alert the first real one is checked in the acceptance rows.

**Re-decided when the worker runs the status mail** (`TicketStatusNotification::shouldSend`), in this order: the ticket still exists; the recipient is
the ticket's requester; the ticket is still in the status being announced; **no later status change exists**; the recipient still passes the ticket policy's
`view`. A suspended account is dropped earlier, by the existing H3 listener. The "no later change" rule is what stops two "Resolved" mails when an operator
flips resolved, open, resolved inside the queue delay (only the mail for the newest change is sent), and stops "Waiting on you" arriving about a request
that has since been resolved or closed. A database error here is not caught: it fails the attempt and the queue retries.

**What the mails contain.** The alert: number, title, category, priority, requester name, a preview of the description cut to 200 characters, and a link to
`operator.tickets.show`. The status mails: number, title, the customer label ([`RequesterTicketPresenter::STATUS_LABELS`](../../src/app/Http/Presenters/RequesterTicketPresenter.php)),
what to do, and a link to `tickets.show`; "Resolved" says a reply reopens the request and that it is closed automatically after
`TicketService::AUTO_CLOSE_HOURS` (72) hours. None carries an internal note, a reply, an attachment name, a requester address, SLA wording or the name of the
person who changed the status.

**User-authored text (F-3).** Laravel renders every `MailMessage` line, greeting and name from one Blade template twice: through CommonMark into the HTML part, and
through plain `{{ }}` encoding plus one `html_entity_decode` into the text/plain part. A title or name containing `[text](https://elsewhere)`, `![x](https://elsewhere/p.png)`,
`**` or a backtick used to become a live link, a remote image or formatting in the HTML part; and the first fix (a backslash before every punctuation character) showed those
backslashes verbatim in the text part (S1 review M1). The representation that is right for both is a **numeric character reference**: CommonMark decodes `&#42;` to a literal `*`
and never lets a reference stand for structure, and the text part's `html_entity_decode` turns it back into the typed character. All user-authored text in all five
notifications therefore goes through [`MailText`](../../src/app/Support/Mail/MailText.php): `plain()` for a subject (one line, controls and bidirectional overrides removed),
`escape()` for one piece of user text (the same, then every ASCII punctuation character as `&#NN;`, returned as an `HtmlString` that neither template encodes again) and
`line()` for a line or greeting that mixes the template's own words, which keep their Markdown (`**Subject:**`), with escaped parts. No Markdown service, theme or view is
replaced. A genuine backslash is `&#92;`, which CommonMark does not read as an escape character, so it is kept.
The tests deliver each of the five notifications through the real mail channel and read both parts: the HTML part must contain the typed characters, link only to the
application and have the same elements as the same message with innocuous text; the text part must hold the typed text exactly and be the same message as the innocuous one
in every other character. The payloads are Markdown links, images, emphasis, code, raw HTML, autolinks, reference links, lists, tables, entities, every ASCII punctuation mark,
Unicode and literal backslashes.
Limits, stated: a bare `https://` in the text stays text in this HTML, but a mail client may link it itself; Laravel's renderer ends with `str_replace('\[', '[')` over the HTML
part, so a backslash a person typed immediately before `[` is not shown there (the text part is untouched); a reply preview is one line (its line breaks used to survive; it is
still the first 200 characters followed by `...`). Per-recipient links (operator mail linking to the operator page) remain WP5 work and are unchanged: the reply mail still links to `/tickets/{id}`.

**Closed customer-surface mode (accepted for this release).** The customer mails (confirmation, reply, Waiting on you, Resolved) are not suppressed by `PORTAL_CUSTOMER_SURFACE_MODE`.
In `closed` mode a customer's ticket routes are server-side 404s ([Customer Pilot Surface](./customer-pilot-surface.md)), so the link in such a mail leads nowhere (tested:
`TicketStatusNotificationTest`, "closed customer-surface mode"). A customer cannot raise a request in closed mode, so only an operator working an older ticket reaches this, and
production sets `pilot` before the first invitation. Suppressing the mail by mode would couple the notifications to customer-surface routing, which this release does not do.

**`HELPDESK_NOTIFY_ADDRESS` is part of the production profile.** One valid address (not a list, not `example.com`/`.org`/`.net`), read through
`helpdesk.notify_address`. `runtime:preflight` refuses it unset or malformed, and so do the queue and scheduler commands (A3), so a release cannot go
live with the alert silently off (RE-H13). Local and test environments may leave it unset: the alert is skipped with a warning (an error in production,
which the preflight should have prevented). The test suite pins it to empty in `tests/bootstrap.php`, so a value exported in the shell or the container cannot change what the ticket tests assert
(guard: `tests/Unit/Configuration/TestEnvironmentPinTest.php`); a test that needs the alert sets `helpdesk.notify_address` itself. **The staging installation runs `APP_ENV=production` and is held to the same rule**; give it an owner-controlled
address that the staging mail sink captures. Configuration proves the address is well formed; **that mail reaches it is proved only by a real alert**, which
the first-install acceptance and the production acceptance each record. The alert is also the cheapest independent record of every ticket number issued, which
is why the destination should be a mailbox that is kept ([EPIC-017 C6](../product/release-0.9-scope-and-architecture.md#73-ticket-number-continuity)).
