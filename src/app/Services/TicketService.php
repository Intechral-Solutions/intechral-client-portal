<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDueDateChange;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\NewTicketAlertNotification;
use App\Notifications\PartnerReplyAlertNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketStatusNotification;
use App\Rules\AllowedTicketAttachment;
use App\Shared\Pilot\CustomerPilotGate;
use App\Support\Helpdesk\DueDateAdjustment;
use App\Support\Helpdesk\HelpdeskNotifyAddress;
use App\Support\Helpdesk\TicketAttribution;
use App\Support\Helpdesk\TicketReference;
use App\Support\Helpdesk\TicketReferenceExhausted;
use App\Support\Helpdesk\TicketReferenceGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class TicketService
{
    /**
     * The implicit transitions of a CUSTOMER reply (EPIC-017 O2). A reply on `open` or
     * `in_progress` changes nothing; a reply on `closed` is refused (CLOSED_REPLY_MESSAGE).
     */
    public const CUSTOMER_REPLY_TRANSITIONS = [
        'pending_user' => 'in_progress',
        'resolved' => 'open',
    ];

    /** Hours a ticket stays `resolved` before `tickets:auto-close` may close it; the Resolved mail tells the customer the same number. */
    public const AUTO_CLOSE_HOURS = 72;

    /**
     * Attempts at a creation that is the OUTERMOST transaction when InnoDB picks it as a deadlock victim (error 1213; a lock wait timeout,
     * 1205, is never retried). R1-2: the creation transaction now holds shared locks on an organization and a membership row before its
     * INSERT, which lengthens it enough that a forced reference collision can deadlock two inserts. Each attempt starts from nothing: the
     * lost attempt's files are deleted, attribution is decided again from current state and a new reference is drawn. See
     * {@see retryingDeadlock()}. Inside a caller's transaction there is no retry and the error propagates as before.
     */
    public const CREATE_ATTEMPTS = 3;

    /** References drawn per creation: at most this many before it fails closed (C6-R). */
    public const REFERENCE_ATTEMPTS = 5;

    public const CLOSED_REPLY_MESSAGE = 'This request is closed. Raise a new request if you need more help.';

    /** Release 1.0 (R1-4): a partner support reply never reopens a request, so a resolved or closed one is simply refused. */
    public const PARTNER_SUPPORT_CLOSED_MESSAGE = 'This request is resolved or closed. Ask Intechral or the requester to reopen it before replying.';

    /** Release 1.0: the furthest ahead an operator may set a due date, in days from now. */
    public const DUE_OVERRIDE_MAX_DAYS = 365;

    public function __construct(private readonly ServiceRequestAccess $access) {}

    /**
     * The one way a Ticket is created. The authenticated `$user` becomes the requester and the submitter, and the customer organization is
     * decided HERE, inside the creation transaction, by {@see ServiceRequestAccess} from the actor's current membership (a refusal throws
     * before anything is written, so a caller cannot reach the INSERT without it). `$organizationId` is what the actor asked for, or null
     * to let the server choose the only organization they belong to; it is a request, never an authority, and nothing in `$data` can
     * name an organization, a submitter or a requester.
     *
     * @throws AuthorizationException when the account may not raise a request at all
     * @throws ValidationException on `organization_id` when no organization is eligible, or one must be chosen
     */
    public function create(User $user, array $data, array $files = [], ?int $organizationId = null): Ticket
    {
        $ticket = $this->createAttributed(
            $user,
            $data,
            $files,
            fn (): TicketAttribution => $this->access->attributionFor($user, $organizationId),
        );

        // After commit only: a mail must never describe a ticket that rolled back, and a mail that cannot be queued must never make a
        // ticket that DID commit look as if it failed. Each hand-over is its own after-commit callback, so one failing (reported, not thrown)
        // cannot stop the other, whether this runs on its own or inside a caller's transaction.
        $this->handOffAfterCommit(fn () => $user->notify(new TicketCreatedNotification($ticket)));
        $this->handOffAfterCommit(fn () => $this->alertOperators($ticket));

        return $ticket;
    }

    /**
     * Release 1.0 (R1-5): a request raised by partner support staff for a CONTACT at a managed customer organization. Same creation path as
     * {@see create()} (the reference, the attachments, the rollback and deadlock-retry contract, the operator alert), with one difference: the
     * three attribution values are decided by {@see ServiceRequestAccess::attributionForPartnerSupport()} and are independent.
     *
     *  - `tickets.user_id`: `$requesterId`, the contact, only if an eligible customer contact of the organization;
     *  - `tickets.organization_id`: `$organizationId`, only if `$actor` holds effective support authority over it, re-decided under locks;
     *  - `tickets.submitted_by_id`: `$actor`, the authenticated partner staff member.
     *
     * `$organizationId` and `$requesterId` are what the actor asked for, never an authority. A refusal throws before anything is written.
     *
     * Email (R1-6): the REQUESTER receives the receipt a requester always receives, which now says who raised it; the SUBMITTER receives a confirmation
     * of their own; the operator alert goes out and names the submitter. Each is its own after-commit hand-over.
     *
     * @throws ValidationException on `organization_id` or `requester_id`
     * @throws NotFoundHttpException when the actor holds no effective authority
     */
    public function createForCustomerContact(User $actor, int $organizationId, int $requesterId, array $data, array $files = []): Ticket
    {
        $ticket = $this->createAttributed(
            $actor,
            $data,
            $files,
            fn (): TicketAttribution => $this->access->attributionForPartnerSupport($actor, $organizationId, $requesterId),
        );

        $this->handOffAfterCommit(function () use ($ticket) {
            $requester = $ticket->user()->first();

            // The same guard a reply mail applies: never a suspended account.
            if ($requester !== null && ! $requester->isSuspended()) {
                $requester->notify(new TicketCreatedNotification($ticket));
            }
        });
        // R1-6: the staff member who submitted it gets their own confirmation (their own hand-over, so neither can stop the other). A staff member who
        // is also the contact is the requester and has just been sent the receipt; a suspended account, or one whose authority has already ended,
        // is not mailed (TicketCreatedNotification decides again when the worker runs it).
        $this->handOffAfterCommit(function () use ($ticket, $actor) {
            if ((int) $ticket->user_id !== (int) $actor->getKey() && ! $actor->isSuspended() && Gate::forUser($actor)->allows('viewAsPartnerSupport', $ticket)) {
                $actor->notify(new TicketCreatedNotification($ticket, TicketCreatedNotification::FOR_SUBMITTER));
            }
        });
        $this->handOffAfterCommit(fn () => $this->alertOperators($ticket));

        return $ticket;
    }

    /**
     * The creation transaction both entry points share. `$attribute` runs first inside it, before any write, and once (the collision-retry loop
     * can never re-decide it); a refusal rolls the transaction back, so no ticket, reply, attachment row, file, mail or job exists afterwards.
     * `$uploader` is who the attachment rows record.
     *
     * @param  callable(): TicketAttribution  $attribute
     */
    private function createAttributed(User $uploader, array $data, array $files, callable $attribute): Ticket
    {
        $priority = $data['priority'];
        $hours = Ticket::SLA_HOURS[$priority];

        // The row, its reference and every attachment row commit together, and a file is written
        // only inside that transaction; storeAttachments() deletes every written file when it
        // rolls back (F-7). The public reference is random and issued with the row (C6-R, ADR-008);
        // the row's numeric id stays internal, and private files are stored under it.
        $work = function (array &$stored) use ($uploader, $data, $priority, $hours, $files, $attribute) {
            $attribution = $attribute();

            $ticket = $this->createWithReference($attribution, [
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'priority' => $priority,
                'status' => 'open',
                'sla_due_at' => now()->addHours($hours),
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($ticket, null, $uploader, $file, $stored);
            }

            return $ticket;
        };

        return $this->retryingDeadlock(fn () => $this->storeAttachments($work));
    }

    /**
     * A reply, with the O2 lifecycle for a customer author (EPIC-017 §13).
     *
     * "Customer" is a capability, never a route or the shell presentation: an author without
     * `tickets.assign`, or a restricted customer (R0.9-CS1) whatever else they hold. Everything happens under a lock on the ticket row, against its committed
     * status, so a concurrent operator change or auto-close is seen, not overwritten:
     *
     *  - a customer reply on `closed` is refused BEFORE any write: no reply, attachment row, file,
     *    status change, history row or notification;
     *  - a customer reply on `pending_user` / `resolved` moves the ticket to `in_progress` / `open`
     *    through transition(), so the history row names the customer, in the same transaction;
     *  - an operator reply never changes status and is accepted on any status (unchanged);
     *  - Release 1.0: an operator may attach a {@see DueDateAdjustment}. The reply or note, any attachments, the new override and its
     *    history row commit together under the same lock, or none of them does. A customer may not: refused before any write.
     *
     * Files are written inside the transaction and deleted if it rolls back (F-7). Notifications
     * are sent only after commit.
     */
    public function addReply(Ticket $ticket, User $user, string $body, bool $isInternal, array $files = [], ?DueDateAdjustment $dueDate = null): TicketReply
    {
        // R0.9-CS1: a restricted customer replying with a stray `tickets.assign` is still a customer (closed-ticket
        // refusal, status lifecycle), exactly as TicketPolicy treats them.
        $isCustomer = ! $user->can('tickets.assign') || CustomerPilotGate::restricts($user);

        // Release 1.0: the same rule as TicketPolicy::adjustDueDate, held again here so no caller can reach the write without it.
        if ($dueDate !== null && $isCustomer) {
            throw new AuthorizationException('Only an operator may change a request due date.');
        }

        [$reply, $current] = $this->storeAttachments(function (array &$stored) use ($ticket, $user, $body, $isInternal, $files, $isCustomer, $dueDate) {
            $current = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($isCustomer && $current->status === 'closed') {
                throw ValidationException::withMessages(['body' => self::CLOSED_REPLY_MESSAGE]);
            }

            $reply = $current->replies()->create([
                'user_id' => $user->id,
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($current, $reply, $user, $file, $stored);
            }

            if ($dueDate !== null) {
                $this->adjustDueDate($current, $reply, $user, $dueDate);
            }

            $next = $isCustomer ? (self::CUSTOMER_REPLY_TRANSITIONS[$current->status] ?? null) : null;

            if ($next !== null) {
                $this->transition($current, $user, $next);
            }

            return [$reply, $current];
        });

        $ticket->setRawAttributes($current->getAttributes(), true);

        $this->notifyOfReply($current, $reply, $user, $isInternal, false);

        return $reply;
    }

    /**
     * Release 1.0 (R1-4): a PUBLIC reply from a partner support user, which is its own method so that {@see addReply()} (the requester's and the
     * operator's) is untouched. In ONE transaction it locks the ticket row, then RE-DECIDES effective support authority under locks
     * ({@see PartnerSupportAccess::assertLocked()}: a revocation committed before this point is seen, one that has not committed makes the
     * check wait), and only then writes. A refusal is a 404 (the same answer as a ticket that does not exist) and a rollback.
     *
     *  - It is never internal, and carries no due-date adjustment: nothing here accepts either.
     *  - It NEVER changes status. Replying is not a status control, so none of a customer's implicit transitions (`pending_user` to `in_progress`,
     *    `resolved` to `open`) applies, and a resolved or closed request is refused (422 on `body`) rather than reopened.
     *  - Files and their failure behaviour are exactly a public reply's: written inside the transaction and deleted on rollback. The requester and
     *    the assignee are notified by {@see replyRecipients()}, never the author, and Intechral is alerted when no assignee could be ({@see notifyOfReply()}).
     *
     * @param  list<UploadedFile>  $files
     */
    public function addPartnerSupportReply(Ticket $ticket, User $user, string $body, array $files = []): TicketReply
    {
        [$reply, $current] = $this->storeAttachments(function (array &$stored) use ($ticket, $user, $body, $files) {
            $current = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            abort_unless(PartnerSupportAccess::assertLocked((int) $user->getKey(), (int) $current->organization_id), 404);

            if (! $current->isOpen()) {
                throw ValidationException::withMessages(['body' => self::PARTNER_SUPPORT_CLOSED_MESSAGE]);
            }

            $reply = $current->replies()->create([
                'user_id' => $user->id,
                'body' => $body,
                'is_internal' => false,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($current, $reply, $user, $file, $stored);
            }

            return [$reply, $current];
        });

        $ticket->setRawAttributes($current->getAttributes(), true);

        $this->notifyOfReply($current, $reply, $user, false, true);

        return $reply;
    }

    /**
     * Release 1.0: set or clear the operator due date on the LOCKED, committed row, and record who changed what, tied to the reply that
     * explains it. Runs inside addReply's transaction, so a refusal here (a ValidationException) rolls the reply back too.
     *
     * Allowed only while the ticket is open; a new date must be in the future and at most DUE_OVERRIDE_MAX_DAYS ahead; a restore is
     * always allowed when there is something to restore (even though the SLA deadline it returns to may be in the past).
     */
    private function adjustDueDate(Ticket $current, TicketReply $reply, User $actor, DueDateAdjustment $adjustment): void
    {
        if (! $current->isOpen()) {
            throw ValidationException::withMessages(['due_action' => 'The due date of a resolved or closed request cannot be changed.']);
        }

        $previous = $current->due_override_at;
        $new = $adjustment->to;

        if ($new === null) {
            if ($previous === null) {
                throw ValidationException::withMessages(['due_action' => 'There is no adjusted due date to restore; the SLA deadline already applies.']);
            }
        } else {
            $now = now();

            if ($new->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['due_at' => 'The new due date must be in the future.']);
            }

            if ($new->greaterThan(now()->addDays(self::DUE_OVERRIDE_MAX_DAYS))) {
                throw ValidationException::withMessages(['due_at' => 'The new due date can be at most '.self::DUE_OVERRIDE_MAX_DAYS.' days ahead.']);
            }

            if ($previous !== null && $previous->equalTo($new)) {
                throw ValidationException::withMessages(['due_at' => 'That is already the due date.']);
            }
        }

        $current->due_override_at = $new;
        $current->save();

        TicketDueDateChange::create([
            'ticket_id' => $current->id,
            'reply_id' => $reply->id,
            'user_id' => $actor->id,
            'previous_override_at' => $previous,
            'new_override_at' => $new,
            'created_at' => now(),
        ]);
    }

    /**
     * Move a ticket to a new status, validated against its COMMITTED status read under a row lock,
     * so a stale caller (a page loaded before a customer reopened the ticket) can neither skip the
     * transition map nor record a history row whose old status never existed. The caller's model
     * is refreshed with the result.
     */
    public function transition(Ticket $ticket, User $actor, string $newStatus): void
    {
        $historyId = null;

        DB::transaction(function () use ($ticket, $actor, $newStatus, &$historyId) {
            $current = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if (! $current->canTransitionTo($newStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot transition from \"{$current->status}\" to \"{$newStatus}\".",
                ]);
            }

            $old = $current->status;

            $current->status = $newStatus;

            if ($newStatus === 'resolved' && $current->resolved_at === null) {
                $current->resolved_at = now();
            }
            if ($newStatus === 'closed' && $current->closed_at === null) {
                $current->closed_at = now();
            }
            // Re-open clears resolved/closed timestamps
            if ($newStatus === 'open') {
                $current->resolved_at = null;
                $current->closed_at = null;
            }

            $current->save();

            $historyId = TicketStatusHistory::create([
                'ticket_id' => $current->id,
                'user_id' => $actor->id,
                'old_status' => $old,
                'new_status' => $newStatus,
                'created_at' => now(),
            ])->getKey();

            $ticket->setRawAttributes($current->getAttributes(), true);
        });

        // EPIC-017 O3: "Waiting on you" and "Resolved" tell the requester. Handed over only once the OUTERMOST transaction commits:
        // transition() also runs inside the bulk action's batch transaction, which rolls back as one, and a rolled-back change must
        // announce nothing. A customer's own reply (to in_progress or open) and auto-close (which does not come through here) notify no one.
        if ($historyId !== null && in_array($newStatus, TicketStatusNotification::STATUSES, true)) {
            $this->handOffAfterCommit(fn () => $this->notifyRequesterOfStatus($ticket, $actor, $newStatus, (int) $historyId));
        }
    }

    public function assign(Ticket $ticket, ?int $assigneeId, User $actor): void
    {
        $this->assertAssignable($assigneeId);

        $ticket->update(['assignee_id' => $assigneeId]);

        activity()->causedBy($actor)
            ->performedOn($ticket)
            ->withProperties(['assignee_id' => $assigneeId])
            ->log('assigned ticket');
    }

    /**
     * Close tickets resolved at least $idleHours ago. Each close is a compare-and-set: it applies
     * only if the ticket is STILL resolved past the cutoff when it is written, and writes its
     * history row only when it did. A customer reply that reopened the ticket after the candidate
     * list was read (EPIC-017 O2) therefore wins, and no false "resolved → closed" row appears.
     * Returns the number of tickets actually closed.
     */
    public function autoCloseResolved(int $idleHours = self::AUTO_CLOSE_HOURS): int
    {
        $cutoff = now()->subHours($idleHours);
        $candidates = Ticket::where('status', 'resolved')
            ->where('resolved_at', '<=', $cutoff)
            ->pluck('id');

        $closed = 0;

        foreach ($candidates as $id) {
            $closed += DB::transaction(function () use ($id, $cutoff): int {
                $now = now();
                $affected = Ticket::whereKey($id)
                    ->where('status', 'resolved')
                    ->where('resolved_at', '<=', $cutoff)
                    ->update(['status' => 'closed', 'closed_at' => $now]);

                if ($affected !== 1) {
                    return 0;
                }

                TicketStatusHistory::create([
                    'ticket_id' => $id,
                    'user_id' => null,
                    'old_status' => 'resolved',
                    'new_status' => 'closed',
                    'created_at' => $now,
                ]);

                return 1;
            });
        }

        return $closed;
    }

    /**
     * The one assignment-eligibility rule (EPIC-010D H5): a Ticket may be assigned only to a user
     * who currently holds `tickets.assign`, is an operator ({@see isAssignable()}, R1-1) and whose account is active (Release 0.9, H3). Unassigning
     * (null) is always allowed. There is no exemption for the current assignee, so a stale assignee
     * who lost the capability, or was suspended, cannot be re-selected. Bulk callers run this before
     * touching any Ticket.
     *
     * @throws ValidationException
     */
    public function assertAssignable(?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }

        $assignee = User::find($assigneeId);

        if ($assignee === null || ! self::isAssignable($assignee)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee cannot be assigned requests.',
            ]);
        }
    }

    /**
     * Whether a user may be a ticket assignee: active, holding `tickets.assign`, AND operator-eligible (`portal.operator`,
     * {@see CustomerPilotGate::isOperator()}). The capability alone is not enough (R1-1): a customer who was granted
     * `tickets.assign` is still a customer, and TicketPolicy already refuses them the tickets, so assigning one would only
     * create a ticket nobody who owns it can work. Every assignee list and {@see assertAssignable()} use this one rule.
     */
    public static function isAssignable(User $user): bool
    {
        return ! $user->isSuspended()
            && $user->can('tickets.assign')
            && CustomerPilotGate::isOperator($user);
    }

    // ── Private helpers ──────────────────────────────────────

    /**
     * Hand a notification to the dispatcher once the outermost database transaction has committed: at once when none is open, and never
     * when it rolls back (Laravel's `DB::afterCommit`, which the test framework also honours). It is what lets a service method that may be
     * called inside a caller's transaction (the bulk action) still send mail "only after commit".
     *
     * A hand-over that fails (the `jobs` insert, a policy lookup) is REPORTED and swallowed here, and nowhere else. By then the change is
     * committed, so an exception thrown from this callback would surface from the commit, make the request answer 500 for something that
     * succeeded, and stop every later after-commit callback (the rest of a bulk update). The queue worker's own exceptions are a different
     * path and are not touched: `shouldSend()` still propagates and the attempt is retried.
     */
    private function handOffAfterCommit(callable $handOff): void
    {
        DB::afterCommit(function () use ($handOff): void {
            try {
                $handOff();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * The new-ticket operator alert (EPIC-017 O3). A destination that is unset or unusable is logged and the request goes on. Production
     * cannot reach that: RuntimeProfile refuses it, and the cron workers refuse to start. The address is never written to the log.
     */
    private function alertOperators(Ticket $ticket): void
    {
        $this->alertHelpdesk($ticket, 'The new-ticket operator alert', new NewTicketAlertNotification($ticket));
    }

    /** R1-6: the same destination and the same failure behaviour, for a partner support reply nobody at Intechral was told about. */
    private function alertOperatorsOfPartnerReply(Ticket $ticket, TicketReply $reply): void
    {
        $this->alertHelpdesk($ticket, 'The partner support reply alert', new PartnerReplyAlertNotification($ticket, $reply));
    }

    private function alertHelpdesk(Ticket $ticket, string $what, BaseNotification $notification): void
    {
        $address = HelpdeskNotifyAddress::usable();

        if ($address === null) {
            Log::log(
                app()->environment('production') ? 'error' : 'warning',
                "{$what} was skipped: HELPDESK_NOTIFY_ADDRESS is not set to a usable address.",
                ['ticket_id' => $ticket->getKey()],
            );

            return;
        }

        Notification::route('mail', $address)->notify($notification);
    }

    /**
     * The requester hears about "Waiting on you" and "Resolved" (EPIC-017 O3): not when they caused the change themselves, not when
     * the account is suspended, and only while they may still view the ticket. TicketStatusNotification re-decides all of it, and
     * whether a newer change has overtaken this one, when the worker runs it.
     */
    private function notifyRequesterOfStatus(Ticket $ticket, User $actor, string $status, int $historyId): void
    {
        $requester = $ticket->user()->first();

        if ($requester === null || $requester->is($actor) || $requester->isSuspended() || Gate::forUser($requester)->denies('view', $ticket)) {
            return;
        }

        $requester->notify(new TicketStatusNotification($ticket, $status, $historyId));
    }

    /**
     * Runs a creation again, from nothing, when the database chose it as a deadlock victim (InnoDB error 1213), and ONLY then: a lock wait
     * TIMEOUT (1205) means the lock holder is slow, not that this transaction is the cheaper half of a cycle, and retrying it would only
     * wait again, so it propagates like any other error.
     *
     * Only when it was the OUTERMOST transaction. The victim's transaction is already rolled back and storeAttachments() has already deleted
     * the files it wrote (the one rollback path), so the next attempt writes its own, decides the attribution again from current state and
     * issues a new reference. Inside a caller's transaction the level is not 0, a retry would act on a transaction that no longer holds what
     * it thinks, and the error propagates as before.
     *
     * Classification reads the exception CHAIN, because Laravel changes the type on the way out: an error inside a nested transaction (the
     * savepoint around the reference INSERT) is rethrown as `Illuminate\Database\DeadlockException`, a PDOException whose code is 0 for a
     * string SQLSTATE and whose `previous` is the driver's QueryException, and Laravel treats a 1205 timeout the same way, so neither the
     * wrapper's class nor its message can tell the two apart. The driver's error number can: `errorInfo[1]` of a PDOException in the chain.
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    private function retryingDeadlock(callable $attempt): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $attempt();
            } catch (Throwable $e) {
                if (! self::isInnoDbDeadlock($e) || DB::transactionLevel() > 0 || $try >= self::CREATE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** InnoDB error 1213 ("Deadlock found when trying to get lock") anywhere in the exception chain; never a 1205 lock wait timeout. */
    private static function isInnoDbDeadlock(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && (int) ($cause->errorInfo[1] ?? 0) === 1213) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inserts the ticket under a fresh random reference. Each attempt is its own savepoint inside the caller's transaction, and ONLY
     * a duplicate of the `ticket_number` unique index draws again; any other error propagates after one attempt. When every attempt
     * collides it throws, and the caller's transaction rolls back whole (no row, file, mail or job).
     *
     * Private (R1-2): the only caller is {@see create()}, so the only way a Ticket row is written by the application is behind
     * {@see ServiceRequestAccess}. The attribution is assigned explicitly: those two columns are not mass-assignable.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithReference(TicketAttribution $attribution, array $attributes): Ticket
    {
        $generator = app(TicketReferenceGenerator::class);

        for ($attempt = 1; $attempt <= self::REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attribution, $attributes, $generator) {
                    $ticket = new Ticket([...$attributes, 'ticket_number' => $generator->next()]);
                    $ticket->user_id = $attribution->requesterId;
                    $ticket->organization_id = $attribution->organizationId;
                    $ticket->submitted_by_id = $attribution->submittedById;
                    $ticket->save();

                    return $ticket;
                });
            } catch (QueryException $e) {
                if (! TicketReference::isCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new TicketReferenceExhausted('No unique ticket reference could be issued in '.self::REFERENCE_ATTEMPTS.' attempts.');
    }

    /**
     * Everything a reply announces, once it has committed. Nothing for an internal note.
     *
     *  1. The requester and the assignee ({@see replyRecipients()}), exactly as before.
     *  2. R1-6: when the author is NOT partner support, the partner support PARTICIPANTS ({@see partnerParticipants()}), as the separate
     *     partner audience of the reply mail, which links to the support page and is re-authorised when the worker runs it.
     *  3. R1-6: when the author IS partner support and no assignee is among the recipients (none assigned, or the assignee is suspended or may no
     *     longer view the request), one alert to the helpdesk address, so Intechral is not left unaware of a reply. An assignee who IS mailed
     *     makes the alert redundant, so there is never both for one reply.
     *
     * The R1-6 hand-overs are queued first and each is its own after-commit hand-over (a failure is reported, never thrown, and cannot stop another).
     * The pre-existing requester/assignee dispatch is untouched, including how it fails.
     */
    private function notifyOfReply(Ticket $ticket, TicketReply $reply, User $author, bool $isInternal, bool $byPartnerSupport): void
    {
        if ($isInternal) {
            return;
        }

        $recipients = $this->replyRecipients($ticket, $author, false);

        if ($byPartnerSupport) {
            if (! $recipients->contains(fn (User $recipient) => $ticket->assignee_id !== null && (int) $recipient->getKey() === (int) $ticket->assignee_id)) {
                $this->handOffAfterCommit(fn () => $this->alertOperatorsOfPartnerReply($ticket, $reply));
            }
        } else {
            $this->handOffAfterCommit(function () use ($ticket, $reply, $author, $recipients) {
                foreach ($this->partnerParticipants($ticket, $author, $recipients) as $participant) {
                    $participant->notify(new TicketRepliedNotification($ticket, $reply, TicketRepliedNotification::FOR_PARTNER_SUPPORT));
                }
            });
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new TicketRepliedNotification($ticket, $reply));
        }
    }

    /**
     * Release 1.0 (R1-6): the partner support staff who take part in this request: the one who SUBMITTED it, and any who has posted a PUBLIC reply on it
     * (so a self-raised request a partner has helped with still tells them the answer). Candidates only; the decision is the partner support
     * rule itself ({@see PartnerSupportAccess::allows()}, read from the tables right now), which is also what the worker asks again: a staff member
     * whose designation was revoked, who was suspended, or whose partner no longer manages this customer is simply not a participant any more, and
     * an operator, the requester and a member of the customer organization can never pass it.
     *
     * Never the author, never the requester or a recipient already mailed (so nobody is told twice), never a suspended account. Replies by other
     * partner staff do not notify them in turn (R1-6 covers the requester's and an operator's replies).
     *
     * @param  Collection<int, User>  $alreadyMailed
     * @return Collection<int, User>
     */
    private function partnerParticipants(Ticket $ticket, User $author, Collection $alreadyMailed): Collection
    {
        $candidateIds = TicketReply::query()->where('ticket_id', $ticket->getKey())->where('is_internal', false)->pluck('user_id')
            ->push($ticket->submitted_by_id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === (int) $author->getKey() || $id === (int) $ticket->user_id || $alreadyMailed->contains(fn (User $u) => (int) $u->getKey() === $id))
            ->values();

        return User::query()->whereIn('id', $candidateIds)->orderBy('id')->get()
            ->reject(fn (User $candidate) => $candidate->isSuspended())
            ->filter(fn (User $candidate) => PartnerSupportAccess::allows($candidate, $ticket))
            ->values();
    }

    /**
     * Who is emailed about a reply: nobody for an internal note; otherwise the owner and the
     * assignee, except the author and any suspended account (Release 0.9, H3), only users who can
     * currently view the Ticket (a stale assignee who lost `tickets.assign` cannot, so receives no
     * preview), and each user at most once.
     *
     * @return Collection<int, User>
     */
    private function replyRecipients(Ticket $ticket, User $author, bool $isInternal)
    {
        if ($isInternal) {
            return collect();
        }

        return collect([$ticket->user, $ticket->assignee])
            ->filter()
            ->reject(fn (User $recipient) => $recipient->is($author))
            ->unique('id')
            ->reject(fn (User $recipient) => $recipient->isSuspended())
            ->filter(fn (User $recipient) => Gate::forUser($recipient)->allows('view', $ticket))
            ->values();
    }

    /**
     * Run $work in a transaction, giving it a list in which storeAttachment() records every file
     * it writes. The database rolls back on any exception, but the filesystem does not, so the
     * recorded files are deleted before the exception continues (EPIC-010D F-7): no file outlives
     * a failed create or reply, and since a row is created only after its file was written, no
     * row is left without its file. Only a process killed mid-request can still strand a file.
     *
     * @template T
     *
     * @param  callable(array<int, string>&): T  $work
     * @return T
     */
    private function storeAttachments(callable $work): mixed
    {
        $stored = [];

        try {
            return DB::transaction(function () use ($work, &$stored) {
                return $work($stored);
            });
        } catch (Throwable $e) {
            if ($stored !== []) {
                Storage::disk('local')->delete($stored);
            }

            throw $e;
        }
    }

    /** @param  array<int, string>  $stored  every path written so far, for storeAttachments() */
    private function storeAttachment(Ticket $ticket, ?TicketReply $reply, User $user, UploadedFile $file, array &$stored): TicketAttachment
    {
        $path = $file->store("tickets/{$ticket->id}", 'local');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("The attachment {$file->getClientOriginalName()} could not be stored.");
        }

        $stored[] = $path;

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'reply_id' => $reply?->id,
            'user_id' => $user->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            // The type detected from the bytes (the one the attachment rule checked), not the
            // browser's claim.
            'mime_type' => AllowedTicketAttachment::detectedType($file) ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);
    }
}
