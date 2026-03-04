<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use App\Database;

$pdo = Database::connection();

$pdo->exec('CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    venue TEXT NOT NULL,
    event_date TEXT NOT NULL,
    total_tickets INTEGER NOT NULL,
    available_tickets INTEGER NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT NOT NULL
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    user_name TEXT NOT NULL,
    user_email TEXT NOT NULL,
    ticket_count INTEGER NOT NULL,
    status TEXT NOT NULL,
    idempotency_key TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (event_id) REFERENCES events(id)
)');

$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bookings_idempotency_key ON bookings(idempotency_key)');

$pdo->exec('CREATE TABLE IF NOT EXISTS email_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    to_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    ts INTEGER NOT NULL
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS idempotency_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    booking_id INTEGER NOT NULL,
    response_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
)');

$existingEvents = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
if ($existingEvents === 0) {
    $stmt = $pdo->prepare('INSERT INTO events (name, venue, event_date, total_tickets, available_tickets, price_cents, created_at) VALUES (:name, :venue, :event_date, :total_tickets, :available_tickets, :price_cents, :created_at)');

    $events = [
        ['Coldplay: Music of the Spheres', 'DY Patil Stadium, Mumbai', '2026-10-15T19:00:00+05:30', 150000, 150000, 850000],
        ['Coldplay: Music of the Spheres - Day 2', 'DY Patil Stadium, Mumbai', '2026-10-16T19:00:00+05:30', 150000, 150000, 850000],
    ];

    foreach ($events as [$name, $venue, $eventDate, $total, $available, $price]) {
        $stmt->execute([
            ':name' => $name,
            ':venue' => $venue,
            ':event_date' => $eventDate,
            ':total_tickets' => $total,
            ':available_tickets' => $available,
            ':price_cents' => $price,
            ':created_at' => date(DATE_ATOM),
        ]);
    }
}

echo "Database initialized\n";
