<?php

namespace App\Notifications;

use App\Services\OrganizationAuthority;
use App\Services\OrganizationInvitations;
use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The mail that carries an organization invitation's token (Release 1.0, R1-3A; design record section 5).
 *
 *  - ENCRYPTED. The stored invitation holds only the token's hash, so the plaintext must travel with the job: it is a scalar constructor
 *    argument (never the model), and the job payload, in `jobs` and, after three failures, in `failed_jobs`, is encrypted with APP_KEY
 *    ({@see ShouldBeEncrypted}). A payload encrypted under a key the application can no longer decrypt with is undeliverable: a rotation that
 *    keeps the old key in APP_PREVIOUS_KEYS leaves in-flight and failed mail readable, one that drops it does not (reissue).
 *  - AFTER COMMIT. {@see ShouldQueueAfterCommit}: the job is enqueued only once the surrounding transaction has committed, and never at all
 *    if it rolls back. `queue.after_commit` is false on every connection in this application, so the interface, not the connection setting,
 *    is the guarantee; the service also dispatches after its own transaction has returned.
 *  - SEND-TIME ELIGIBILITY. The mail is queued, so by the time the worker runs it the invitation may have been superseded, revoked, accepted
 *    or expired, its address may now belong to an account, or its issuer may have lost authority. {@see shouldSend()} re-reads every fact from
 *    the tables and mails the link only while it is still the live one. Ineligible (including "no such row") returns false and the mail is
 *    dropped, not failed. A database error is NOT caught: it propagates, the attempt fails and the queue retries, so a transient outage never
 *    silently swallows a valid invitation.
 *
 * Three attempts, 60 s then 180 s apart. The mail names the organization and the inviter, both user-authored (MailText::escape).
 */
#[Tries(3)]
#[Backoff(60, 180)]
final class OrganizationInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly int $invitationId, private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $row = $this->row();

        if ($row === null) {
            return false;
        }

        return $row->accepted_at === null
            && $row->revoked_at === null
            && Carbon::parse($row->expires_at)->isFuture()
            && hash_equals((string) $row->token_hash, OrganizationInvitations::hashToken($this->token))
            && $row->organization_name !== null
            && ! DB::table('users')->where('email', $row->email)->exists()
            && OrganizationAuthority::isMembershipManager((int) $row->invited_by);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $row = $this->row();
        $url = route('invitation.show', ['token' => $this->token]);
        $app = config('app.name');
        $organization = MailText::escape($row?->organization_name ?? $app);
        $inviter = MailText::escape($row?->inviter_name ?? $app);
        $days = OrganizationInvitations::LIFETIME_DAYS;

        return (new MailMessage)
            ->subject("You've been invited to join an organization on {$app}")
            ->greeting('Hello!')
            ->line(MailText::line($inviter, ' has invited you to join ', $organization, ' on '.$app.'.'))
            ->action('Accept Invitation', $url)
            ->line("This invitation expires in {$days} days. If you did not expect this invitation, you may ignore this email.");
    }

    /** The persisted invitation with the organization's and the inviter's names, read fresh from the tables (no scope, no cache). */
    private function row(): ?object
    {
        return DB::table('organization_invitations as i')
            ->leftJoin('organizations as o', 'o.id', '=', 'i.organization_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.invited_by')
            ->where('i.id', $this->invitationId)
            ->first(['i.*', 'o.name as organization_name', 'u.name as inviter_name']);
    }
}
