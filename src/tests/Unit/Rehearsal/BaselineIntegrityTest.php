<?php

use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\SnapshotComparator;
use Illuminate\Support\Facades\DB;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1A: the deterministic fixtures and the integrity snapshot. The primary rehearsal database holds the
 * baseline; every test that damages it puts it back before it ends.
 */

beforeEach(function () {
    RehearsalSandbox::use();
    $this->baseline = RehearsalSandbox::baseline();
    $this->primary = RehearsalTarget::primary();
    $this->db = fn () => DB::connection($this->primary->connectionName());
    $this->file = fn (string $path) => $this->primary->filesRoot().'/'.$path;
});

function differencesFrom(array $baseline): array
{
    return array_column(SnapshotComparator::compare($baseline, IntegritySnapshot::capture(RehearsalTarget::primary())), 'code');
}

it('seeds fixtures that cover the product and every integrity check passes on them', function () {
    expect(IntegritySnapshot::failures($this->baseline))->toBe([])
        ->and(count($this->baseline['rows']))->toBeGreaterThan(30)
        ->and(array_sum($this->baseline['rows']))->toBeGreaterThan(200)
        ->and($this->baseline['files'])->toHaveCount(4)
        ->and($this->baseline['schema']['foreign_keys'])->not->toBeEmpty();

    foreach (['users', 'tickets', 'ticket_attachments', 'projects', 'tasks', 'time_entries', 'invoices', 'activity_log'] as $table) {
        expect($this->baseline['rows'][$table])->toBeGreaterThan(0, "{$table} has no fixture rows");
    }
});

it('generates identical fixtures and an identical snapshot every time', function () {
    $again = RehearsalSandbox::baseline(rebuild: true);

    expect(IntegritySnapshot::hash($again))->toBe(IntegritySnapshot::hash($this->baseline))
        ->and($again['rows'])->toBe($this->baseline['rows'])
        ->and($again['files'])->toBe($this->baseline['files']);
});

it('captures a snapshot that is reproducible without any change in between', function () {
    $first = IntegritySnapshot::capture($this->primary);
    $second = IntegritySnapshot::capture($this->primary);

    expect(IntegritySnapshot::hash($first))->toBe(IntegritySnapshot::hash($second))
        ->and(SnapshotComparator::compare($first, $second))->toBe([])
        ->and(IntegritySnapshot::hash($first))->toBe(IntegritySnapshot::hash($this->baseline));
});

it('fails the comparison when a row is lost, and passes again once it is back', function () {
    $row = (array) ($this->db)()->table('task_comments')->orderBy('id')->first();
    ($this->db)()->table('task_comments')->where('id', $row['id'])->delete();

    try {
        $codes = differencesFrom($this->baseline);
        expect($codes)->toContain('rows.count_changed', 'row.missing');
    } finally {
        ($this->db)()->table('task_comments')->insert($row);
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});

it('fails the comparison when a row changes in place with its count unchanged', function () {
    $before = ($this->db)()->table('tasks')->orderBy('id')->first();
    ($this->db)()->table('tasks')->where('id', $before->id)->update(['title' => $before->title.' (edited)']);

    try {
        expect(differencesFrom($this->baseline))->toContain('row.changed')
            ->not->toContain('rows.count_changed');
    } finally {
        ($this->db)()->table('tasks')->where('id', $before->id)->update(['title' => $before->title]);
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});

it('detects a reference to a parent that does not exist', function () {
    $reply = ($this->db)()->table('ticket_replies')->orderBy('id')->first();
    $connection = ($this->db)();
    $connection->statement('set foreign_key_checks = 0');
    $connection->table('ticket_replies')->where('id', $reply->id)->update(['user_id' => 987654]);
    $connection->statement('set foreign_key_checks = 1');

    try {
        $snapshot = IntegritySnapshot::capture($this->primary);
        $orphans = array_filter(IntegritySnapshot::failures($snapshot), fn (string $f) => str_starts_with($f, 'fk_orphans:') && str_contains($f, 'ticket_replies'));

        expect($orphans)->not->toBeEmpty()
            ->and(array_column(SnapshotComparator::compare($this->baseline, $snapshot), 'code'))->toContain('check.failed');
    } finally {
        ($this->db)()->table('ticket_replies')->where('id', $reply->id)->update(['user_id' => $reply->user_id]);
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});

it('detects an attachment row whose file is missing', function () {
    $path = ($this->file)('tickets/1/rehearsal-1.txt');
    rename($path, $path.'.hidden');

    try {
        $snapshot = IntegritySnapshot::capture($this->primary);

        expect(IntegritySnapshot::failures($snapshot))->toContain('attachment_rows_without_file: 1')
            ->and(array_column(SnapshotComparator::compare($this->baseline, $snapshot), 'code'))->toContain('file.missing');
    } finally {
        rename($path.'.hidden', $path);
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});

it('detects a file that no attachment row points at, and a ticket directory with no rows', function () {
    $extra = ($this->file)('tickets/1/unexpected.txt');
    $stray = ($this->file)('tickets/999/orphan.txt');
    file_put_contents($extra, 'x');
    mkdir(dirname($stray));
    file_put_contents($stray, 'y');

    try {
        $snapshot = IntegritySnapshot::capture($this->primary);

        expect(IntegritySnapshot::failures($snapshot))->toContain('files_without_attachment_row: 2', 'ticket_directories_without_attachment_rows: 1')
            ->and(array_column(SnapshotComparator::compare($this->baseline, $snapshot), 'code'))->toContain('file.added');
    } finally {
        unlink($extra);
        unlink($stray);
        rmdir(dirname($stray));
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});

it('detects an attachment file whose content or size changed', function () {
    $path = ($this->file)('tickets/1/rehearsal-1.txt');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, strrev($original));
        expect(differencesFrom($this->baseline))->toContain('file.changed');

        file_put_contents($path, $original.'!');
        $snapshot = IntegritySnapshot::capture($this->primary);
        expect(IntegritySnapshot::failures($snapshot))->toContain('attachment_size_differs_from_file: 1');
    } finally {
        file_put_contents($path, $original);
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});

it('treats a check that cannot run as a failure, never as a pass', function () {
    ($this->db)()->statement('alter table time_entry_blocks rename to time_entry_blocks_moved');

    try {
        $failures = IntegritySnapshot::failures(IntegritySnapshot::capture($this->primary));

        expect(implode("\n", $failures))->toContain('unavailable');
    } finally {
        ($this->db)()->statement('alter table time_entry_blocks_moved rename to time_entry_blocks');
    }

    expect(differencesFrom($this->baseline))->toBe([]);
});
