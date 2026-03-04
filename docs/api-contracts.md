# API Contracts

## GET /api/v1/events
Response:
```json
{
  "data": [
    {
      "id": 1,
      "name": "Coldplay: Music of the Spheres",
      "venue": "DY Patil Stadium, Mumbai",
      "event_date": "2026-10-15T19:00:00+05:30",
      "available_tickets": 150000,
      "total_tickets": 150000,
      "price_cents": 850000
    }
  ]
}
```

## POST /api/v1/bookings
Headers:
- `Idempotency-Key` (optional but strongly recommended)

Request:
```json
{
  "event_id": 1,
  "user_name": "Aryan",
  "user_email": "aryan@example.com",
  "ticket_count": 2
}
```

Responses:
- `201` confirmed booking
- `200` idempotent replay
- `409` insufficient inventory
- `422` validation error
- `429` rate-limited

## GET /api/v1/bookings/{id}
Returns booking details with event metadata.
