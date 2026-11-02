<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\Helpdesk\TicketReference;
use App\Support\Helpdesk\TicketReferenceExhausted;
use App\Support\Helpdesk\TicketReferenceGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Release 0.9 C6-R (ADR-008): replaces the pre-C6-R ticket numbers (`TKT-0001`, ...) with canonical `TK-XXXXX-XXXXX` references.
 * Release scaffolding for the one staging conversion; a fresh production install has no legacy rows and only runs `--check`.
 *
 * Guarded on every side:
 *  - read-only unless `--apply`; `--check` exits 1 while any legacy row exists (the pre-production proof of "none");
 *  - `--apply` needs `--expect=N` equal to the legacy count (a count that moved means someone else changed the data), at most
 *    MAX_ROWS rows, and the explicit config gate `helpdesk.rereference_allowed`, closed by default;
 *  - a row is converted only if it still holds the legacy value it was listed with (re-read under lock), through the model, inside
 *    Ticket::convertingLegacyReferences(): the one window in which the immutability guard lets a stored reference change, and only
 *    non-canonical -> canonical;
 *  - only `ticket_number` changes (not `updated_at`): rows, replies, attachments, history, time entries and the private numeric
 *    attachment paths are untouched;
 *  - repeatable: a second run finds nothing to convert.
 */
#[Signature('tickets:rereference {--check : Read-only; exit 1 if any legacy ticket number exists} {--apply : Convert (needs --expect and the config gate)} {--expect= : The legacy row count the operator expects} {--map-file= : Write id, old and new references here (a new file, outside the repository)}')]
#[Description('Replace legacy TKT- ticket numbers with TK-XXXXX-XXXXX references (Release 0.9 C6-R conversion; guarded)')]
class RereferenceTickets extends Command
{
    /** More legacy rows than this is not the disposable staging set this command exists for. */
    public const MAX_ROWS = 25;

    public function handle(): int
    {
        $legacy = $this->legacyRows();

        if ($this->option('check')) {
            if ($this->option('apply')) {
                return $this->refuse('--check is read-only and cannot be combined with --apply.');
            }

            $this->line('Legacy ticket numbers: '.count($legacy));

            if (config('helpdesk.rereference_allowed') === true) {
                $this->warn('The conversion gate (TICKET_REREFERENCE_ALLOWED) is OPEN. Close it once the staging conversion is done.');
            }

            return $legacy === [] ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->line('Dry run: nothing is changed. Legacy ticket numbers: '.count($legacy));

            foreach ($legacy as $id => $old) {
                $this->line("  ticket id {$id}: {$old}");
            }

            return self::SUCCESS;
        }

        return $this->apply($legacy);
    }

    /** @param  array<int, string>  $legacy */
    private function apply(array $legacy): int
    {
        $expect = filter_var($this->option('expect'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($expect === false) {
            return $this->refuse('--apply needs --expect=N, the number of legacy tickets you expect.');
        }

        if ($expect !== count($legacy)) {
            return $this->refuse("Expected {$expect} legacy ticket number(s) but found ".count($legacy).'. Nothing was changed.');
        }

        if ($legacy === []) {
            $this->info('No legacy ticket numbers. Nothing to do.');

            return self::SUCCESS;
        }

        if (count($legacy) > self::MAX_ROWS) {
            return $this->refuse('More than '.self::MAX_ROWS.' legacy tickets: this command is only for the disposable staging set. Nothing was changed.');
        }

        if (config('helpdesk.rereference_allowed') !== true) {
            return $this->refuse('The conversion gate is closed (helpdesk.rereference_allowed / TICKET_REREFERENCE_ALLOWED). Nothing was changed.');
        }

        // The map is created exclusively (never over an existing file) and private BEFORE anything changes, and each converted row is
        // appended as it happens, so a crash part-way leaves a map of exactly what was converted.
        $map = null;
        $mapFile = $this->option('map-file');

        if (is_string($mapFile) && $mapFile !== '') {
            $previous = umask(0077);
            $map = @fopen($mapFile, 'x');
            umask($previous);

            if ($map === false) {
                return $this->refuse('--map-file must be a new file in a writable directory. Nothing was changed.');
            }
        }

        /** @var array<int, array{string, string}> $done */
        $done = [];
        $failed = 0;

        try {
            $this->convertAll($legacy, $done, $failed, $map);
        } finally {
            if ($map !== null) {
                fclose($map);
            }
        }

        $this->info('Converted '.count($done).' ticket(s); '.$failed.' not converted.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, string>  $legacy
     * @param  array<int, array{string, string}>  $done
     * @param  resource|null  $map
     */
    private function convertAll(array $legacy, array &$done, int &$failed, $map): void
    {
        Ticket::convertingLegacyReferences(function () use ($legacy, &$done, &$failed, $map) {
            foreach ($legacy as $id => $old) {
                try {
                    $new = $this->convert($id, $old);
                } catch (Throwable $e) {
                    $this->error("Ticket id {$id} ({$old}) was not converted: ".$e->getMessage());
                    $failed++;

                    continue;
                }

                if ($new === null) {
                    $this->error("Ticket id {$id} no longer holds {$old}; left untouched.");
                    $failed++;

                    continue;
                }

                $done[$id] = [$old, $new];

                if ($map !== null) {
                    fwrite($map, "{$id}\t{$old}\t{$new}\n");
                }

                $this->line("  ticket id {$id}: {$old} -> {$new}");
            }
        });
    }

    /** The new reference, or null when the row no longer holds $old. Bounded retry on the unique index only, like creation. */
    private function convert(int $id, string $old): ?string
    {
        $generator = app(TicketReferenceGenerator::class);

        return DB::transaction(function () use ($id, $old, $generator) {
            $ticket = Ticket::query()->lockForUpdate()->find($id);

            if ($ticket === null || $ticket->ticket_number !== $old) {
                return null;
            }

            $ticket->timestamps = false;

            for ($attempt = 1; $attempt <= TicketService::REFERENCE_ATTEMPTS; $attempt++) {
                $new = $generator->next();

                try {
                    DB::transaction(fn () => $ticket->update(['ticket_number' => $new]));

                    return $new;
                } catch (QueryException $e) {
                    if (! TicketReference::isCollision($e)) {
                        throw $e;
                    }
                }
            }

            throw new TicketReferenceExhausted("No unique reference could be issued for ticket id {$id}.");
        });
    }

    /** @return array<int, string> id => the non-canonical value, in id order */
    private function legacyRows(): array
    {
        $legacy = [];

        foreach (DB::table('tickets')->select(['id', 'ticket_number'])->orderBy('id')->cursor() as $row) {
            if (! TicketReference::isCanonical($row->ticket_number)) {
                $legacy[(int) $row->id] = (string) $row->ticket_number;
            }
        }

        return $legacy;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
