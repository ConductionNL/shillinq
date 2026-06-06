---
title: Booking Self-service Widget — API reference
---

The Widget API is mounted under `/index.php/apps/shillinq/api/widget/` and
requires `Authorization: Bearer <api_key>` plus `?businessId=<id>` on every
request (REQ-WSW-001). Rate-limit: 100 req/min per business by default
(configurable per `WidgetAccessKey`).

## GET /api/widget/services

Returns the active services available for booking. Public-safe subset only —
no internal fields or PII.

### Query parameters

| Name        | Required | Description                                  |
| ----------- | -------- | -------------------------------------------- |
| `businessId`| yes      | Partner business id, matches the access key. |

### Response — 200 OK

```json
{
  "services": [
    {
      "serviceId": "svc-001",
      "name": "Intake consultation",
      "duration": 30,
      "price": 75.0,
      "currency": "EUR",
      "description": "30-minute introductory consultation."
    }
  ]
}
```

### Errors

| Status | Body                                       | Cause                              |
| ------ | ------------------------------------------ | ---------------------------------- |
| 401    | `{error: "unauthorised", message: "..."}`  | Missing or invalid bearer token.   |
| 429    | `{error: "rate-limited", message: "..."}`  | More than 100 req/min for the key. |
| 500    | `{error: "server-error", message: "..."}`  | Service catalogue unavailable.     |

## GET /api/widget/slots

Returns the available appointment slots for a (service, resource, date) tuple.

### Query parameters

| Name        | Required | Description                                   |
| ----------- | -------- | --------------------------------------------- |
| `businessId`| yes      | Partner business id.                          |
| `serviceId` | yes      | Logical service id.                           |
| `resourceId`| yes      | Logical resource id.                          |
| `date`      | yes      | Calendar date, ISO `YYYY-MM-DD` UTC.          |

### Caching

The slot list is cached for 5 minutes per (service, resource, date). The
response includes an `ETag` header; partners that send `If-None-Match` with
the previous ETag receive `304 Not Modified` when the cached list is unchanged.

### Response — 200 OK

```json
{
  "date": "2026-05-22",
  "slots": [
    { "startTime": "2026-05-22T09:00:00Z", "endTime": "2026-05-22T09:30:00Z" },
    { "startTime": "2026-05-22T09:30:00Z", "endTime": "2026-05-22T10:00:00Z" }
  ],
  "cached": false
}
```

## POST /api/widget/appointments

Creates an appointment from the widget. Returns 201 Created with the booking
id and a confirmation message. Customer details are accepted on input but
never echoed back; the appointment record stores an anonymous hash of the
email so admins can deduplicate self-service bookings (design D6).

### Request body

```json
{
  "serviceId": "svc-001",
  "resourceId": "res-001",
  "startTime": "2026-05-22T09:00:00Z",
  "endTime": "2026-05-22T09:30:00Z",
  "customerName": "Alice Smith",
  "email": "alice@example.com",
  "phone": "+31612345678",
  "notes": "Wheelchair access required."
}
```

### Response — 201 Created

```json
{
  "appointmentId": "apt-1a2b3c4d5e6f7080",
  "status": "pending_confirmation",
  "confirmationMessage": "Your appointment was created. You will receive a confirmation email shortly."
}
```

### Errors

| Status | Body                                       | Cause                                  |
| ------ | ------------------------------------------ | -------------------------------------- |
| 400    | `{error: "bad-request", message: "..."}`   | Validation failure (REQ-WSW-006).      |
| 401    | `{error: "unauthorised", message: "..."}`  | Missing or invalid bearer token.       |
| 409    | `{error: "slot-unavailable", message: ...}`| Slot was just booked by someone else.  |
| 429    | `{error: "rate-limited", message: "..."}`  | More than 100 req/min for the key.     |
| 500    | `{error: "server-error", message: "..."}`  | Booking persistence failed.            |
