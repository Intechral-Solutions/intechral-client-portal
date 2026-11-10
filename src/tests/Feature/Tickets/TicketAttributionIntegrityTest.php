<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Helpdesk\TicketReferenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 1.0 (R1-2A): the storage guarantees behind attribution. The database (NOT NULL, RESTRICT foreign keys) is the last line; the
 * model refuses a change to either value; and the application has exactly one place that writes a ticket.
 */

beforeEach(fn () => $this->seedRolesAndPermissions());

function attrRawTicket(array $overrides = []): array
{
    $user = User::factory()->create();

    return [
        'ticket_number' => app(TicketReferenceGenerator::class)->next(),
        'user_id' => $user->id,
        'organization_id' => Organization::factory()->create()->id,
        'submitted_by_id' => $user->id,
        'title' => 'Raw', 'description' => 'Raw', 'category' => 'General', 'priority' => 'low', 'status' => 'open',
        'created_at' => now(), 'updated_at' => now(),
        ...$overrides,
    ];
}

describe('schema', function () {
    it('has NOT NULL organization and submitter columns and no company column', function () {
        $columns = collect(Schema::getColumns('tickets'))->keyBy('name');

        expect($columns['organization_id']['nullable'])->toBeFalse()
            ->and($columns['submitted_by_id']['nullable'])->toBeFalse()
            ->and($columns['organization_id']['type'])->toContain('bigint')->toContain('unsigned')
            ->and($columns['submitted_by_id']['type'])->toContain('bigint')->toContain('unsigned')
            ->and($columns->has('company_id'))->toBeFalse()
            ->and($columns->has('user_id'))->toBeTrue();
    });

    it('restricts both foreign keys on delete and update', function () {
        $rules = collect(DB::select(
            'select k.column_name as c, k.referenced_table_name as t, r.delete_rule as d, r.update_rule as u
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
             where k.table_schema = database() and k.table_name = ? and k.column_name in (?, ?)',
            ['tickets', 'organization_id', 'submitted_by_id']
        ))->keyBy('c');

        expect($rules['organization_id']->t)->toBe('organizations')->and($rules['organization_id']->d)->toBe('RESTRICT')->and($rules['organization_id']->u)->toBe('RESTRICT')
            ->and($rules['submitted_by_id']->t)->toBe('users')->and($rules['submitted_by_id']->d)->toBe('RESTRICT')->and($rules['submitted_by_id']->u)->toBe('RESTRICT');
    });

    it('refuses a row without an organization, without a submitter, or naming one that does not exist', function (array $overrides) {
        expect(fn () => DB::table('tickets')->insert(array_diff_key(attrRawTicket(), array_flip($overrides['without'] ?? [])) + ($overrides['set'] ?? [])))
            ->toThrow(QueryException::class);
    })->with([
        'no organization' => [['without' => ['organization_id']]],
        'no submitter' => [['without' => ['submitted_by_id']]],
        'null organization' => [['set' => ['organization_id' => null], 'without' => ['organization_id']]],
        'dangling organization' => [['without' => ['organization_id'], 'set' => ['organization_id' => 999999999]]],
        'dangling submitter' => [['without' => ['submitted_by_id'], 'set' => ['submitted_by_id' => 999999999]]],
    ]);

    it('refuses to delete an organization or a user that a ticket still names, as requester or as submitter', function () {
        $submitter = User::factory()->create();
        $ticket = Ticket::factory()->create(['submitted_by_id' => $submitter->id]);

        foreach ([
            fn () => DB::table('organizations')->where('id', $ticket->organization_id)->delete(),
            fn () => DB::table('users')->where('id', $submitter->id)->delete(),
            fn () => DB::table('users')->where('id', $ticket->user_id)->delete(),
        ] as $delete) {
            // Each refused statement poisons nothing: MariaDB rolls back the statement only.
            expect($delete)->toThrow(QueryException::class);
        }

        expect(DB::table('tickets')->where('id', $ticket->id)->exists())->toBeTrue();
    });
});

describe('the model', function () {
    it('refuses to create a ticket without both values', function (array $set) {
        $attributes = Ticket::factory()->raw();
        $ticket = new Ticket(Arr::except($attributes, ['organization_id', 'submitted_by_id']));
        foreach ($set as $column => $value) {
            $ticket->{$column} = $value;
        }

        expect(fn () => $ticket->save())->toThrow(InvalidArgumentException::class, 'customer organization and its submitter');
    })->with([
        'neither' => [[]],
        'only the organization' => [['organization_id' => 1]],
        'only the submitter' => [['submitted_by_id' => 1]],
        'zero' => [['organization_id' => 0, 'submitted_by_id' => 0]],
    ]);

    it('does not mass-assign the attribution columns', function () {
        $other = Organization::factory()->create();
        $ticket = Ticket::factory()->create();
        $organizationId = $ticket->organization_id;
        $submitterId = $ticket->submitted_by_id;

        $ticket->fill(['organization_id' => $other->id, 'submitted_by_id' => User::factory()->create()->id, 'title' => 'Renamed'])->save();

        $fresh = $ticket->fresh();
        expect($fresh->title)->toBe('Renamed')->and($fresh->organization_id)->toBe($organizationId)->and($fresh->submitted_by_id)->toBe($submitterId);
        expect((new Ticket)->getFillable())->not->toContain('organization_id')->not->toContain('submitted_by_id')->not->toContain('company_id');
    });

    it('refuses a direct change to either value once set, and leaves the row untouched', function (string $column) {
        $ticket = Ticket::factory()->create();
        $before = $ticket->getAttribute($column);
        $replacement = $column === 'organization_id' ? Organization::factory()->create()->id : User::factory()->create()->id;

        $ticket->{$column} = $replacement;

        expect(fn () => $ticket->save())->toThrow(LogicException::class, 'immutable');
        expect($ticket->fresh()->getAttribute($column))->toBe($before);
    })->with(['organization_id', 'submitted_by_id']);

    it('still allows every ordinary change to a ticket', function () {
        $ticket = Ticket::factory()->open()->create();

        $ticket->status = 'in_progress';
        $ticket->save();

        expect($ticket->fresh()->status)->toBe('in_progress');
    });

    it('resolves the organization even when its viewer is outside the organization scope', function () {
        $ticket = Ticket::factory()->create();
        $outsider = ticketUser();

        $this->actingAs($outsider);

        expect($ticket->organization?->id)->toBe($ticket->organization_id)
            ->and($ticket->submittedBy?->id)->toBe($ticket->submitted_by_id);
    });
});

/*
 * REGRESSION TRIPWIRES, not enforcement. These scans read the application's source text (comments removed) and fail when a NEW way of
 * writing a ticket, or of assigning the attribution columns, appears in app/. They are cheap and catch the common accidents: a second
 * `Ticket::create`, a query-builder insert, an assignment to either column. They cannot see dynamic code, a table name in a variable,
 * a call through a macro or a raw SQL string built at runtime, and they do not look at tests, seeders or migrations. What actually
 * guarantees attribution is the database (NOT NULL, RESTRICT foreign keys), the model guards above, and `createWithReference()` being
 * private; direct SQL or a query-builder UPDATE of these two columns is NOT prevented by anything in PHP (D0 section I records that no
 * database trigger exists), which is why no production path may do either, and why this block exists to notice if one appears.
 */
const ATTR_WRITE_VERBS = 'create|insert|insertGetId|insertOrIgnore|insertUsing|upsert|forceCreate|firstOrCreate|firstOrNew|updateOrCreate|make|createMany|saveMany';

describe('regression tripwires: where a ticket is written', function () {
    /** The PHP source without its comments, so prose that mentions "a new Ticket" or a column name is not mistaken for code. */
    function attrCode(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return list<string> */
    function attrFilesMatching(string $directory, string $pattern): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match($pattern, attrCode(file_get_contents($file->getPathname()))) === 1) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    it('creates tickets in app/ only inside TicketService, behind ServiceRequestAccess', function () {
        $verbs = ATTR_WRITE_VERBS;
        $writers = attrFilesMatching('app', '~(?:'.implode('|', [
            "\\bTicket\\s*::\\s*(?:query\\s*\\(\\s*\\)\\s*->\\s*)?(?:{$verbs}|unguarded)\\b",
            // `new Ticket(...)`, case-sensitive and followed by an argument list: a label such as 'New Ticket' is not code.
            '\\bnew\\s+Ticket\\s*[(;]',
            "table\\(\\s*['\"]tickets['\"]\\s*\\)\\s*->\\s*(?:{$verbs})\\b",
            "->\\s*tickets\\s*\\(\\s*\\)\\s*->\\s*(?:{$verbs}|save)\\b",
            "(?i:\\b(?:insert|replace)\\s+(?:ignore\\s+)?into\\s+[`'\"]?tickets\\b)",
        ]).')~');

        // The rehearsal fixtures insert raw rows into a 0.9-shaped scratch database through a helper (never `Ticket`, never the application's own).
        expect($writers)->toBe(['app/Services/TicketService.php']);
    });

    it('assigns the attribution columns on an object in app/ only inside TicketService', function () {
        expect(attrFilesMatching('app', '/->\\s*(?:organization_id|submitted_by_id)\\s*=(?!=)/'))->toBe(['app/Services/TicketService.php']);
    });

    it('names the submitter column in application code only where provenance is written, guarded or checked', function () {
        // The model (guards), the one writer, and the snapshot's integrity check.
        expect(attrFilesMatching('app', '/submitted_by_id/'))->toBe([
            'app/Models/Ticket.php',
            'app/Services/TicketService.php',
            'app/Support/Rehearsal/IntegritySnapshot.php',
        ]);
    });

    it('has no update through the query builder or raw SQL that names either column', function () {
        $columns = '(?:organization_id|submitted_by_id)';

        expect(attrFilesMatching('app', '~(?:'.implode('|', [
            "(?:Ticket\\s*::|table\\(\\s*['\"]tickets['\"]\\s*\\)|->\\s*tickets\\s*\\(\\s*\\))[^;]*->\\s*(?:update|upsert|increment|decrement)\\s*\\([^;]*{$columns}",
            "(?i:\\bupdate\\s+[`'\"]?tickets[`'\"]?\\s+set\\b[^;]*{$columns})",
            "forceFill\\s*\\([^;]*{$columns}[^;]*\\)[^;]*->\\s*(?:save|update)\\b",
        ]).')~s'))->toBe([]);
    });
});
