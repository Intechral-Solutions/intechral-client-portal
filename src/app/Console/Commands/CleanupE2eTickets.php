<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Removes the tickets a browser (E2E) run created, and their private attachment files (EPIC-017 §25.3).
 *
 * Tickets have no delete route, so without this every run would leave its tickets behind. The command is
 * deliberately not a general ticket-deletion tool:
 *
 *  - it runs only in `local` and `testing`: BOTH the runtime environment and the configured `app.env` must be
 *    one of them, checked before it reads or changes anything. The global `--env` option changes only the
 *    runtime value, so it cannot authorise a run by itself;
 *  - it takes no argument and no option that selects tickets, users or patterns;
 *  - a ticket is eligible only when its title starts with the literal `E2E-` AND its requester is one of the
 *    seeded E2E customers below (DevSeeder). The seeded fixtures `TKT-E2E1` and `TKT-E2E2` are titled
 *    `E2E fixture: …` and are never eligible, whoever owns them;
 *  - a ticket with any linked time entry or task is REFUSED, and so is the whole run, before anything is
 *    deleted: `time_entries.ticket_id` and `tasks.ticket_id` are `nullOnDelete`, so deleting the ticket would
 *    silently detach that record. Nothing is detached, nulled or deleted on their behalf.
 *
 * Each ticket is removed in ONE transaction that starts by locking its row (`SELECT … FOR UPDATE`). A time
 * entry or task cannot be given that ticket while the lock is held (InnoDB takes a shared lock on the parent
 * row for the foreign key and waits), so the association check made under the lock stays true until the row is
 * gone; afterwards the foreign key rejects the attempt instead of the delete nulling it. Under the lock it
 * re-checks the title and owner, reads the linked rows with locking reads, proves the state of the ticket's
 * attachment directory, removes it, and deletes the row. `TicketService::addReply` also locks the ticket row
 * before it writes a file, so an upload cannot interleave either.
 *
 * Directory state is PROVEN, never inferred from a failed lookup: the storage root, `tickets/` and
 * `tickets/{id}` are each listed. A directory is absent only when its parent listed fine and does not contain
 * it. If any of those listings fails (typically a `0700` directory owned by the web-server user, seen by
 * another user), the state is unknown and the ticket is kept; nothing is deleted on a guess.
 *
 * Files go before the row, so a file that cannot be removed leaves the row (a later run retries it). The
 * filesystem is not transactional: a row delete that fails after the files went leaves a ticket with no
 * files, which a later run deletes (the directory is then provably absent). Replies, attachment rows and
 * status histories go with the row through their existing `cascadeOnDelete` keys. It is idempotent: a second
 * run finds nothing and succeeds.
 *
 * It must run once, after a whole browser suite, never from a test or a worker: every worker's tickets share
 * the `E2E-` prefix, so a run during the suite would delete tickets other workers are still using
 * (`./dev test:e2e` runs it as the web-server user once the workers have finished; a suite started by hand
 * gets it from `playwright.config.ts` globalTeardown).
 */
#[Signature('e2e:cleanup-tickets')]
#[Description('Delete the tickets an E2E run created (title E2E-…, owned by a seeded E2E customer) and their private files')]
class CleanupE2eTickets extends Command
{
    /** The same allowlist as DatabaseSeeder's call to DevSeeder, which seeds the identities below. */
    private const ENVIRONMENTS = ['local', 'testing'];

    /** The literal, case-sensitive title prefix every browser-created ticket carries. */
    public const TITLE_PREFIX = 'E2E-';

    /** The seeded E2E customers (DevSeeder). Only their tickets are ever eligible. */
    public const OWNER_EMAILS = [
        'e2e-customer@intechral.test',
        'e2e-customer-other@intechral.test',
    ];

    private const DISK = 'local';

    /** An attachment directory that is provably there / provably not there / cannot be established. */
    private const PRESENT = 'present';

    private const ABSENT = 'absent';

    /** The storage root lists fine but has no `tickets/` at all: absent, yet not the shape a used app has. */
    private const NO_TICKETS_ROOT = 'no-tickets-root';

    private const UNKNOWN = 'unknown';

    public function handle(): int
    {
        $refusedEnvironment = $this->disallowedEnvironment();

        if ($refusedEnvironment !== null) {
            $this->error(sprintf(
                'Refusing to run in the "%s" environment: this command only runs in %s, and both the runtime and the configured environment must be one of them.',
                $refusedEnvironment,
                implode(' and ', self::ENVIRONMENTS),
            ));

            return self::FAILURE;
        }

        $candidates = $this->eligibleTickets();

        $this->line(sprintf('Eligible E2E tickets: %d', count($candidates)));

        if ($candidates === []) {
            $this->info('Nothing to remove.');

            return self::SUCCESS;
        }

        $blocked = $this->blockedTickets(array_keys($candidates));

        if ($blocked !== []) {
            $this->error('Refusing to delete any ticket: these E2E tickets have linked records that deleting them would detach.');

            foreach ($blocked as $id => $links) {
                $this->line(sprintf(
                    '  %s: %d time entr%s, %d task%s',
                    $candidates[$id],
                    $links['time_entries'],
                    $links['time_entries'] === 1 ? 'y' : 'ies',
                    $links['tasks'],
                    $links['tasks'] === 1 ? '' : 's',
                ));
            }

            $this->line('Nothing was deleted. Remove or re-point those records (they belong to a person, not to this command), then run it again.');

            return self::FAILURE;
        }

        // A permission mismatch is systemic (every ticket's directory is equally invisible), so it refuses the
        // whole run up front rather than half-finishing it. The per-ticket check under the lock is still the
        // authoritative one.
        $unreadable = [];

        foreach ($candidates as $id => $number) {
            $state = $this->attachmentDirectory($id);

            if ($state['state'] === self::UNKNOWN) {
                $unreadable[$number] = $state['reason'];
            }
        }

        if ($unreadable !== []) {
            $this->error('Refusing to delete any ticket: the state of these tickets\' attachment directories cannot be established by this process.');

            foreach ($unreadable as $number => $reason) {
                $this->line(sprintf('  %s: %s', $number, $reason));
            }

            $this->line('Nothing was deleted. Run it as the web-server user that owns the private files (./dev test:e2e does), or fix the storage permissions; they are not relaxed here.');

            return self::FAILURE;
        }

        $deleted = 0;
        $files = 0;
        $directories = 0;
        $failures = [];

        foreach ($candidates as $id => $number) {
            try {
                $removed = $this->removeTicket($id);
            } catch (Throwable $e) {
                $failures[] = sprintf('%s: %s', $number, $this->scrub($e->getMessage()));

                continue;
            }

            $deleted += $removed['deleted'] ? 1 : 0;
            $files += $removed['files'];
            $directories += $removed['directory'] ? 1 : 0;
        }

        $this->line(sprintf('Tickets deleted: %d', $deleted));
        $this->line(sprintf('Attachment files removed: %d (in %d director%s)', $files, $directories, $directories === 1 ? 'y' : 'ies'));

        if ($failures !== []) {
            $this->error(sprintf('%d ticket(s) were NOT deleted and stay eligible for the next run:', count($failures)));

            foreach ($failures as $failure) {
                $this->line('  '.$failure);
            }

            return self::FAILURE;
        }

        $this->info('E2E ticket cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * The first environment value that is not permitted, or null when both are. The runtime value is what
     * `app()->environment()` reports, which the global `--env` option overrides; `app.env` is the configured
     * value (APP_ENV, as loaded), which it does not. A command-line selector alone therefore never authorises
     * a run.
     */
    private function disallowedEnvironment(): ?string
    {
        $runtime = (string) $this->laravel->environment();
        $configured = (string) config('app.env');

        foreach ([$runtime, $configured] as $environment) {
            if (! in_array($environment, self::ENVIRONMENTS, true)) {
                return $environment;
            }
        }

        return null;
    }

    /** @return array<int, int> */
    private function ownerIds(): array
    {
        return User::query()->whereIn('email', self::OWNER_EMAILS)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Eligible tickets as [id => ticket number]. The SQL prefix match is case-insensitive under the default
     * collation, so the literal prefix is checked again here.
     *
     * @return array<int, string>
     */
    private function eligibleTickets(): array
    {
        $owners = $this->ownerIds();

        if ($owners === []) {
            return [];
        }

        $eligible = [];

        Ticket::query()
            ->whereIn('user_id', $owners)
            ->where('title', 'like', self::TITLE_PREFIX.'%')
            ->orderBy('id')
            ->select(['id', 'ticket_number', 'title'])
            ->each(function (Ticket $ticket) use (&$eligible): void {
                if (str_starts_with($ticket->title, self::TITLE_PREFIX)) {
                    $eligible[(int) $ticket->id] = (string) $ticket->ticket_number;
                }
            });

        return $eligible;
    }

    /**
     * The given tickets that have a linked time entry or task, as [id => counts]. Read straight from the
     * tables so no model scope can hide a row. This is the up-front, whole-run refusal; it takes no locks and
     * is not what keeps a delete from detaching a record (see linkedUnderLock).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{time_entries: int, tasks: int}>
     */
    private function blockedTickets(array $ids): array
    {
        $blocked = [];

        foreach (['time_entries', 'tasks'] as $table) {
            DB::table($table)
                ->whereIn('ticket_id', $ids)
                ->select('ticket_id', DB::raw('count(*) as linked'))
                ->groupBy('ticket_id')
                ->get()
                ->each(function (object $row) use (&$blocked, $table): void {
                    $blocked[(int) $row->ticket_id] ??= ['time_entries' => 0, 'tasks' => 0];
                    $blocked[(int) $row->ticket_id][$table] = (int) $row->linked;
                });
        }

        return $blocked;
    }

    /**
     * The linked rows of one ticket, as a current (locking) read of the rows themselves, not an aggregate and
     * not a snapshot. It runs while the ticket row is locked, which is what stops a new link; the shared lock
     * on what it reads also stops an existing one being re-pointed meanwhile.
     *
     * @return array{time_entries: int, tasks: int}
     */
    private function linkedUnderLock(int $id): array
    {
        $linked = [];

        foreach (['time_entries', 'tasks'] as $table) {
            $linked[$table] = DB::table($table)->where('ticket_id', $id)->sharedLock()->pluck('id')->count();
        }

        return $linked;
    }

    /**
     * Remove one ticket in a single transaction: lock the row, re-check everything, remove the attachment
     * directory, delete the row. Throws, leaving the row, when anything cannot be established or done.
     *
     * @return array{deleted: bool, files: int, directory: bool}
     */
    private function removeTicket(int $id): array
    {
        $result = ['deleted' => false, 'files' => 0, 'directory' => false];

        DB::transaction(function () use ($id, &$result): void {
            $ticket = Ticket::query()->whereKey($id)->lockForUpdate()->first();

            // A row already gone (a repeated run, or removed by someone else) is simply done.
            if ($ticket === null) {
                return;
            }

            // What was eligible when the run began may not be now; only the locked, current row counts.
            if (! str_starts_with((string) $ticket->title, self::TITLE_PREFIX) || ! in_array((int) $ticket->user_id, $this->ownerIds(), true)) {
                throw new RuntimeException('it is no longer an E2E ticket of a seeded E2E customer; left in place');
            }

            $linked = $this->linkedUnderLock($id);

            if ($linked['time_entries'] > 0 || $linked['tasks'] > 0) {
                throw new RuntimeException('a time entry or task is linked to it; left in place');
            }

            $result = array_merge($result, $this->removeAttachmentDirectory($id));

            $ticket->delete();
            $result['deleted'] = true;
        });

        return $result;
    }

    /**
     * Remove `tickets/{id}`. The path is built from the integer key alone (never from a title or any stored
     * path), so it can only ever be this ticket's own directory.
     *
     * @return array{files: int, directory: bool}
     */
    private function removeAttachmentDirectory(int $id): array
    {
        $directory = 'tickets/'.$id;
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);
        $state = $this->attachmentDirectory($id);

        if ($state['state'] === self::UNKNOWN) {
            throw new RuntimeException('its attachment directory cannot be inspected by this process ('.$state['reason'].'); the ticket was left in place');
        }

        if ($state['state'] === self::NO_TICKETS_ROOT) {
            // Rows but no `tickets/` at all: either nothing was ever stored for them (then no row would name
            // files), or this process is looking at a different storage root than the database's.
            if (DB::table('ticket_attachments')->where('ticket_id', $id)->exists()) {
                throw new RuntimeException('it has attachment records but the attachment root holds no tickets directory (a different storage root than the database\'s?); the ticket was left in place');
            }

            return ['files' => 0, 'directory' => false];
        }

        if ($state['state'] === self::ABSENT) {
            return ['files' => 0, 'directory' => false];
        }

        $files = count($disk->allFiles($directory));

        if (! $disk->deleteDirectory($directory)) {
            throw new RuntimeException('its attachment directory could not be removed; the ticket was left in place');
        }

        $after = $this->attachmentDirectory($id);

        if ($after['state'] !== self::ABSENT) {
            throw new RuntimeException('its attachment directory is still there after the delete ('.$after['reason'].'); the ticket was left in place');
        }

        return ['files' => $files, 'directory' => true];
    }

    /**
     * What this process can establish about `tickets/{id}` on the private disk, from real listings: the root,
     * `tickets/`, then the directory. A path that cannot be listed is UNKNOWN, never absent: PHP's
     * `is_dir`/`file_exists` return false for both "not there" and "not allowed to look", so they are not used
     * to prove absence.
     *
     * @return array{state: string, reason: string}
     */
    private function attachmentDirectory(int $id): array
    {
        try {
            $root = rtrim($this->storageRoot(), '/');
        } catch (Throwable) {
            return ['state' => self::UNKNOWN, 'reason' => 'the private disk has no local root'];
        }

        $entries = $this->listing($root);

        if ($entries === null) {
            return ['state' => self::UNKNOWN, 'reason' => 'the storage root cannot be listed'];
        }

        if (! in_array('tickets', $entries, true)) {
            return ['state' => self::NO_TICKETS_ROOT, 'reason' => 'no tickets directory'];
        }

        $tickets = $this->listing($root.'/tickets');

        if ($tickets === null) {
            return ['state' => self::UNKNOWN, 'reason' => 'the tickets directory exists but cannot be listed (a file-permission mismatch with the web-server user?)'];
        }

        if (! in_array((string) $id, $tickets, true)) {
            return ['state' => self::ABSENT, 'reason' => 'not listed'];
        }

        $path = $root.'/tickets/'.$id;

        if (is_link($path) || ! is_dir($path)) {
            return ['state' => self::UNKNOWN, 'reason' => 'it is not a plain directory'];
        }

        if ($this->listing($path) === null) {
            return ['state' => self::UNKNOWN, 'reason' => 'the ticket\'s directory exists but cannot be listed (a file-permission mismatch with the web-server user?)'];
        }

        return ['state' => self::PRESENT, 'reason' => 'listed'];
    }

    private function storageRoot(): string
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);

        return $disk->path('');
    }

    /**
     * The entry names of a directory, or null when it cannot be listed for ANY reason.
     *
     * @return array<int, string>|null
     */
    private function listing(string $path): ?array
    {
        clearstatcache();
        $entries = @scandir($path);

        return $entries === false ? null : array_values(array_diff($entries, ['.', '..']));
    }

    /** Messages name a ticket number and a reason, not where the private files are on disk. */
    private function scrub(string $message): string
    {
        try {
            $root = rtrim($this->storageRoot(), '/');
        } catch (Throwable) {
            $root = '';
        }

        // The longer path first: the storage root normally lives inside the application directory.
        foreach (['<storage>' => $root, '<app>' => base_path()] as $label => $path) {
            if ($path !== '') {
                $message = str_replace($path, $label, $message);
            }
        }

        return $message;
    }
}
