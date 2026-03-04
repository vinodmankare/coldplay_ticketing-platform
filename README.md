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

### Infrastructure
- Dockerized API + static frontend (`docker-compose up --build`)
- Zero external DB dependency: SQLite file-backed storage
- One-command DB bootstrap at startup

### Security & Quality
- Input validation and ticket-count limit (`1..6`)
- Idempotency to avoid duplicate payments/bookings on retries
- Basic IP rate limit for brute-force traffic dampening
- Critical path tests in `backend/tests/run.php`

## Run locally (without Docker)

### API
```bash
cd backend
php scripts/init_db.php
php -S 0.0.0.0:8080 -t public
```

### Frontend
Serve `frontend` with any static server and open `http://localhost:5173`:
```bash
cd frontend
python -m http.server 5173
```

## Run with Docker
```bash
docker compose up --build
```

- API: `http://localhost:8080`
- Frontend: `http://localhost:5173`

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
  "user_name": "Aryan",
  "user_email": "aryan@example.com",
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
- invalid high ticket quantity rejection
- email outbox entry creation

## Process & Thought (suggested commit sequence)
Use sequential commits to show design evolution:
1. `chore: scaffold php booking service and sqlite schema`
2. `feat: implement transactional booking with idempotency`
3. `feat: add frontend booking flow`
4. `feat: dockerize api and frontend`
5. `test: add critical-path booking tests`
6. `docs: add tradeoffs and architecture notes`

## Tradeoffs
See `TRADEOFFS.md` for explicit priority decisions.
