<?php

/*
 * Child process for BackupConsistencyTest (WP-B1). Uploads attachments into the PRIMARY REHEARSAL database and its
 * private-file root the way TicketService does, as fast as it can, until <stopFile> exists: inside one transaction it
 * writes the file first, then inserts its row, then commits. Every few uploads it leaves one "in flight" for a moment
 * (file written, row not yet committed). Prints the number of committed uploads.
 *
 * Usage: php backup_upload_worker.php <filesRoot> <stopFile> <ticketId> <userId>
 */

use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $root, $stop, $ticket, $user] = $argv;
$c = RehearsalTarget::primary()->connectionConfig();
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']}", (string) $c['username'], (string) $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$insert = $pdo->prepare("insert into ticket_attachments (ticket_id, reply_id, user_id, filename, path, mime_type, size, created_at, updated_at) values (?, null, ?, 'concurrent.txt', ?, 'text/plain', ?, now(), now())");

$committed = 0;
for ($n = 1; ! file_exists($stop); $n++) {
    $path = "tickets/{$ticket}/wpb1-concurrent-".getmypid()."-{$n}.txt";
    $body = str_repeat("upload {$n}\n", $n % 7 + 1);

    $pdo->beginTransaction();
    file_put_contents($root.'/'.$path, $body);
    chmod($root.'/'.$path, 0600);
    $insert->execute([$ticket, $user, $path, strlen($body)]);
    if ($n % 5 === 0) {
        usleep(30_000);   // in flight: the file exists, the row is not committed
    }
    $pdo->commit();
    $committed++;
    usleep(2_000);
}

echo $committed;
