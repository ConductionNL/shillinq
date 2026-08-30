# Design — Member 03: Peppol Transmission (code)

## Context

`kind: code` member adding Peppol/PDF transmission of an approved PO.
Builds on the `PurchaseOrderService` and `PurchaseOrderForm.vue` from
member 02, and the PO Peppol metadata fields declared in member 01.

## Decisions

### D8 — Peppol BIS Ordering 3.0 with PDF+email fallback

Carried from the giant's D8. An approved PO emits as a UBL 2.1 Order via
the openconnector Peppol Access Point. The send is only available once
the lifecycle is `approved` (the send-block guard from member 02 still
applies). On success, `peppol_message_id` (URN) and `peppol_sent_at` are
recorded and the PO transitions to `sent`.

### D2 — Graceful fallback, never silent

If the supplier is not a Peppol participant, `sendToPDFEmail()` runs and
records `peppol_fallback_reason` (e.g. "supplier_not_peppol_participant").
The PO still transitions to `sent`; the fallback is auditable.

## Security (ADR-005)

- Transmission is server-side; the client triggers the action but cannot
  forge peppol_message_id or bypass the approval-state precondition.

## Reuse
- openconnector Peppol Access Point for UBL Order AS4 transmission
- PO Peppol metadata fields (member 01)
- `PurchaseOrderService` + PO form (member 02)
