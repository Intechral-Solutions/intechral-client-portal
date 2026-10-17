# Notification Delivery: Eligibility, SMTP Bounds and Retries (R0.9 WP1)

**Authority:** the Release 0.9 shared-hosting runtime gate (database cache, sessions and queue; no Redis, no persistent
worker; a five-minute cron drain is acceptable). This document records WP1 only: what a queued mail re-checks when the
worker runs it, how long a send can take, how often it is retried, and what it still cannot promise.
**Companions:** [Account Lifecycle](./account-lifecycle.md) (the suspension check) · [Authentication Flow](./auth-flow.md)
(invitations).

Three notifications are queued (`ShouldQueue`): `InvitationNotification`, `TicketCreatedNotification` and
`TicketRepliedNotification`. Password reset is **not** queued and is unchanged: it is sent synchronously inside the request.

## 1. Send-time eligibility

Laravel calls `shouldSend($notifiable, $channel)` and then fires `NotificationSending` immediately before each channel
delivers, which for a queued notification is inside the job. Anything that can change between "decided to send" and "sent"
is therefore decided there, from persisted state read fresh, never from the serialized model.

| Notification | Dropped at send time when | Rule |
|---|---|---|
| `InvitationNotification` | the invitation was re-issued, accepted, expired by time, or deleted | the row is re-read and must satisfy `Invitation::isPending()`, the rule the accept link itself uses. `InvitationService::invite()` marks every earlier pending invitation for the address `expired`, so a re-issued link is dead and is not mailed. A deleted row is discarded (`#[DeleteWhenMissingModels]`). |
| `TicketRepliedNotification` | the ticket or reply is gone; the reply is an internal note; the recipient may no longer **view** the ticket | `Gate::forUser($recipient)->allows('view', $freshTicket)`: the `TicketPolicy` every ticket screen uses (owner, or `tickets.assign` and not a restricted customer). No access rule of its own. |
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
PHP's `default_socket_timeout` (60 s). A blank, zero or negative value falls back to the default (a negative becomes 1 s);
it never means "wait forever".

What the timeout actually bounds (Symfony Mailer 7.4, measured against an isolated SMTP server, all in
`backups/dev/r09-wp1-notification-safety/evidence/` on the development machine):

* It is a **per-read idle limit** (`stream_set_timeout`), enforced by the kernel socket. It does not need `pcntl`, and it
  holds on hosts where a worker's `--timeout` cannot be enforced.
* A peer that greets and then goes silent costs **two** timeouts: the `EHLO` read times out, Symfony retries with `HELO`,
  and that read times out as well. This is the cause of the earlier gate's 120 s send under `--timeout=60`: 2 × the 60 s
  default. With `MAIL_TIMEOUT=20` the same peer is cut off after 40.1 s (three runs: 40.1, 40.1, 40.1 s).
* A peer that never greets costs one. A peer that answers each command just inside the limit is **not** cut off by it:
  five replies at 19 s took 95.2 s and the message was delivered. The theoretical ceiling for one message is about
  nine reads (greeting, a timed-out `EHLO`, `HELO`, `MAIL`, `RCPT`, `DATA`, end of data, `QUIT`), i.e. about **9 × MAIL_TIMEOUT**
  (180 s at 20 s). Name resolution and the TLS handshake are outside this analysis (resolver timeouts are the operating system's).

`--timeout` on the worker is a second, optional bound and a weaker one: it is a `pcntl` alarm, and PHP handles the signal
only when the blocked socket read returns. With the default `--timeout=60`, a slow peer's send ran for about 96 s before
the worker was killed (exit code 1) with the job still **reserved**, to be re-run only after `retry_after`.
Use `--timeout=240` (below `retry_after`) so the worker's limit never fires before the SMTP bound does.

## 3. Retries

Each of the three notifications carries `#[Tries(3)]` and `#[Backoff(60, 180)]` (the Laravel 13 queued-notification
attributes, read by `SendQueuedNotifications`): three attempts, the second 60 s after the first fails and the third
180 s after the second. These attributes take precedence over a worker's `--tries`. A third failure moves the job to
`failed_jobs` with the transport exception. With a five-minute cron drain, each retry is simply picked up by the next run
after it becomes available, so the real spacing is rounded up to the cron interval.

## 4. Reservation window (`DB_QUEUE_RETRY_AFTER`)

The database queue reserves a job when a worker takes it and makes it available again after `retry_after` seconds. If a job
is still running when that expires, a second worker takes it and the **same mail is sent concurrently**.

| Measured (T = 20 s) | Longest send |
|---|---|
| silent peer after greeting | 40.1 s |
| slow peer, every reply 19 s | 95.2 s |
| theoretical ceiling (9 × T) | 180 s |

* `retry_after=30` (below the 40 s send): a second worker started the same job 34.5 s in, **while the first was still
  inside its send** (overlapping connections). This is the control that shows the setting matters.
* `retry_after=300` with a second worker polling every 2 s throughout three 40 s attempts: the connections never
  overlapped, and the third failure reached `failed_jobs`.

`DB_QUEUE_RETRY_AFTER` now defaults to **300**: 3.1 × the slowest measured send and 1.7 × the theoretical ceiling. It is a
candidate pending owner approval, not a permanent decision. Keep `retry_after > worker --timeout > 9 × MAIL_TIMEOUT`.
Raising `MAIL_TIMEOUT` raises the ceiling in proportion; re-check the inequality when changing either.

## 5. What this does not guarantee

Delivery is **at least once**, not exactly once. SMTP gives the sender no way to learn that a message it has fully
transmitted was accepted if the reply is lost. The tested case: a server took the whole message (logged `accepted`) and
never sent the final `250`; the client timed out after 20.2 s, the job was retried 66 s later, and the server accepted the
**same message again**. The same ambiguity follows any worker death between the server's acceptance and the job's deletion
(killed process, host restart, a `pcntl` timeout, an exhausted reservation). Mitigations are bounded, not eliminating:
the timeout shortens the window, the three attempts cap the repetition, and the recipient-visible content is idempotent
(a repeated invitation carries the same live link; a repeated reply notice names the same ticket). Do not build on the
assumption that a mail is sent once.

Other residual risks: a peer that keeps answering just inside the timeout can hold a send for up to the ceiling above;
`shouldSend()` narrows but cannot close the window between its check and the SMTP acceptance; a hung peer consumes one of
the three attempts, so three consecutive hangs fail the job after about 6 minutes.
