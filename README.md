# Coldplay Mumbai Ticketing - Functional Prototype

This repository provides a backend-heavy prototype to demonstrate booking strategy under high demand, while keeping developer onboarding simple.

## What is implemented

### Booking Logic (critical path)
- `POST /api/v1/bookings`: transactional booking flow
- Inventory decrement in DB transaction to prevent oversell
- Idempotency support via `Idempotency-Key` header
- Basic abuse control with IP rate limit
- Outbox row creation for confirmation email

### Feedback Loop
- Booking creates a `pending` email task in `email_outbox`
- `php backend/scripts/process_outbox.php` marks queued emails as sent and writes to `backend/storage/email.log`
- Docker setup includes a dedicated `worker` service that continuously processes outbox emails

### Infrastructure
- Dockerized API + worker + static frontend (`docker compose up --build`)
- Zero external DB dependency: SQLite file-backed storage
- One-command DB bootstrap at startup

### Security & Quality
- Input validation and ticket-count limit (`1..6`)
- Idempotency to avoid duplicate payments/bookings on retries
- Basic IP rate limit for brute-force traffic dampening
- Restrictive CORS allow-list + security response headers
- Critical path tests in `backend/tests/run.php`
- CI quality gate on push/PR (`.github/workflows/ci.yml`)

## Assignment Coverage Matrix

| Requirement | Prototype coverage |
|---|---|
| Booking Logic | Transactional `POST /api/v1/bookings` with inventory checks and oversell prevention |
| Feedback Loop | Confirmation email outbox + worker processor |
| Infrastructure | Dockerized API/worker/frontend + simple local run |
| Quality Gate | Local tests + CI syntax/test checks before merge |
| Extensibility | Versioned REST API (`/api/v1`) reusable by web/mobile clients |

## Run locally (without Docker)

### API
```bash
cd backend
php scripts/init_db.php
php -S 127.0.0.1:8080 -t public
```

### Frontend
Serve `frontend` with any static server and open `http://localhost:5173`:
```bash
cd frontend
python -m http.server 5173
```

Alternative using Node.js:
```bash
cd ..
npx serve frontend -l 5173
```

Quick API check before opening frontend:
```bash
curl http://127.0.0.1:8080/health
```

## Troubleshooting

- API health check fails (`Unable to connect to the remote server`)
  - Ensure API terminal is running and left open:
  - `cd backend`
  - `php scripts/init_db.php`
  - `php -S 127.0.0.1:8080 -t public`

- PowerShell `curl` prompt (`Security Warning: Script Execution Risk`)
  - PowerShell maps `curl` to `Invoke-WebRequest`.
  - Use:
  - `curl.exe http://127.0.0.1:8080/health`
  - or `Invoke-RestMethod http://127.0.0.1:8080/health`

- Frontend shows `Failed to load events`
  - Confirm API is reachable at `http://127.0.0.1:8080/health`.
  - Keep frontend and API in separate terminals.

- `npx serve` returns `404 /`
  - If you are already in `frontend`, run:
  - `npx serve . -l 5173`
  - If you are in repo root, run:
  - `npx serve frontend -l 5173`

## Run with Docker
```bash
docker compose up --build
```

- API: `http://localhost:8080`
- Frontend: `http://localhost:5173`
- Worker: background outbox processor (`coldplay-worker`)

## API contracts

### `GET /api/v1/events`
Returns event inventory snapshot.

### `POST /api/v1/bookings`
Headers:
- `Content-Type: application/json`
- `Idempotency-Key: <unique-value>` (recommended)

Body:
```json
{
  "event_id": 1,
  "user_name": "Vinod",
  "user_email": "vinod@example.com",
  "ticket_count": 2
}
```

Success:
```json
{
  "booking_id": 101,
  "event_id": 1,
  "event_name": "Coldplay: Music of the Spheres",
  "tickets_booked": 2,
  "booking_status": "confirmed",
  "remaining_tickets": 149998
}
```

## Tests
```bash
cd backend
php tests/run.php
```

Covers:
- successful booking and inventory decrement
- idempotent replay with same key
- insufficient inventory conflict
- invalid high ticket quantity rejection
- burst traffic rate limit behavior
- email outbox entry creation

## Tradeoffs
See `TRADEOFFS.md` for explicit priority decisions.
