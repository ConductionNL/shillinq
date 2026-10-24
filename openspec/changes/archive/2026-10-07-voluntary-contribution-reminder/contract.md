# Contract: voluntary-contribution-reminder

## Consumers

- `portaliq`: forwards the parent manifest's `decline` action server to server,
  with the signed `X-Portal-Subject` assertion, exactly like the `pay` action.
- `learniq`, `portaliq` (as owning apps of the chargeable): read the result, a
  `voided` payment request and an `ARInvoice.lifecycleState` of `declined`. No new
  call.

## Endpoints

### `POST /apps/shillinq/api/portal/contributions/decline`

**Auth**: `#[PublicPage]`, the `X-Portal-Subject` assertion verified by
`PortalAssertionVerifier` is the only credential. Audience `parent` or `customer`.
Rate limited to 20 calls per minute per client.

**Request:**
```json
{ "invoiceId": "00000000-0000-0000-0000-000000000000" }
```

**Response (200):**
```json
{ "status": "declined" }
```

A repeat call on an invoice the same guardian already declined returns the same
200 and writes nothing.

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | The assertion is missing or does not verify |
| 403  | Wrong audience; or the invoice is not the guardian's, not a voluntary contribution, not open (`issued` or `overdue`), missing, or the id is malformed. One body for all of these |
| 502  | OpenRegister failed while reading or saving |

### Portal manifest action (parent audience)

```json
{
  "id": "decline",
  "label": "I will not pay",
  "type": "endpoint-forward",
  "endpoint": "/apps/shillinq/api/portal/contributions/decline",
  "method": "POST",
  "fields": ["invoiceId"],
  "minTrust": "low"
}
```

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 401 | `unauthorized` | No verified assertion |
| 403 | `forbidden` | Anything that is not an open voluntary contribution owned by the guardian |
| 502 | `downstream_error` | OpenRegister unavailable or a save failed |

## Versioning

Additive. The `pay` action and its receiver are unchanged. The parent manifest
gains one action.

## Breaking Change Policy

A change to the request body or the status values is announced in portaliq's
contribution contract and shipped behind a new action id.

## SLA

Two OpenRegister reads and at most three saves per call; well under one second on
a normal instance.
