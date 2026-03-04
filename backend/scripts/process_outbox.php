<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use App\Database;

$pdo = Database::connection();

$rows = $pdo->query("SELECT id, booking_id, to_email, subject, body FROM email_outbox WHERE status = 'pending' ORDER BY id LIMIT 100")->fetchAll();

if (!$rows) {
    echo "No pending emails\n";
    exit(0);
}

$logFile = __DIR__ . '/../storage/email.log';

$update = $pdo->prepare("UPDATE email_outbox SET status = 'sent' WHERE id = :id");
foreach ($rows as $row) {
    $line = sprintf("[%s] to=%s booking=%d subject=\"%s\" body=\"%s\"\n", date(DATE_ATOM), $row['to_email'], $row['booking_id'], $row['subject'], $row['body']);
    file_put_contents($logFile, $line, FILE_APPEND);
    $update->execute([':id' => $row['id']]);
}

echo sprintf("Processed %d emails\n", count($rows));
