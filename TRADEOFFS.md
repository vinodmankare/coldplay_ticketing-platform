# Tradeoffs & Prioritization

## Priorities chosen
1. Correct booking semantics under load spikes.
2. Easy developer setup.
3. Clear API contracts for web/mobile reuse.
4. Minimal but meaningful quality gates.

## Intentional choices
- SQLite for prototype speed: faster onboarding, not final scale choice.
- Synchronous booking + async outbox: keeps UX fast while decoupling email.
- Basic rate limit: enough for prototype abuse damping, replace with Redis or edge rate limiting in production.
- Max 6 tickets per request: prevents one-call hoarding and limits contention.

## What is productionized later
- Replace SQLite with Postgres + Redis.
- Add distributed queue/waiting room and reservation TTL.
- Stronger bot defense (device fingerprint, captcha, risk scoring).
- Payment orchestration with retry-safe webhooks.
- Observability stack (metrics, tracing, SLO dashboards).
- Blue/green deploy and chaos validation.
