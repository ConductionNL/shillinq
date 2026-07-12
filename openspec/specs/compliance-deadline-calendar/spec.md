---
status: in-progress
---

# compliance-deadline-calendar Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- compliance-deadline-calendar

## Purpose

Surfaces Shillinq's compliance and operational deadlines in the Nextcloud Calendar
(app-owned VEVENTs) and the Notifications centre: BTW/ICP/VPB filing deadlines,
payment-run execution dates, AR invoice due dates (opt-in), and contract renewal /
opzegtermijn alerts. Reuses the `OCP\Calendar\ICalendarManager` seam proven in
`ObligationTaskBridge` and the RFC 5545 VEVENT construction of `IcsService`;
publication is fail-soft and never blocks source CRUD.

## Requirements

### Requirement: REQ-CDC-000 — Shillinq SHALL surface compliance deadlines in the Nextcloud Calendar and Notifications

The system SHALL publish its compliance and operational deadlines (BTW/ICP/VPB
filing, payment-run execution, opt-in AR invoice due dates, contract renewal /
opzegtermijn) into a dedicated app-owned Nextcloud calendar as VEVENTs and MUST
raise reminder Notifications ahead of each deadline, honouring per-user category
toggles, fail-soft so a missing calendar backend never blocks source CRUD. Detailed
sub-requirements (REQ-CDC-001 … REQ-CDC-007) are authored in the in-progress change
delta and synced here on archive.

#### Scenario: A filing deadline appears on the calendar and reminds the user

- GIVEN a BTW-aangifte for period 2026-Q1 with a derivable filing due date and the
  filing category enabled
- WHEN the calendar and reminder jobs run
- THEN a VEVENT MUST appear on the app calendar for that deadline and an NC
  Notification MUST be raised within the user's lead time

The normative requirements (REQ-CDC-001 … REQ-CDC-007) are authored as the change
delta at
`openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md`
and will be synced into this canonical spec on archive (`openspec sync`). They
cover: the dedicated app calendar + idempotent fail-soft VEVENT upsert, filing /
payment-run / opt-in AR-due-date publication from existing sources, contract
deadlines via an extended `ObligationTaskBridge`, per-user category toggles, and a
scheduled reminder-notification job.

## Notes

- Owns no register schema — all deadlines are derived from existing sources.
- Related canonical specs: `templates-notifications-settings` (SMS/email channels),
  `bookkeeping-accounts-receivable-core` (AR due dates), the VAT/ICP/VPB filing
  specs, and `contract-lifecycle-management` (`ObligationTaskBridge`).
- Imperative surfaces (calendar/notification integration, scheduled job) are
  ADR-031-justified; category-toggle defaults are declarative.
