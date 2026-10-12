<?php

/*
|--------------------------------------------------------------------------
| Release 0.9 migration rehearsal: the reviewed candidate migrations
|--------------------------------------------------------------------------
| The rehearsal harness (App\Support\Rehearsal, `php artisan rehearsal:run`) builds its baseline from every
| migration in database/migrations EXCEPT the ones named here, seeds records, snapshots them, applies the
| candidates the operator selects with --candidate=<name>, snapshots again, and reports any difference it
| was not told to expect. It runs ONLY migrations that are listed here, are present in
| database/migrations, and whose file still has the recorded SHA-256, so what is rehearsed is exactly what
| was reviewed. Nothing else (no path, no other PHP file) is ever executed by it.
|
| An entry is added in the same change that adds the migration. Shape:
|
|   '2026_10_12_000001_example_migration' => [
|       'summary' => 'One sentence a reviewer can check the change against.',
|       'sha256'  => hash('sha256', file contents of database/migrations/<name>.php),
|       // Every structural change the migration is MEANT to make, in the harness's notation. The rehearsal
|       // fails if the observed schema difference is not exactly this list.
|       'schema'  => ['column+:users.example', 'fk~:tickets.user_id->users.id:CASCADE>RESTRICT'],
|       // Tables whose existing rows the migration is MEANT to change (for example a backfill). Empty for
|       // an additive or tightening migration.
|       'data'    => [],
|   ],
|
| Notation: table+:t · table-:t · column+:t.c · column-:t.c · column~:t.c (type or nullability changed) ·
| fk+:t.c->p.c:RULE · fk-:t.c->p.c:RULE · fk~:t.c->p.c:OLD>NEW · unique+:t(a,b) · unique-:t(a,b).
|
| EMPTY at R0.9-1A: the harness is established before any candidate exists. The H1/H2/H3 migrations of
| R0.9-1B are listed here when they are written. Rehearsing with no candidate proves the baseline, the
| snapshot and the backup/restore round trip. It proves nothing about any future migration.
*/

return [];
