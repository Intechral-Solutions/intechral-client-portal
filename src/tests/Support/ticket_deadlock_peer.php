<?php

/*
 * Peer process for TicketAttributionConcurrencyTest (R1-2A remediation T-2). A plain PDO client, no Laravel, so nothing in the application
 * needs a test hook: it simply plays the other half of a REAL InnoDB lock cycle.
 *
 *   php ticket_deadlock_peer.php <deadlock|hold> <requesterUserId> <organizationId> <signalFile>
 *
 * It opens a transaction, makes itself the HEAVIER side of any cycle (ten inserts, so InnoDB picks the other transaction as the victim),
 * locks the requester's `users` row FOR UPDATE (the row a ticket INSERT must share-lock for its foreign key), and prints READY. Then:
 *
 *   deadlock  waits for the signal file to appear (the test writes it; a file, not stdin, because the test process is blocked inside the
 *             database call under test and cannot pump a pipe), then locks the organization row FOR UPDATE. The ticket creation under test holds a shared lock on
 *             that row and waits for the users row: a cycle. It prints ACQUIRED once the creation was rolled back as the victim and the
 *             lock came free, or ERROR <code> if THIS side was chosen. It then rolls back and exits.
 *   hold      only holds, until the signal file appears (or 20 seconds pass), so the creation's INSERT waits and times out (1205).
 *
 * Connects with the DB_* environment the test passes in (the testing database, which TestDatabaseSafety insists on in the parent).
 */

[$mode, $userId, $organizationId, $signal] = [$argv[1] ?? '', (int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (string) ($argv[4] ?? '')];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->beginTransaction();

$insert = $pdo->prepare('insert into password_histories (user_id, password, created_at) values (?, ?, now())');
for ($i = 0; $i < 10; $i++) {
    $insert->execute([$userId, 'peer-weight-'.$i]);
}

$pdo->prepare('select id from users where id = ? for update')->execute([$userId]);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$deadline = microtime(true) + 20;
while (! is_file($signal) && microtime(true) < $deadline) {
    usleep(5_000);
}

if ($mode === 'deadlock' && is_file($signal)) {
    try {
        $pdo->prepare('select id from organizations where id = ? for update')->execute([$organizationId]);
        fwrite(STDOUT, "ACQUIRED\n");
    } catch (PDOException $e) {
        fwrite(STDOUT, 'ERROR '.($e->errorInfo[1] ?? 0)."\n");
    }
    fflush(STDOUT);
}

try {
    $pdo->rollBack();
} catch (PDOException) {
    // The server may already have rolled this transaction back.
}
