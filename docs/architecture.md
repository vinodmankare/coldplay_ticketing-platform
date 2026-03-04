# Architecture

## Components
- API (PHP): booking APIs and inventory transaction boundary.
- SQLite DB: events, bookings, idempotency keys, email outbox.
- Outbox worker: processes queued confirmation emails.
- Web frontend: fetches events and submits booking.

## High-demand strategy (prototype view)
- Single write transaction per booking avoids oversell race.
- Idempotency key protects retries from duplicate booking.
- Rate limiting cuts brute-force spikes.
- Outbox pattern decouples user request from email side effects.

## Scalability roadmap
- API horizontal scaling behind load balancer.
- Postgres with row-level locks or optimistic versioning.
- Redis for idempotency/rate-limit counters.
- Queue + worker pool for confirmations.
- Waiting-room service for flash sale smoothing.
