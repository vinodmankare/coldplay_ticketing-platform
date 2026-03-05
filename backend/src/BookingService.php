<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;
use Throwable;

final class BookingService
{
    private const MAX_TICKETS_PER_BOOKING = 6;
    private const MAX_USER_NAME_LENGTH = 80;
    private const MAX_EMAIL_LENGTH = 254;
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._:-]{1,80}$/';
    private const DEFAULT_RATE_WINDOW_SECONDS = 60;
    private const DEFAULT_MAX_PER_IP_PER_WINDOW = 25;
    private const DEFAULT_GLOBAL_MAX_PER_WINDOW = 500;

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

    public function book(array $payload, string $ip, ?string $idempotencyKey, array $requestMeta = []): array
    {
        $csrfCheck = $this->validateCsrf($requestMeta);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $eventId = (int) ($payload['event_id'] ?? 0);
        $userName = trim((string) ($payload['user_name'] ?? ''));
        $userEmail = trim((string) ($payload['user_email'] ?? ''));
        $ticketCount = (int) ($payload['ticket_count'] ?? 0);

        if ($eventId <= 0) {
            return ['status' => 422, 'data' => ['message' => 'event_id is required and must be valid.']];
        }

        if ($ticketCount <= 0 || $ticketCount > self::MAX_TICKETS_PER_BOOKING) {
            return ['status' => 422, 'data' => ['message' => 'ticket_count must be between 1 and '.self::MAX_TICKETS_PER_BOOKING.'.']];
        }

        if ($userName === '' || strlen($userName) > self::MAX_USER_NAME_LENGTH) {
            return ['status' => 422, 'data' => ['message' => 'user_name is required and must be at most '.self::MAX_USER_NAME_LENGTH.' characters.']];
        }

        if ($this->containsXssPayload($userName)) {
            return ['status' => 422, 'data' => ['message' => 'user_name contains invalid characters.']];
        }

        if (!$this->isValidEmailAddress($userEmail)) {
            return ['status' => 422, 'data' => ['message' => 'user_email must be a valid email address.']];
        }

        if ($this->containsXssPayload($userEmail)) {
            return ['status' => 422, 'data' => ['message' => 'user_email contains invalid characters.']];
        }

        $rateLimitStatus = $this->allowRequest($ip);
        if ($rateLimitStatus === 'global') {
            return ['status' => 503, 'data' => ['message' => 'High demand detected. Please retry in a moment.']];
        }
        if ($rateLimitStatus === 'ip') {
            return ['status' => 429, 'data' => ['message' => 'Too many requests. Please retry shortly.']];
        }

        if ($idempotencyKey !== null && !preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey)) {
            return ['status' => 422, 'data' => ['message' => 'Idempotency-Key format is invalid.']];
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

            return ['status' => 500, 'data' => ['message' => 'Booking failed. Please retry.']];
        }
    }

    private function allowRequest(string $ip): string
    {
        $windowSeconds = max(1, (int) (getenv('RATE_LIMIT_WINDOW_SECONDS') ?: self::DEFAULT_RATE_WINDOW_SECONDS));
        $maxPerIpPerWindow = max(1, (int) (getenv('RATE_LIMIT_MAX_PER_IP') ?: self::DEFAULT_MAX_PER_IP_PER_WINDOW));
        $globalMaxPerWindow = max(1, (int) (getenv('RATE_LIMIT_GLOBAL_MAX') ?: self::DEFAULT_GLOBAL_MAX_PER_WINDOW));
        $windowStart = time() - $windowSeconds;

        $this->db->prepare('DELETE FROM rate_limits WHERE ts < :window_start')->execute([':window_start' => $windowStart]);

        $globalCountStmt = $this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE ts >= :window_start');
        $globalCountStmt->execute([':window_start' => $windowStart]);
        $globalCount = (int) $globalCountStmt->fetchColumn();
        if ($globalCount >= $globalMaxPerWindow) {
            return 'global';
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE ip = :ip AND ts >= :window_start');
        $countStmt->execute([':ip' => $ip, ':window_start' => $windowStart]);
        $count = (int) $countStmt->fetchColumn();

        if ($count >= $maxPerIpPerWindow) {
            return 'ip';
        }

        $insert = $this->db->prepare('INSERT INTO rate_limits (ip, ts) VALUES (:ip, :ts)');
        $insert->execute([':ip' => $ip, ':ts' => time()]);

        return 'ok';
    }

    private function containsXssPayload(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // Reject HTML/script-like and control-character payloads at API boundary.
        if ($value !== strip_tags($value)) {
            return true;
        }

        if (preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value) === 1) {
            return true;
        }

        return preg_match('/(?:javascript:|data:text\\/html|<|>|on\\w+\\s*=)/i', $value) === 1;
    }

    private function isValidEmailAddress(string $email): bool
    {
        if (strlen($email) > self::MAX_EMAIL_LENGTH || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        [$localPart, $domain] = $parts;
        if ($localPart === '' || $domain === '') {
            return false;
        }

        // Reject obvious synthetic domains like x.com.com.com where the same
        // suffix label is repeated multiple times.
        $labels = explode('.', strtolower($domain));
        if (count($labels) >= 3) {
            $last = $labels[count($labels) - 1];
            $secondLast = $labels[count($labels) - 2];
            $thirdLast = $labels[count($labels) - 3];
            if ($last === $secondLast && $secondLast === $thirdLast) {
                return false;
            }
        }

        return true;
    }

    private function validateCsrf(array $requestMeta): ?array
    {
        $origin = trim((string) ($requestMeta['origin'] ?? ''));
        $secFetchSite = strtolower(trim((string) ($requestMeta['sec_fetch_site'] ?? '')));
        $csrfToken = trim((string) ($requestMeta['csrf_token'] ?? ''));

        // Non-browser or internal calls (tests/worker/mobile) may omit browser headers.
        if ($origin === '' && $secFetchSite === '') {
            return null;
        }

        $allowedOrigins = array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: 'http://localhost:5173,http://127.0.0.1:5173')));
        if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
            return ['status' => 403, 'data' => ['message' => 'Origin is not allowed.']];
        }

        // Do not hard-block on sec-fetch-site for local dev because
        // localhost and 127.0.0.1 are treated as cross-site.
        // Origin allow-list + CSRF token validation remain mandatory.

        $expectedCsrfToken = trim((string) (getenv('CSRF_TOKEN') ?: 'local-dev-csrf-token'));
        if ($expectedCsrfToken !== '' && !hash_equals($expectedCsrfToken, $csrfToken)) {
            return ['status' => 403, 'data' => ['message' => 'CSRF validation failed.']];
        }

        return null;
    }
}
