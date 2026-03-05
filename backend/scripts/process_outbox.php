<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use App\Database;

function processOutboxBatch(int $limit = 100): int
{
    $pdo = Database::connection();
    $limit = max(1, min($limit, 500));

    $stmt = $pdo->prepare("SELECT id, booking_id, to_email, subject, body FROM email_outbox WHERE status = 'pending' ORDER BY id LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return 0;
    }

    $markSent = $pdo->prepare("UPDATE email_outbox SET status = 'sent' WHERE id = :id");
    $markFailed = $pdo->prepare("UPDATE email_outbox SET status = 'failed' WHERE id = :id");

    $processed = 0;
    foreach ($rows as $row) {
        $sent = sendConfirmationEmail(
            (string) $row['to_email'],
            (string) $row['subject'],
            (string) $row['body'],
            (int) $row['booking_id']
        );

        if ($sent) {
            $markSent->execute([':id' => $row['id']]);
            $processed++;
        } else {
            $markFailed->execute([':id' => $row['id']]);
        }
    }

    return $processed;
}

function sendConfirmationEmail(string $to, string $subject, string $body, int $bookingId): bool
{
    $transport = strtolower(trim((string) (getenv('MAIL_TRANSPORT') ?: 'log')));
    $from = trim((string) (getenv('MAIL_FROM') ?: 'no-reply@coldplay.local'));
    $logFile = __DIR__ . '/../storage/email.log';

    $line = sprintf("[%s] to=%s booking=%d subject=\"%s\" body=\"%s\"\n", date(DATE_ATOM), $to, $bookingId, $subject, $body);

    // Default, deterministic transport for local/dev/demo.
    if ($transport === 'log') {
        file_put_contents($logFile, $line, FILE_APPEND);
        return true;
    }

    // Attempt real email dispatch when configured.
    if ($transport === 'mail') {
        $headers = [
            'From: '.$from,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Booking-Id: '.$bookingId,
        ];
        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        if ($ok) {
            return true;
        }
    }

    $line = '[FALLBACK-LOG] '.$line;
    file_put_contents($logFile, $line, FILE_APPEND);

    return $transport !== 'mail';
}

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    $batchSize = (int) (getenv('WORKER_BATCH_SIZE') ?: 100);
    $processed = processOutboxBatch($batchSize);
    if ($processed === 0) {
        echo "No pending emails\n";
        exit(0);
    }
    echo sprintf("Processed %d emails\n", $processed);
}
