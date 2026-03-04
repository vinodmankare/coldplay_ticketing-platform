<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/BookingService.php';

use App\BookingService;
use App\Database;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Idempotency-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pdo = Database::connection();
$service = new BookingService($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'GET' && $uri === '/health') {
    respond(200, ['status' => 'ok']);
}

if ($method === 'GET' && $uri === '/api/v1/events') {
    respond(200, ['data' => $service->listEvents()]);
}

if ($method === 'GET' && preg_match('#^/api/v1/bookings/(\d+)$#', $uri, $matches) === 1) {
    $booking = $service->getBooking((int) $matches[1]);
    if (!$booking) {
        respond(404, ['message' => 'Booking not found.']);
    }
    respond(200, ['data' => $booking]);
}

if ($method === 'POST' && $uri === '/api/v1/bookings') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    if (!is_array($payload)) {
        respond(422, ['message' => 'Invalid JSON payload.']);
    }

    $idem = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $result = $service->book($payload, $ip, $idem ?: null);

    respond($result['status'], $result['data']);
}

respond(404, ['message' => 'Route not found.']);
