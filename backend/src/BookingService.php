<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;
use Throwable;

final class BookingService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function listEvents(): array
    {
        return $this->db->query('SELECT id, name, venue, event_date, available_tickets, total_tickets, price_cents FROM events ORDER BY event_date')->fetchAll();
    }

    public function getBooking(int $bookingId): ?array
    {
        $stmt = $this->db->prepare('SELECT b.id, b.event_id, b.user_name, b.user_email, b.ticket_count, b.status, b.created_at, e.name AS event_name, e.venue, e.event_date FROM bookings b JOIN events e ON e.id = b.event_id WHERE b.id = :id');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch();

        return $booking ?: null;
    }

    public function book(array $payload, string $ip, ?string $idempotencyKey): array
    {
        $eventId = (int) ($payload['event_id'] ?? 0);
        $userName = trim((string) ($payload['user_name'] ?? ''));
        $userEmail = trim((string) ($payload['user_email'] ?? ''));
        $ticketCount = (int) ($payload['ticket_count'] ?? 0);

        if ($eventId <= 0 || $ticketCount <= 0 || $ticketCount > 6 || $userName === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 422, 'data' => ['message' => 'Invalid input.']];
        }

        if (!$this->allowRequest($ip)) {
            return ['status' => 429, 'data' => ['message' => 'Too many requests. Please retry shortly.']];
        }

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 80) {
            return ['status' => 422, 'data' => ['message' => 'Idempotency-Key is too long.']];
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE TRANSACTION');

            if ($idempotencyKey) {
                $existing = $this->db->prepare('SELECT response_json FROM idempotency_keys WHERE idempotency_key = :key');
                $existing->execute([':key' => $idempotencyKey]);
                $stored = $existing->fetchColumn();
                if (is_string($stored) && $stored !== '') {
                    $this->db->exec('COMMIT');
                    $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
                    $decoded['replayed'] = true;

                    return ['status' => 200, 'data' => $decoded];
                }
            }

            $eventStmt = $this->db->prepare('SELECT id, name, venue, event_date, available_tickets, price_cents FROM events WHERE id = :id');
            $eventStmt->execute([':id' => $eventId]);
            $event = $eventStmt->fetch();

            if (!$event) {
                $this->db->exec('ROLLBACK');

                return ['status' => 404, 'data' => ['message' => 'Event not found.']];
            }

            if ((int) $event['available_tickets'] < $ticketCount) {
                $this->db->exec('ROLLBACK');

                return ['status' => 409, 'data' => ['message' => 'Not enough tickets available.']];
            }

            $updateEvent = $this->db->prepare('UPDATE events SET available_tickets = available_tickets - :count WHERE id = :id');
            $updateEvent->execute([':count' => $ticketCount, ':id' => $eventId]);

            $now = (new DateTimeImmutable())->format(DATE_ATOM);
            $insertBooking = $this->db->prepare('INSERT INTO bookings (event_id, user_name, user_email, ticket_count, status, idempotency_key, created_at) VALUES (:event_id, :name, :email, :count, :status, :key, :created_at)');
            $insertBooking->execute([
                ':event_id' => $eventId,
                ':name' => $userName,
                ':email' => $userEmail,
                ':count' => $ticketCount,
                ':status' => 'confirmed',
                ':key' => $idempotencyKey,
                ':created_at' => $now,
            ]);

            $bookingId = (int) $this->db->lastInsertId();

            $subject = 'Booking Confirmed - Coldplay Mumbai';
            $body = sprintf('Hi %s, your booking #%d for %s (%d tickets) is confirmed.', $userName, $bookingId, $event['name'], $ticketCount);
            $insertOutbox = $this->db->prepare('INSERT INTO email_outbox (booking_id, to_email, subject, body, status, created_at) VALUES (:booking_id, :email, :subject, :body, :status, :created_at)');
            $insertOutbox->execute([
                ':booking_id' => $bookingId,
                ':email' => $userEmail,
                ':subject' => $subject,
                ':body' => $body,
                ':status' => 'pending',
                ':created_at' => $now,
            ]);

            $response = [
                'booking_id' => $bookingId,
                'event_id' => $eventId,
                'event_name' => $event['name'],
                'tickets_booked' => $ticketCount,
                'booking_status' => 'confirmed',
                'remaining_tickets' => ((int) $event['available_tickets']) - $ticketCount,
            ];

            if ($idempotencyKey) {
                $insertKey = $this->db->prepare('INSERT INTO idempotency_keys (idempotency_key, booking_id, response_json, created_at) VALUES (:key, :booking_id, :response_json, :created_at)');
                $insertKey->execute([
                    ':key' => $idempotencyKey,
                    ':booking_id' => $bookingId,
                    ':response_json' => json_encode($response, JSON_THROW_ON_ERROR),
                    ':created_at' => $now,
                ]);
            }

            $this->db->exec('COMMIT');

            return ['status' => 201, 'data' => $response];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }

            return ['status' => 500, 'data' => ['message' => 'Booking failed.', 'error' => $e->getMessage()]];
        }
    }

    private function allowRequest(string $ip): bool
    {
        $windowSeconds = 60;
        $maxPerWindow = 25;
        $windowStart = time() - $windowSeconds;

        $this->db->prepare('DELETE FROM rate_limits WHERE ts < :window_start')->execute([':window_start' => $windowStart]);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE ip = :ip AND ts >= :window_start');
        $countStmt->execute([':ip' => $ip, ':window_start' => $windowStart]);
        $count = (int) $countStmt->fetchColumn();

        if ($count >= $maxPerWindow) {
            return false;
        }

        $insert = $this->db->prepare('INSERT INTO rate_limits (ip, ts) VALUES (:ip, :ts)');
        $insert->execute([':ip' => $ip, ':ts' => time()]);

        return true;
    }
}
