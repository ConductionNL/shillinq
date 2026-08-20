# Tasks: fix-semantic-org-markers

## 1. Prefix bare markers
- [x] 1.1 In `lib/Settings/register.d/bookkeeping-quote-order-invoice.json`,
      change `"x-schema-org": "Offer"` (line ~18) → `"schema:Offer"`,
      `"Order"` (~312) → `"schema:Order"`, `"ParcelDelivery"` (~651) →
      `"schema:ParcelDelivery"`, and both `"Invoice"` (~760, ~1120) →
      `"schema:Invoice"`.

## 2. Fix the IFRS 15 Contract marker
- [x] 2.1 In `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json` (line ~17),
      change the `Contract` schema's `"x-schema-org": "schema:CreativeWork"` →
      `"schema:Contract"`.

## 3. Add the Payment marker
- [x] 3.1 In `lib/Settings/register.d/zz-order-base.json`, add
      `"x-schema-org": "schema:PayAction"` (or `schema:MoneyTransfer`) to the
      `Payment` schema.

## 4. Verify
- [x] 4.1 Add a lint/assertion over `lib/Settings/register.d/*.json` +
      `lib/Settings/shillinq_register.json`: every `x-schema-org` value matches
      `^(schema:|ns#)`.
- [x] 4.2 Re-validate the three edited register fragments as well-formed JSON.
