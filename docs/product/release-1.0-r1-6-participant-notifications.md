# Release 1.0: R1-6 Service request participant notifications (design record)

**Status:** implemented on `feature/r1-6-participant-notifications` from `main` at `316cb84` (R1-5, PR #64). Not committed until the hand-back is accepted, not merged, not deployed; Release 0.9 staging is untouched.
Scope (owner package, 2026-11-12): close the communication gaps R1-4 and R1-5 recorded: a partner staff member who raises a request is told, the requester and operator mails name the submitter, a requester's or an operator's public reply reaches the partner participants,
and a partner reply that nobody at Intechral would hear about alerts the helpdesk. Existing Laravel notifications and the database queue only; **no** preferences, in-app centre or new messaging subsystem. No schema change, no new route, no front-end change.

## 1. What is sent, to whom

| Event | Recipient | Mail | Change |
|---|---|---|---|
| Request created by the requester | requester; helpdesk address | receipt; "New request" alert | **none** (byte-for-byte as before) |
| Request raised by partner support (`submitted_by_id` ≠ `user_id`) | the **requester** | the receipt, now with one line: "**Pat** raised this request for you at **Charter**" | wording; re-authorised at send time (see §3) |
| same | the **submitter** | new: "Request raised", names the contact and organization, links to the **support** page | new |
| same | helpdesk address | the alert reads "was submitted by **Pat** (partner support) on behalf of **Rae**" | wording |
| Public reply by the requester or an operator | requester / assignee | ordinary reply mail | unchanged (author excluded, as before) |
| same | **partner support participants** | the reply mail in a second audience: names requester and organization, links to the **support** page | new |
| Public reply by partner support | requester, assignee | ordinary reply mail | unchanged |
| same, **no assignee was mailed** | helpdesk address | new `PartnerReplyAlertNotification` | new |
| Internal note | nobody | | unchanged |

A **partner support participant** is a candidate who (a) submitted the request, or (b) has posted a **public** reply on it, **and** passes the one partner support rule ([`PartnerSupportAccess::allows`](../../src/app/Services/PartnerSupportAccess.php), R1-4 §1, read from the tables) **now**. The rule is the whole eligibility test; nothing else is consulted, and an operator, the requester, a member of the customer organization,
an ungranted or foreign-partner member can never pass it, whatever they once wrote. The author, the requester and anyone already mailed for the same reply are removed first, so nobody is told twice.
Replies *by* partner staff do not notify their colleagues (the package covers the requester's and an operator's replies); this is a one-line change in `TicketService::notifyOfReply` if the owner wants it.

The helpdesk alert for a partner reply is sent when the reply's recipient set holds no assignee: nobody assigned, **or** the assignee is suspended or may no longer view the request. When an assignee is mailed there is no alert, so Intechral hears once.
A requester's or operator's reply on an unassigned request alerts nobody (unchanged; out of scope).

## 2. Extensions, not abstractions

- `TicketCreatedNotification` gains an `audience` (`requester` default, `submitter`); `TicketRepliedNotification` gains one (`participant` default, `partner_support`). Defaults keep every existing call site as it was, and a job serialized before this release (no `audience` in its payload) falls back to the default audience when the worker runs it. Both classes keep their queue, retry (3 tries, 60 s/180 s) and missing-model attributes.
- `PartnerReplyAlertNotification` mirrors `NewTicketAlertNotification` (on-demand to `HELPDESK_NOTIFY_ADDRESS`, escaped text, a 200-character preview, operator link); the alert helper in `TicketService` is shared and keeps the log wording for an unusable address.
- `TicketService::notifyOfReply` is the one place a public reply is announced; `partnerParticipants` is the one place the partner audience is decided. Each R1-6 hand-over is its own `handOffAfterCommit` (a failed hand-over is reported and swallowed, never fails the committed reply, never stops another). The pre-existing requester/assignee dispatch is untouched, including how it fails.

## 3. Eligibility, at both boundaries

1. **Dispatch** (after commit): suspended accounts and anyone failing `PartnerSupportAccess::allows` are never queued. Proven with the real queue by counting `jobs` rows before any worker runs.
2. **Send** (inside the worker, `shouldSend`): the partner audience asks `viewAsPartnerSupport` again, so a designation revoked, a suspension, an operator promotion, a relationship moved, a disabled customer or partner, or a removed membership **between queueing and sending sends nothing**. A reply that became internal, was deleted, or sits on another ticket is dropped; a ticket or reply that no longer exists is discarded rather than failed. A database error is not caught: the attempt fails and retries.
3. The submitter confirmation re-checks the same rule and that the recipient **is** the submitter. The requester receipt of a **partner-raised** request re-checks that the recipient is the requester and may view the ticket (a receipt for a request *someone else wrote* is a disclosure of that text); an **ordinary** receipt is not re-decided (it echoes only what the requester typed), which preserves existing behaviour.
4. Every reply mail, either audience, refuses the reply's own author at send time as well.
5. `SkipSuspendedRecipients` still applies to every account notification.

## 4. Confidentiality

The partner mail carries what a support user can already read at `support.show`: the **public** reply text (200-character preview), the author's name, the requester's name and the customer organization's name. No attachment name, no internal note or internal attachment, no time, SLA, assignee or any address, no id. User-authored text goes through `MailText` like every other mail.
The link is the **support** route (which the recipient may open), never the requester's. The requester now learns the **submitter's name** and the **customer organization's name** from the receipt (the owner approved the contact disclosure in the other direction in R1-5); the operator alert names the submitter, as the operator page already does.

## 5. Evidence

`tests/Feature/PartnerSupport/PartnerParticipantNotificationsTest.php`: 64 tests covering recipients, suppressed recipients, duplicate prevention, author exclusion, revocation matrix at dispatch **and** in the queue (real database queue and `queue:work`), internal-note and attachment boundaries, content and escaping, failure isolation and the unassigned alert (assigned, unassigned, assignee suspended or without the capability, unusable address).
Mutation probes (each restored): dropping the dispatch authority filter, the send-time partner check, the assignee de-duplication of the alert, the submitter authority check and the internal-note guard each fail at least one test.

## 6. Residual risks and decisions

- A participant is decided from `submitted_by_id` and public reply authorship. Someone who replied once keeps receiving later requester/operator replies while they remain effective support; there is no unsubscribe (no preferences by scope).
- The alert decision uses the assignee set at dispatch. An assignee made unreachable inside the queue window is not re-alerted (the assignee's own mail is dropped by its own send-time check).
- Status mails (`pending_user`, `resolved`) still go to the requester only; partner participants are not told of status changes (not in the package).
- Partner-to-partner replies are not announced (§1).
- **Review (Opus, 2026-11-12): SAFE TO COMMIT**, no blocking finding. Taken: the pre-deploy queued-job fallback (finding 1), the alert accepting only an address notifiable (3), a test title (7). Accepted and recorded:
  - *No reply throttle (2).* Reply routes carry no rate limit (as before). R1-6 adds recipients per reply (partner participants, the helpdesk alert), so a scripted burst could use SMTP relay allowance. Recipients only ever receive content they are entitled to. **Owner ruling: required before RC1, deferred to the security-hardening work, not changed in R1-6.** Recorded as gate 6 in the [release notes](./release-1.0-notes.md#gates-before-release-10-is-deployed-to-a-customer-facing-environment): a shared per-account limiter across the applicable reply routes, sized for normal operator activity.
  - *Requester receipt re-check is weak (4).* `view` for a requester is ownership, so a requester removed from the customer organization in the queue window still gets a partner-raised receipt (title, submitter's name, organization name). The owner approved this disclosure; suspension is still enforced.
  - *Customer surface mode (5).* Participant eligibility ignores `CustomerPilotGate`, as requester and assignee mail already do; in `closed` mode a partner participant is still mailed although `support.*` answers 404.
  - *`raisedByAnother` assumes partner support (6).* Today only partner-originated creation can make submitter and requester differ; a future "operator raises on behalf of" path must revisit the "(partner support)" wording of the alert and the receipt line.

## 7. Owner rulings (2026-11-12)

The owner accepted the implementation and the Opus verdict (SAFE TO COMMIT) and ruled:

1. **Partner-to-partner notifications:** current behaviour retained; participants are told of requester and operator public replies, not of other partner participants' replies.
2. **Reply throttling:** required before RC1, deferred to the security-hardening work (release notes, gate 6). R1-6 is not modified for it.
3. **One-time repliers:** a partner staff member who posts a public reply stays a participant for later requester and operator replies, subject to the current-authorization and send-time checks. No unsubscribe or preference system for 1.0.

The accepted Opus findings remain documented and non-blocking.
