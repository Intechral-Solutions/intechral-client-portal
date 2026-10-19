<?php

use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\SnapshotComparator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1B (J-2): the schema half of the integrity snapshot. A migration that changes a foreign key's ON UPDATE
 * rule, a non-unique index, or a collation, and nothing else, must not pass as "no difference"; and a narrowly
 * declared change must pass without hiding anything it did not declare. Each test changes the rehearsal
 * database directly (DDL), compares, and puts it back, then proves it is back.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    $this->baseline = RehearsalSandbox::baseline();
    $this->db = fn (): Connection => DB::connection(RehearsalTarget::primary()->connectionName());
});

/** "code subject" for every difference between the baseline and the database as it is now. */
function schemaDifferences(array $baseline, array $declared = []): array
{
    return array_map(
        fn (array $d) => $d['code'].' '.$d['subject'],
        // Pinned to the baseline's columns, as the runner captures after a candidate: an added column is not a changed row.
        SnapshotComparator::compare($baseline, IntegritySnapshot::capture(RehearsalTarget::primary(), IntegritySnapshot::pinnedColumns($baseline)), $declared)
    );
}

/** Replace a foreign key (drop, then add under the same name) with the given rules. */
function setForeignKey(Connection $db, string $table, string $constraint, string $column, string $parent, string $delete, string $update): void
{
    $db->statement("alter table `{$table}` drop foreign key `{$constraint}`");
    $db->statement("alter table `{$table}` add constraint `{$constraint}` foreign key (`{$column}`) references `{$parent}` (`id`) on delete {$delete} on update {$update}");
}

it('captures what the migrations can change: both foreign-key rules, non-unique indexes and collations', function () {
    $schema = $this->baseline['schema'];

    expect($schema['foreign_keys']['ticket_replies.ticket_id->tickets.id'])->toMatchArray(['rule' => 'CASCADE', 'update_rule' => 'RESTRICT'])
        ->and($schema['indexes'])->toContain('tickets(user_id,status)', 'ticket_replies(user_id)')
        ->and($schema['collations']['cms_pages']['title'])->toBe('utf8mb4/utf8mb4_unicode_ci')
        ->and($schema['table_collations']['cms_pages'])->toBe('utf8mb4_unicode_ci')
        ->and($schema['indexes'])->toBe(array_values(array_unique($schema['indexes'])))
        ->and(collect($schema['indexes'])->sort()->values()->all())->toBe($schema['indexes']);
});

it('is deterministic: the extended schema section hashes identically on every capture', function () {
    $first = IntegritySnapshot::capture(RehearsalTarget::primary());
    $second = IntegritySnapshot::capture(RehearsalTarget::primary());

    expect(IntegritySnapshot::hash($first))->toBe(IntegritySnapshot::hash($second))
        ->and($first['schema'])->toBe($second['schema']);
});

describe('foreign-key rules', function () {
    it('fails an undeclared ON UPDATE change, and passes it when declared, without hiding anything else', function () {
        $db = ($this->db)();
        setForeignKey($db, 'ticket_replies', 'ticket_replies_ticket_id_foreign', 'ticket_id', 'tickets', 'CASCADE', 'CASCADE');
        $token = 'fku~:ticket_replies.ticket_id->tickets.id:RESTRICT>CASCADE';

        try {
            expect(schemaDifferences($this->baseline))->toBe(["schema.undeclared_change {$token}"])
                ->and(schemaDifferences($this->baseline, [$token]))->toBe([]);

            // Declared ON UPDATE change plus an unrelated, undeclared one: only the unrelated one is reported.
            $db->statement('create index probe_idx on tickets (priority)');
            expect(schemaDifferences($this->baseline, [$token]))->toBe(['schema.undeclared_change index+:tickets(priority)']);
        } finally {
            $db->statement('drop index if exists probe_idx on tickets');
            setForeignKey($db, 'ticket_replies', 'ticket_replies_ticket_id_foreign', 'ticket_id', 'tickets', 'CASCADE', 'RESTRICT');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });

    it('still detects an ON DELETE change, which is a different token from an ON UPDATE change', function () {
        $db = ($this->db)();
        setForeignKey($db, 'ticket_replies', 'ticket_replies_ticket_id_foreign', 'ticket_id', 'tickets', 'RESTRICT', 'RESTRICT');
        $token = 'fk~:ticket_replies.ticket_id->tickets.id:CASCADE>RESTRICT';

        try {
            expect(schemaDifferences($this->baseline))->toBe(["schema.undeclared_change {$token}"])
                ->and(schemaDifferences($this->baseline, [$token]))->toBe([])
                // Declaring the ON UPDATE token for an ON DELETE change declares nothing: both mismatches are reported.
                ->and(schemaDifferences($this->baseline, ['fku~:ticket_replies.ticket_id->tickets.id:RESTRICT>CASCADE']))->toBe([
                    "schema.undeclared_change {$token}",
                    'schema.declared_change_missing fku~:ticket_replies.ticket_id->tickets.id:RESTRICT>CASCADE',
                ]);
        } finally {
            setForeignKey($db, 'ticket_replies', 'ticket_replies_ticket_id_foreign', 'ticket_id', 'tickets', 'CASCADE', 'RESTRICT');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });

    it('states both rules for an added foreign key, so neither can change unseen', function () {
        $db = ($this->db)();
        $db->statement('create table probe_child (id bigint unsigned primary key, ticket_id bigint unsigned not null, '
            .'constraint probe_child_ticket_foreign foreign key (ticket_id) references tickets (id) on delete cascade on update cascade) engine = InnoDB');  // explicit: a MyISAM default (the Namecheap host) ignores foreign keys

        try {
            $differences = schemaDifferences($this->baseline);

            expect($differences)->toContain('schema.undeclared_change fk+:probe_child.ticket_id->tickets.id:CASCADE/CASCADE')
                ->and($differences)->toContain('schema.undeclared_change table+:probe_child')
                // The same foreign key with a different ON UPDATE rule is a different declaration.
                ->and(schemaDifferences($this->baseline, ['table+:probe_child', 'index+:probe_child(ticket_id)', 'fk+:probe_child.ticket_id->tickets.id:CASCADE/RESTRICT']))
                ->toContain('schema.undeclared_change fk+:probe_child.ticket_id->tickets.id:CASCADE/CASCADE')
                ->and(schemaDifferences($this->baseline, ['table+:probe_child', 'index+:probe_child(ticket_id)', 'fk+:probe_child.ticket_id->tickets.id:CASCADE/CASCADE']))->toBe([]);
        } finally {
            $db->statement('drop table if exists probe_child');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });
});

describe('non-unique indexes', function () {
    it('fails an undeclared added index and passes it when declared', function () {
        $db = ($this->db)();
        $db->statement('create index probe_idx on tickets (priority, status)');

        try {
            expect(schemaDifferences($this->baseline))->toBe(['schema.undeclared_change index+:tickets(priority,status)'])
                ->and(schemaDifferences($this->baseline, ['index+:tickets(priority,status)']))->toBe([])
                // Declaring a different column order declares nothing.
                ->and(schemaDifferences($this->baseline, ['index+:tickets(status,priority)']))->toBe([
                    'schema.undeclared_change index+:tickets(priority,status)',
                    'schema.declared_change_missing index+:tickets(status,priority)',
                ]);
        } finally {
            $db->statement('drop index if exists probe_idx on tickets');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });

    it('fails an undeclared dropped index, and does not treat a rename as a change', function () {
        $db = ($this->db)();
        $db->statement('create index probe_idx on tickets (priority)');
        $withIndex = IntegritySnapshot::capture(RehearsalTarget::primary());

        try {
            $db->statement('alter table tickets rename index probe_idx to probe_idx_renamed');
            expect(SnapshotComparator::compare($withIndex, IntegritySnapshot::capture(RehearsalTarget::primary())))->toBe([]);

            $db->statement('drop index probe_idx_renamed on tickets');
            $codes = array_map(fn (array $d) => $d['code'].' '.$d['subject'], SnapshotComparator::compare($withIndex, IntegritySnapshot::capture(RehearsalTarget::primary())));
            expect($codes)->toBe(['schema.undeclared_change index-:tickets(priority)']);
        } finally {
            $db->statement('drop index if exists probe_idx on tickets');
            $db->statement('drop index if exists probe_idx_renamed on tickets');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });
});

describe('collations', function () {
    it('fails an undeclared column collation change on its own token, not as a type change', function () {
        $db = ($this->db)();
        $column = $db->selectOne("select column_type as t, is_nullable as n, column_default as d from information_schema.columns where table_schema = database() and table_name = 'cms_pages' and column_name = 'title'");
        expect($column->d)->toBeNull()->and($column->n)->toBe('NO');

        $modify = fn (string $collation) => $db->statement("alter table cms_pages modify title {$column->t} character set utf8mb4 collate {$collation} not null");
        $modify('utf8mb4_bin');

        try {
            expect(schemaDifferences($this->baseline))->toBe(['schema.undeclared_change collation~:cms_pages.title'])
                ->and(schemaDifferences($this->baseline, ['collation~:cms_pages.title']))->toBe([])
                // A type declaration does not cover a collation change.
                ->and(schemaDifferences($this->baseline, ['column~:cms_pages.title']))->toBe([
                    'schema.undeclared_change collation~:cms_pages.title',
                    'schema.declared_change_missing column~:cms_pages.title',
                ]);
        } finally {
            $modify('utf8mb4_unicode_ci');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });

    it('fails an undeclared change of a table\'s default collation, which changes no column', function () {
        $db = ($this->db)();
        $db->statement('alter table task_checklist_items default collate utf8mb4_bin');

        try {
            expect(schemaDifferences($this->baseline))->toBe(['schema.undeclared_change tablecollation~:task_checklist_items'])
                ->and(schemaDifferences($this->baseline, ['tablecollation~:task_checklist_items']))->toBe([]);
        } finally {
            $db->statement('alter table task_checklist_items default collate utf8mb4_unicode_ci');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });

    it('does not report a new column\'s collation separately from the column itself', function () {
        $db = ($this->db)();
        $db->statement('alter table task_comments add column probe_note varchar(20) null');

        try {
            expect(schemaDifferences($this->baseline))->toBe(['schema.undeclared_change column+:task_comments.probe_note'])
                ->and(schemaDifferences($this->baseline, ['column+:task_comments.probe_note']))->toBe([]);
        } finally {
            $db->statement('alter table task_comments drop column probe_note');
        }

        expect(schemaDifferences($this->baseline))->toBe([]);
    });
});
