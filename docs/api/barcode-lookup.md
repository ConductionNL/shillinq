# Barcode Lookup API

Synchronous barcode-resolution endpoint for POS terminals (consumed by the
pipelinq `pos-barcode-scan` module). Resolves a scanned barcode value to its
`Barcode` register record plus the expanded `InventoryItem` product data,
returning the per-UoM quantity so the POS can offer "add 1 carton (= N units)".

Spec: `openspec/changes/inventory-barcode-sku/specs/inventory-barcode-sku/spec.md`
(REQ-SKU-007, REQ-SKU-008).

## Endpoint

```
GET /index.php/apps/shillinq/api/barcode/lookup/{code}
```

| Parameter | In | Required | Description |
|-----------|------|----------|-------------|
| `code` | path | Yes | The scanned barcode value (e.g. `5410317126589`). |
| `uomCode` | query | No | Filter by unit-of-measure (e.g. `CA` for cartons). When omitted, the first active match is returned. |

## Authentication

The endpoint is a `#[PublicPage]` so provisioned POS terminals (which hold no
Nextcloud session) can call it, but every request MUST present a valid Bearer
API key matching the app config secret `barcode_lookup_api_key`:

```
Authorization: Bearer <barcode_lookup_api_key>
```

When no API key is configured, the endpoint **fails secure** — it falls back to
requiring an authenticated Nextcloud user and rejects anonymous callers (it is
never an open endpoint). The API key is read from app config and is never
returned in any response (ADR-005).

Set the key via OpenRegister/Nextcloud app config:

```bash
docker exec -u www-data nextcloud php occ config:app:set shillinq barcode_lookup_api_key --value="<a-strong-random-key>"
```

## Responses

### 200 OK

```json
{
  "barcode": {
    "id": "barcode-001",
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
    "category": "Pet Food",
    "unitPrice": 12.99,
    "currency": "EUR"
  }
}
```

`product` is `null` when the referenced `InventoryItem` has not yet been seeded
(the barcode is still resolvable on its own). Per REQ-SKU-009, the pipelinq POS
UX displays `"{quantity}× {uomCode} | {product.name}"` (e.g. `"4× CA |
Dragonvale Cat Senior 2kg"`).

### 404 Not Found

Returned when no **active** barcode matches the code (and optional UoM).
Inactive barcodes (`isActive: false`) are never returned (REQ-SKU-008).

```json
{ "error": "Barcode not found" }
```

### 401 Unauthorized

Missing/invalid Bearer key (with a key configured), or anonymous caller when no
key is configured.

```json
{ "error": "Unauthorized" }
```

## Examples

Scan a unit EAN-13:

```bash
curl -s \
  -H "Authorization: Bearer $POS_KEY" \
  "https://nextcloud.example/index.php/apps/shillinq/api/barcode/lookup/5410317126589"
```

Scan a carton GTIN-14, constrained to the carton UoM:

```bash
curl -s \
  -H "Authorization: Bearer $POS_KEY" \
  "https://nextcloud.example/index.php/apps/shillinq/api/barcode/lookup/15410317126586?uomCode=CA"
```
