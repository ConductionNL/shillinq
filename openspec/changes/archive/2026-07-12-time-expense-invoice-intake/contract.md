# Contract: time-expense-invoice-intake

## Consumers

- `pipelinq`: consumes this endpoint from its `time-billing-handoff-emit`
  change. Pipelinq emits a batch of approved time entries (and optionally
  expenses) for a client and receives back a shillinq draft-invoice
  reference to display/link on the pipelinq side. Pipelinq stores the
  returned `invoiceId` against its project so the handoff is traceable and
  re-emit is idempotent.

## Endpoints

### `POST /apps/shillinq/api/billing/time-intake`

**Auth**: Nextcloud session (`#[NoAdminRequired]`, authenticated non-guest
user required — no `#[PublicPage]`). The administration (tenant) scope is
resolved server-side from the session; a client-supplied `administrationId`
is ignored. Per-object authorization per ADR-005.

**Request:**
```json
{
  "batchId": "00000000-0000-0000-0000-000000000000",
  "organisationRef": "cust-5432",
  "currency": "EUR",
  "billingModel": "t_and_m",
  "period": { "start": "2026-06-01", "end": "2026-06-30" },
  "rateCardId": null,
  "projectRef": "proj-widget-api",
  "notes": "June retainer overflow — approved 2026-07-01",
  "entries": [
    {
      "externalId": "pl-time-1001",
      "date": "2026-06-03",
      "minutes": 120,
      "description": "API design review",
      "hourlyRate": 150.0,
      "rateRef": null,
      "projectRef": "proj-widget-api"
    },
    {
      "externalId": "pl-time-1002",
      "date": "2026-06-05",
      "minutes": 90,
      "description": "Integration spike",
      "hourlyRate": 100.0,
      "rateRef": null,
      "projectRef": "proj-widget-api"
    }
  ],
  "expenses": []
}
```

**Response (200) — new batch:**
```json
{
  "invoiceId": "bil-2026-0042",
  "invoiceNumber": "BIL-2026-0042",
  "status": "draft",
  "lines": 2,
  "duplicated": false
}
```

**Response (200) — replayed batch (same `batchId`):**
```json
{
  "invoiceId": "bil-2026-0042",
  "invoiceNumber": "BIL-2026-0042",
  "status": "draft",
  "lines": 2,
  "duplicated": true
}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | Malformed body, missing `batchId`, empty `entries`, or unparseable dates |
| 401  | No authenticated user (not logged in) |
| 409  | `batchId` reused with a **different** payload than the stored batch |
| 422  | Validation failure: `billingModel` != `t_and_m`, unresolvable `rateRef` with no `hourlyRate`, non-positive `minutes`, or a duplicate `externalId` already invoiced under a different batch |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad Request | Body not JSON, `batchId`/`organisationRef`/`entries` missing, `entries` empty |
| 401  | Unauthorized | Session has no non-guest user |
| 409  | Conflict | Same `batchId`, materially different payload (idempotency violation) |
| 422  | Unprocessable Entity | Semantic validation failed (non-T&M model, bad rate, bad minutes, cross-batch duplicate `externalId`) |
| 500  | Internal Error | Unexpected server-side failure (logged; no partial invoice left visible) |

## Versioning

- Path is unversioned under `/apps/shillinq/api/billing/` to match the
  billing-ingress convention; the response is additive-only. New optional
  response fields MAY be added without a version bump. Consumers MUST ignore
  unknown fields.
- The existing `/api/v1/invoices/*` operator API is unaffected and keeps its
  `v1` prefix.

## Breaking Change Policy

- Any change that removes or renames a request/response field, or tightens
  validation in a way that rejects a previously-accepted payload, is
  breaking. It requires a coordinated edit to the pipelinq
  `time-billing-handoff-emit` change and a joint sign-off before merge.
- The frozen contract lives in this file; the pipelinq change references it
  by name. Non-breaking additions are announced in the change's proposal.

## SLA

- Synchronous drafting for a typical batch (≤200 entries): p95 < 2s.
- Idempotent replay (batch already processed): p95 < 300ms (single lookup,
  no invoice generation).
- Availability tracks the shillinq app itself; no separate SLA. The endpoint
  is safe to retry — idempotency guarantees at-most-one invoice per
  `batchId`.
