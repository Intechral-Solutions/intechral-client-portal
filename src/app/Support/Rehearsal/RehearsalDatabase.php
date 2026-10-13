<?php

namespace App\Support\Rehearsal;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Resets and migrates a rehearsal database. Every method verifies the target with {@see TargetGuard} first,
 * so there is no way to reach a destructive statement without the full safety contract passing.
 */
final class RehearsalDatabase
{
    /**
     * Run $callback with the rehearsal connection as the application's default, and put the previous default
     * back afterwards. Models, the schema builder and the existing audits all resolve the default, so while
     * this runs nothing can reach the development or testing database by accident.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function within(RehearsalTarget $target, callable $callback)
    {
        TargetGuard::assert($target);

        return DB::usingConnection($target->connectionName(), $callback);
    }

    /**
     * Drop every table, then mark the database as the harness's own, and prepare its empty private-file root.
     * This is the only destructive operation, and it runs only after the guard has accepted the target.
     */
    public static function reset(RehearsalTarget $target): void
    {
        TargetGuard::assert($target);

        $schema = Schema::connection($target->connectionName());
        $schema->dropAllTables();

        $schema->create(RehearsalTarget::MARKER_TABLE, function ($table) {
            $table->id();
            $table->string('signature');
            $table->string('role', 16);
            $table->timestamp('created_at')->nullable();
        });

        DB::connection($target->connectionName())->table(RehearsalTarget::MARKER_TABLE)->insert([
            'signature' => RehearsalTarget::SIGNATURE,
            'role' => $target->role,
            'created_at' => now(),
        ]);

        RehearsalFiles::reset($target->filesRoot(), RehearsalFiles::kindFor($target));
    }

    /**
     * Empty a rehearsal database and delete its private files. Leaves the database empty (and so unmarked,
     * which the guard accepts), ready for the next reset. The guard runs first.
     */
    public static function clean(RehearsalTarget $target): void
    {
        TargetGuard::assert($target);

        Schema::connection($target->connectionName())->dropAllTables();
        RehearsalFiles::remove($target->filesRoot(), RehearsalFiles::kindFor($target));
    }

    /**
     * Run the given migration files, by absolute path, against the target. Only {@see MigrationSet} produces
     * these paths.
     *
     * @param  list<string>  $paths
     */
    public static function migrate(RehearsalTarget $target, array $paths): void
    {
        TargetGuard::assert($target);

        $exit = Artisan::call('migrate', [
            '--database' => $target->connectionName(),
            '--path' => $paths,
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException("A rehearsal migration failed:\n".trim(Artisan::output()));
        }
    }

    /**
     * The migrations recorded as run, by name, sorted.
     *
     * @return list<string>
     */
    public static function ran(RehearsalTarget $target): array
    {
        $names = DB::connection($target->connectionName())->table('migrations')->pluck('migration')->all();
        sort($names);

        return $names;
    }
}
