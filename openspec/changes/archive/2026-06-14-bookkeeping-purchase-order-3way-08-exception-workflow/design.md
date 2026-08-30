# Design — Member 08: Exception Resolution Workflow (code)

## Context

`kind: code` member implementing the exception-resolution side of
matching. Consumes the `ThreeWayMatch` records produced by members 06-07.

## Decisions

### D7 — Three resolution paths, all audit-trailed

Carried from the giant's D7. When match_status ∈ {exception_price,
exception_quantity, exception_missing_grn, exception_missing_po,
fraud_alert}:

1. **Accept with motivation** — crediteuren-administrateur confirms and
   records resolution_notes; updates ThreeWayMatch resolution fields.
2. **File dispute** — auto-generates a UBL CreditNote request via
   openconnector and escalates to the Inkoper notification queue.
3. **Reject and block payment** — marks the invoice rejected, reverses any
   partial GR/IR postings, restores stock if needed.

All paths record resolved_by + resolution_action + resolution_notes +
resolved_at. Payment is blocked until resolution.

## Security (ADR-005)

- Resolution actions are server-authoritative and gated to the
  crediteuren-administrateur (and controller, per ToleranceProfile
  exception_routing). resolved_by is taken from the authenticated user,
  not the request body.

## Reuse
- `ThreeWayMatch` register + resolution fields (member 01)
- Notification service for exception alerts + deep links
- openconnector for UBL CreditNote dispute generation
- nextcloud-vue for the exception panel (modal isolation per ADR-004)
