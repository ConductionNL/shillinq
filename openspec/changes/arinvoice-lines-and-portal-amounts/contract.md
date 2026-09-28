# Contract: arinvoice-lines-and-portal-amounts

## Consumers

- `portaliq`: renders the customer manifest's `salesInvoices` and the new
  `requestPayments` collection, and forwards the `pay` row action server to
  server with the signed `X-Portal-Subject` assertion.

## Endpoints

### `POST /apps/shillinq/api/portal/payments/initiate` (extended)

**Auth**: `#[PublicPage]`; the verified `X-Portal-Subject` assertion is the only
credential; audience `customer` or `parent`. Unchanged.

**Request, an invoice row (unchanged):**
```json
{ "invoiceId": "00000000-0000-0000-0000-000000000000" }
```

**Request, a `requestPayments` row (new):**
```json
{ "paymentRequestId": "00000000-0000-0000-0000-000000000000" }
```

`invoiceId` wins when both are sent.

**Response (200):**
```json
{ "checkoutUrl": "https://checkout.example/<session>" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | The assertion is missing or does not verify |
| 403  | Wrong audience; or the target is not the subject's, not payable, missing or malformed. For a payment request also: an invoice stands behind it, or it is not `pending`. One body for all |
| 502  | OpenRegister or the provider failed, or the request carries no chargeable amount |
| 503  | `{status: deferred}`: the provider is dormant |

### Customer manifest collection `requestPayments` (new)

```json
{
  "id": "requestPayments",
  "register": "shillinq",
  "schema": "PaymentRequest",
  "scopeField": "customerId",
  "scopeClaim": "customerMasterId",
  "label": "My payment requests",
  "listable": true,
  "rowAction": "pay",
  "fields": ["description", "requestType", "amount", "currency", "state", "dueAt", "legalBasis", "paymentLink", "capturedAt", "failureReason", "confirmationSummary"]
}
```

### Customer manifest `salesInvoices` fields (corrected)

`invoiceNumber`, `invoiceType`, `invoiceDate`, `dueDate`, `currency`,
`grossAmount`, `vatAmount`, `invoiceLines`, `lifecycleState`,
`sourceDocumentUri`, `ublRef`, `dunning`. Previously `totalAmount`, `taxAmount`,
`lines`, `state` and `ublXml`, none of which `ARInvoice` declares.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 401 | `unauthorized` | No verified assertion |
| 403 | `forbidden` | Not the subject's payable invoice or pending request-only payment request |
| 502 | `downstream_error` | OpenRegister or provider failure, or no chargeable amount |
| 503 | `deferred` | Provider dormant |

## Versioning

Additive for the endpoint (`paymentRequestId` is new, `invoiceId` unchanged) and
for the manifest (one new collection). The `salesInvoices` field names change,
but the old names never carried a value.

## Breaking Change Policy

A change to the request body or the collection ids is announced in portaliq's
contribution contract before it ships.

## SLA

Unchanged from the invoice path: two OpenRegister reads, one provider call, one save.
