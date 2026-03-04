# Capacity Planning

## Assumptions
- Peak concurrent visitors: 1,500,000
- Booking attempts in burst minute: 250,000
- Sustained booking API RPS target: 4,000
- Max tickets/request: 6

## Hot path budget
- `POST /bookings` p95 target: < 250 ms (excluding payment gateway in full system)
- Read inventory p95 target: < 100 ms

## Scaling plan
- Stateless API replicas behind LB.
- Redis for request shaping and idempotency lookup.
- Primary DB for writes + read replicas for catalog.
- Queue workers autoscaled on outbox depth.

## Guardrails
- Queueing gate when API or DB saturation is detected.
- Per-user and per-IP booking limits.
- Circuit breakers for non-critical downstreams.
