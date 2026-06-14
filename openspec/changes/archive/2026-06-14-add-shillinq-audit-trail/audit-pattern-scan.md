# Task 1 — Audit anti-pattern scan (ADR-022)

This file records the result of the Task 1 scan from `tasks.md` —
confirming the shillinq codebase carries **no parallel audit storage**
per ADR-022 enumeration ("Home-grown audit trails").

## Scan results (executed against `feature/audit-trail/add-shillinq-audit-trail`)

| Anti-pattern bucket | Glob | Hits | Notes |
|---|---|---|---|
| Parallel audit Mappers | `lib/Db/Audit*` `lib/Db/EventLog*` `lib/Db/ChangeLog*` | 0 | clean |
| App-local audit storage services | `lib/Service/AuditService.php` `lib/Service/AuditLogger.php` | 0 | clean |
| Audit cleanup background jobs | `lib/BackgroundJob/*Audit*.php` `lib/Cron/*Audit*.php` | 0 | clean |
| Audit retention services | `lib/Service/*Retention*.php` | 0 | clean — retention is OR's responsibility per REQ-AT-005 |
| App-local audit Vue surfaces | `src/views/Audit*.vue` `src/components/Audit*.vue` | 0 | clean — OR's audit-log UI is the canonical surface |

## ALLOWED — pre-existing consumers of OR's audit trail

The following pre-existing files match a literal `Audit*` filename glob
but are **not** parallel audit storage. They are CONSUMERS that read
OR's audit-log API and present its data in a domain-specific surface
(PO-3-way forensic export package per `bookkeeping-purchase-order-3way-11`,
REQ-PO3W-010). Per ADR-022, consumers of the audit-trail-immutable
abstraction are permitted; only parallel storage and home-grown
chain implementations are forbidden.

| File | Role | Why allowed |
|---|---|---|
| `lib/Service/AuditExportService.php` | Slice-11 PO-3-way forensic exporter — assembles a deterministic ZIP from OR's audit log + slice-01..10 records | Consumes OR audit-log API; writes NO app-local audit table; the package is a derived artefact, not a parallel storage |
| `src/components/three-way-match/AuditTrailDetail.vue` | Slice-11 PO-3-way audit-trail page component for a single SupplierInvoice — renders the ordered lifecycle timeline from OR's audit-log API | Consumer-side rendering; does NOT shadow OR's audit-log UI for general bookkeeping. The bookkeeping-wide audit surface declared by this change (REQ-AT-003 / REQ-AT-004) MUST use OR's audit-log component, not this PO-3-way specialised view |

## Conclusion

The codebase satisfies ADR-022 and the anti-pattern scenarios of
REQ-AT-001. Implementation Tasks 5..9 can proceed: declaring the
audit flag on every bookkeeping register, wiring the OR audit-log UI
into the manifest, and extending CI to keep this true.
