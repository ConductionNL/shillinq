# Signing Audit Trail

The Signing Audit Trail shows who approved and signed each financial document and when.

## Access

Navigate to **Bookkeeping > Signing Audit Trail** in the sidebar. The view opens
OpenRegister's audit-log UI pre-filtered to signing events for all bookkeeping objects.

## What You See

| Column | Description |
|--------|-------------|
| Timestamp | When the signing event occurred (ISO-8601, UTC) |
| Object type | Account, GL Transaction, Purchase Order, etc. |
| Object ID | UUID of the signed document |
| Actor | User name and ID of the signer |
| Action | `lifecycle:draft→signed`, `lifecycle:pending→approved`, etc. |
| Status | `approved`, `rejected`, or `pending` |
| Comment | Approval comment or rejection reason |

## Use Cases

### Accountant: Verify signature chain for a purchase order

1. Open **Bookkeeping > Signing Audit Trail**.
2. Filter by `objectType = PurchaseOrder` and the relevant date range.
3. Confirm all three approval levels (department manager, budget owner, accountant)
   appear chronologically with matching timestamps.

### Rekenkamer auditor: Prove no unsigned invoices were posted

1. Open **Bookkeeping > Signing Audit Trail**.
2. Set filter `objectType = APInvoice` and `status = approved`.
3. Export as Excel (top-right export button) for your audit file.

## Per-object Side Panel

On any bookkeeping detail page (Account, IV3-rapportage, Deelnemer), the right-hand
**Audit History** panel shows all signing events for the currently viewed object.
The panel is permission-scoped: you only see events for objects you have read access to.

## Compliance Note

The signing trail consumes OpenRegister's `audit-trail-immutable` abstraction —
every event is hash-chain certified per ADR-022. The hash can be verified via
OpenRegister's verification API (`valid: true` means the trail was not tampered with).

## Related

- [Destruction Report](audit-pack-destruction-report.md)
- [GDPR Subject Access](../compliance/gdpr-subject-access.md)
