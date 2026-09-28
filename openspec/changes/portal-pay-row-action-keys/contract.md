# Contract: portal-pay-row-action-keys

## Consumers

- `portaliq` (#805): reads `rowField`, `rowWhen` and `noticeField` from the
  manifest and forwards `{invoiceId: <proven row id>}` to the pay endpoint.
- Shillinq's admin settings page: reads and writes the return address.

## Endpoints

### Manifest action `pay` (keys added)

```json
{
  "id": "pay",
  "label": "Pay now",
  "type": "endpoint-forward",
  "endpoint": "/apps/shillinq/api/portal/payments/initiate",
  "method": "POST",
  "minTrust": "low",
  "rowField": "invoiceId",
  "rowWhen": { "field": "lifecycleState", "in": ["issued", "partially-paid", "overdue"] }
}
```

Parent `salesInvoices` gains `"noticeField": "invoiceNote"`. `paymentRequests`
names no `rowAction`.

### `PUT` / `POST /apps/shillinq/api/settings` (one key added)

**Auth**: `#[AuthorizedAdminSetting]`, unchanged.

**Request:**
```json
{ "portal_payment_redirect_url": "https://portaal.gemeente.example/betalen" }
```

**Response (200):** the refreshed settings, including the key.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `portal_payment_redirect_url` is not empty and not an absolute `https` address; nothing is stored |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | `invalid` | The return address is not `https` |

## Versioning

Additive keys; the settings response gains one key.

## Breaking Change Policy

A renamed manifest key is agreed with portaliq first.

## SLA

Unchanged.
