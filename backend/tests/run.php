<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/BookingService.php';

use App\BookingService;
use App\Database;

$testDb = __DIR__ . '/test.sqlite';
if (file_exists($testDb)) {
    unlink($testDb);
}
putenv('DB_PATH=' . $testDb);

require __DIR__ . '/../scripts/init_db.php';

$pdo = Database::connection($testDb);
$service = new BookingService($pdo);

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$ok = $service->book([
    'event_id' => 1,
    'user_name' => 'Test User',
    'user_email' => 'test@example.com',
    'ticket_count' => 2,
], '127.0.0.1', 'idem-a-1');

assertTrue($ok['status'] === 201, 'Booking should succeed');
assertTrue(isset($ok['data']['booking_id']), 'Booking id should exist');

$eventAfter = $pdo->query('SELECT available_tickets FROM events WHERE id = 1')->fetchColumn();
assertTrue((int) $eventAfter === 149998, 'Available tickets should decrease after successful booking');

$idemReplay = $service->book([
    'event_id' => 1,
    'user_name' => 'Test User',
    'user_email' => 'test@example.com',
    'ticket_count' => 2,
], '127.0.0.1', 'idem-a-1');

assertTrue($idemReplay['status'] === 200, 'Repeated idempotency key should replay');
assertTrue(($idemReplay['data']['booking_id'] ?? 0) === $ok['data']['booking_id'], 'Idempotent replay should return same booking id');

$overbook = $service->book([
    'event_id' => 1,
    'user_name' => 'Big Buyer',
    'user_email' => 'big@example.com',
    'ticket_count' => 200000,
], '127.0.0.1', 'idem-overbook');

assertTrue($overbook['status'] === 422, 'Input guard should block unrealistic ticket_count > 6');

$outboxCount = $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE booking_id = {$ok['data']['booking_id']}")->fetchColumn();
assertTrue((int) $outboxCount === 1, 'Booking should enqueue one confirmation email');

$pdo->exec('UPDATE events SET available_tickets = 1 WHERE id = 2');
$soldOut = $service->book([
    'event_id' => 2,
    'user_name' => 'Late User',
    'user_email' => 'late@example.com',
    'ticket_count' => 2,
], '127.0.0.1', 'idem-soldout');
assertTrue($soldOut['status'] === 409, 'Should return conflict when inventory is insufficient');

$rateLimitHit = null;
for ($i = 0; $i < 40; $i++) {
    $result = $service->book([
        'event_id' => 1,
        'user_name' => 'Burst User '.$i,
        'user_email' => "burst{$i}@example.com",
        'ticket_count' => 1,
    ], '10.10.10.10', 'idem-rate-'.$i);
    if ($result['status'] === 429) {
        $rateLimitHit = $result;
        break;
    }
}
assertTrue(is_array($rateLimitHit) && $rateLimitHit['status'] === 429, 'Rate limit should trigger for burst traffic');

echo "All critical booking tests passed.\n";
