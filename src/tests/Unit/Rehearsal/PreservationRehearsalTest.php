<?php

use App\Support\Rehearsal\IntegritySnapshot;
use App\Support\Rehearsal\MigrationSet;
use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\PreservationKeys;
use Tests\Support\RehearsalSandbox;

/*
 * Release 0.9 (R0.9-1B): the REAL candidate migrations (M1 users.disabled_at, M2 the 13 user foreign keys, M3
 * project_company.crm_company_id), rehearsed with the R0.9-1A harness on populated fixtures: the baseline is
 * pinned, the fixtures are seeded, and only then are the candidates applied. Then the behaviour they exist for is
 * tried in the rehearsal database: deleting a referenced user or company is refused and loses nothing, and the
 * probes leave the snapshot exactly as it was. Storage is a throw-away directory (RehearsalSandbox); the committed
 * migrations and manifests are the real ones.
 */

const PRESERVATION_CANDIDATES = [
    '2026_10_12_000001_add_disabled_at_to_users_table',
    '2026_10_12_000002_restrict_user_business_record_deletes',
    '2026_10_12_000003_restrict_company_project_association_deletes',
];

beforeEach(function () {
    RehearsalSandbox::use();
    File::deleteDirectory(RehearsalTarget::backupsRoot());
});

/** @return array{0: int, 1: array<string, mixed>} */
function rehearseCandidates(array $candidates, array $options = []): array
{
    $buffer = new BufferedOutput;
    $exit = Artisan::call('rehearsal:run', ['--json' => true, '--candidate' => $candidates] + $options, $buffer);

    return [$exit, json_decode($buffer->fetch(), true)];
}

it('names exactly the three approved candidates, after the pinned baseline', function () {
    $set = MigrationSet::repository();

    expect($set->candidateNames())->toBe(PRESERVATION_CANDIDATES)
        ->and($set->select(PRESERVATION_CANDIDATES))->toHaveCount(3)
        ->and(count($set->baselineNames()))->toBe(45);
});

it('applies M1, M2 and M3 to the populated baseline, after the fixtures, changing exactly the declared structure and no record', function () {
    [$exit, $report] = rehearseCandidates(PRESERVATION_CANDIDATES);

    expect($exit)->toBe(0)
        ->and($report['ok'])->toBeTrue()
        ->and($report['candidates'])->toBe(PRESERVATION_CANDIDATES)
        ->and($report['migration']['applied'])->toBe(PRESERVATION_CANDIDATES)
        ->and($report['migration']['applied_after_fixtures'])->toBeTrue()
        ->and($report['migration']['populated_before'])->toBe(['migrations' => 45, 'fixture_rows' => 205, 'fixture_tables' => 33])
        ->and($report['migration']['unexercised'])->toBe([])
        ->and($report['migration']['differences'])->toBe([])
        ->and($report['baseline']['failures'])->toBe([])
        ->and($report['restore']['matches_primary'])->toBeTrue()
        ->and($report['restore']['differences'])->toBe([])
        ->and($report['cleanup']['backup'])->toBe('removed');
});

it('changes the 14 approved keys and adds the column, as read back from the rehearsal database', function () {
    [$exit] = rehearseCandidates(PRESERVATION_CANDIDATES, ['--no-restore' => true]);
    expect($exit)->toBe(0);

    $snapshot = IntegritySnapshot::capture(RehearsalTarget::primary());
    $keys = $snapshot['schema']['foreign_keys'];

    foreach (PreservationKeys::USER_KEYS as [$table, $column]) {
        expect($keys["{$table}.{$column}->users.id"])->toMatchArray(['rule' => 'RESTRICT', 'update_rule' => 'RESTRICT']);
    }

    expect($keys['project_company.crm_company_id->crm_companies.id'])->toMatchArray(['rule' => 'RESTRICT', 'update_rule' => 'RESTRICT'])
        // Unrelated keys keep their rules.
        ->and($keys['project_members.user_id->users.id']['rule'])->toBe('CASCADE')
        ->and($keys['tickets.assignee_id->users.id']['rule'])->toBe('SET NULL')
        ->and($keys['project_company.project_id->projects.id']['rule'])->toBe('CASCADE')
        ->and($snapshot['schema']['columns']['users'])->toHaveKey('disabled_at');
});

// 203 -> 205 (R0.9-CS1): the catalogue gained portal.operator, so the seeded permissions and role grants gained one row each.
it('adds users.disabled_at to a populated users table without altering any existing user', function () {
    // M1 alone: the five fixture users exist before it runs, and the harness compares every one of their rows
    // (over the pre-migration columns) before and after.
    [$exit, $report] = rehearseCandidates([PRESERVATION_CANDIDATES[0]], ['--no-restore' => true]);

    expect($exit)->toBe(0)
        ->and($report['migration']['differences'])->toBe([])
        ->and($report['migration']['populated_before']['fixture_rows'])->toBe(205);

    $db = RehearsalTarget::primary()->connection();

    expect($db->table('users')->count())->toBe(5)
        ->and($db->table('users')->whereNotNull('disabled_at')->count())->toBe(0)
        ->and(Schema::connection(RehearsalTarget::primary()->connectionName())->hasColumn('users', 'disabled_at'))->toBeTrue();
});

it('refuses to delete a referenced user or company in the rehearsal database, loses nothing, and leaves the snapshot unchanged', function () {
    [$exit, $report] = rehearseCandidates(PRESERVATION_CANDIDATES, ['--no-restore' => true]);
    expect($exit)->toBe(0);

    $target = RehearsalTarget::primary();
    $db = $target->connection();
    $before = IntegritySnapshot::capture($target);

    // Every fixture user is referenced by at least one durable record.
    foreach ($db->table('users')->orderBy('id')->pluck('id') as $id) {
        expect(fn () => $db->table('users')->where('id', $id)->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row');
    }

    // A company that a project references.
    expect(fn () => $db->table('crm_companies')->where('id', 1)->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row');

    expect(IntegritySnapshot::hash(IntegritySnapshot::capture($target)))->toBe(IntegritySnapshot::hash($before));

    // And the protection is not a blanket: a company no project references, and a user who owns nothing, can still go.
    $db->beginTransaction();
    try {
        expect($db->table('crm_companies')->where('id', 2)->delete())->toBe(1);

        $db->table('users')->insert(['id' => 99, 'name' => 'Throwaway', 'email' => 'throwaway@rehearsal.test', 'password' => null, 'created_at' => now(), 'updated_at' => now()]);
        expect($db->table('users')->where('id', 99)->delete())->toBe(1);
    } finally {
        $db->rollBack();
    }

    expect(IntegritySnapshot::hash(IntegritySnapshot::capture($target)))->toBe(IntegritySnapshot::hash($before));
});
