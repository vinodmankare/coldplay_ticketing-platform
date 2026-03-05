<?php

declare(strict_types=1);

require_once __DIR__.'/process_outbox.php';

$lockFile = __DIR__.'/../storage/worker.lock';
$lockHandle = fopen($lockFile, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "Failed to open worker lock file.\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Worker already running. Exiting.\n");
    exit(0);
}

$batchSize = (int) (getenv('WORKER_BATCH_SIZE') ?: 100);
$idleSleep = max(1, (int) (getenv('WORKER_IDLE_SLEEP_SECONDS') ?: 2));
$busySleep = max(1, (int) (getenv('WORKER_BUSY_SLEEP_SECONDS') ?: 1));

while (true) {
    $processed = processOutboxBatch($batchSize);
    sleep($processed > 0 ? $busySleep : $idleSleep);
}
