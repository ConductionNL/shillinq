# Barcode Lookup API

**Endpoint:** `GET /index.php/apps/shillinq/api/barcode/lookup/{code}`
**Spec:** [`inventory-barcode-sku`](../../openspec/changes/inventory-barcode-sku/specs/inventory-barcode-sku/spec.md) — REQ-SKU-007, REQ-SKU-008, REQ-SKU-009.
**Consumers:** pipelinq `pos-barcode-scan` module (primary), any retail/wholesale POS terminal.

## Purpose

Resolve a scanned barcode value to its canonical `Barcode` record plus the
expanded `Product` product data, returning the per-UoM `quantity` so the POS
terminal can offer the correct "add 1 carton (= 12 units)" semantics at
checkout.

> The spec text uses `InventoryItem`; the live shillinq catalog ships the
> schema as `Product`. The response below uses `product` because that
> matches the actual schema slug.

## Authentication

`#[PublicPage]` controller so POS terminals without a Nextcloud session can
reach the endpoint — but every request MUST present a valid Bearer API key
matching the app-config secret `shillinq:barcode_lookup_api_key`:

```
Authorization: Bearer <secret>
```

The key is compared with `hash_equals` (constant-time). If the secret is not
yet provisioned the endpoint **fails secure** — anonymous callers are
rejected and the endpoint only accepts authenticated Nextcloud users (ADR-005).

Provision the key once per POS fleet:

```bash
occ config:app:set shillinq barcode_lookup_api_key --value "<long-random-secret>"
```

Revoke a compromised key by overwriting it (no per-key revocation list yet).

## Request

| Parameter | Location | Required | Description |
|-----------|----------|----------|-------------|
| `code` | path | Yes | The scanned barcode value (e.g. `5410317126589`). Slashes/dashes/dots allowed. |
| `uomCode` | query | No | Optional UoM filter (e.g. `EA`, `CA`, `PL`). When omitted, returns the first active match. |

## Responses

### 200 OK — barcode resolved

```json
{
  "barcode": {
    "id": "5e7d7a9c-...-...",
    "barcode": "5410317126589",
    "barcodeType": "EAN",
    "format": "EAN-13",
    "productSku": "DV-KAT-SENIOR-2KG",
    "uomCode": "EA",
    "quantity": 1,
    "isDefault": true,
    "isActive": true
  },
  "product": {
    "sku": "DV-KAT-SENIOR-2KG",
    "name": "Dragonvale Cat Senior 2kg",
    "category": "food_beverage",
    "unitPrice": 12.99,
    "currency": "EUR"
  }
}
```

`product` is `null` when no matching `Product` is present (e.g. when
inventory-product-catalog has not yet seeded the demo catalog). The barcode
record is still returned so the POS can fall back to a manual lookup.

### 404 Not Found — barcode missing or inactive

```json
{ "error": "Barcode not found" }
```

Returned in three cases:
1. No `Barcode` record matches `code` (REQ-SKU-007).
2. The matching record has `isActive: false` (REQ-SKU-008).
3. `code` is empty after trimming.

### 401 Unauthorized — missing / invalid Bearer token

```json
{ "error": "Unauthorized" }
```

Returned when the `Authorization` header is absent, malformed, or the token
does not match the configured `barcode_lookup_api_key` (and there is no
authenticated NC session to fall back to).

## Curl examples

```bash
# Provisioned POS terminal scans an EAN-13 unit barcode.
curl -sS \
  -H "Authorization: Bearer $POS_TOKEN" \
  "https://nc.example.com/index.php/apps/shillinq/api/barcode/lookup/5410317126589"

# Same product, force the carton (CA) record.
curl -sS \
  -H "Authorization: Bearer $POS_TOKEN" \
  "https://nc.example.com/index.php/apps/shillinq/api/barcode/lookup/15410317126586?uomCode=CA"
```

## POS UX requirement (REQ-SKU-009)

When a barcode is scanned, the POS UX MUST display the quantity information
alongside the barcode to prevent checkout errors:

```
{quantity}× {uomCode} | {product.name}
```

Examples:
- `1× EA | Dragonvale Cat Senior 2kg` — single unit.
- `4× CA | Dragonvale Cat Senior 2kg` — carton of 4.
- `100× PL | Dragonvale Cat Senior 2kg` — full pallet.

This requirement is owned by the pipelinq POS module; shillinq supplies the
`quantity` and `uomCode` fields in the lookup response.

## Caching guidance

The endpoint is synchronous and lightweight. A CDN or reverse proxy MAY
cache `(code, uomCode)` responses for up to 60 seconds; the POS terminal
SHOULD always honour `Cache-Control: no-store` to ensure deactivation
(REQ-SKU-008) takes effect promptly. No server-side cache header is set by
the controller today.

## Operational notes

- Inactive barcodes never appear in the response (REQ-SKU-008). Reactivating a
  barcode (`isActive: true`) makes it immediately resolvable.
- Duplicate barcode values across different products is prevented at write
  time by the `(productSku, barcodeType, uomCode)` uniqueness constraint on
  the `Barcode` schema (REQ-SKU-005).
- All ObjectService failures are caught and presented as empty results — no
  database stack traces ever leak to the client (ADR-005).
