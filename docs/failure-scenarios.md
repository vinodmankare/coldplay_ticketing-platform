# Failure Scenarios

## 1) Sudden demand spike
- Symptom: API saturation, high tail latency.
- Mitigation: waiting room, edge throttles, autoscale API pods.

## 2) Duplicate client retries
- Symptom: accidental double booking/payment.
- Mitigation: idempotency key replay semantics.

## 3) Inventory race conditions
- Symptom: oversold seats.
- Mitigation: transaction boundary around read-modify-write.

## 4) Email provider outage
- Symptom: booking succeeds, email delayed.
- Mitigation: outbox queue + retry worker.

## 5) DB write pressure
- Symptom: lock contention and timeout.
- Mitigation: short transactions, strict validation before lock, queueing gate.
