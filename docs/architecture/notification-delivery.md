# Notification Delivery: Eligibility, SMTP Bounds and Retries (R0.9 WP1)

**Authority:** the Release 0.9 shared-hosting runtime gate (database cache, sessions and queue; no Redis, no persistent
worker; a five-minute cron drain is acceptable). This document records WP1 only: what a queued mail re-checks when the
worker runs it, how long a send can take, how often it is retried, and what it still cannot promise.
**Companions:** [Account Lifecycle](./account-lifecycle.md) (the suspension check) · [Authentication Flow](./auth-flow.md)
(invitations).

Three notifications are queued (`ShouldQueue`): `InvitationNotification`, `TicketCreatedNotification` and
`TicketRepliedNotification`. Password reset is **not** queued and remains synchronous: it is sent inside the request, with no
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
whether and how to set it belongs to the host-runtime decision.

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
