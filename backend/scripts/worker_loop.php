<?php

declare(strict_types=1);

require_once __DIR__.'/process_outbox.php';

while (true) {
    require __DIR__.'/process_outbox.php';
    sleep(2);
}

