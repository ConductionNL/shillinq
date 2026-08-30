---
status: done
---

# compliance-deadline-calendar Specification

**Status**: done
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
toggles, fail-soft so a missing calendar backend never blocks source CRUD. The
detailed sub-requirements (REQ-CDC-001 … REQ-CDC-007) below are normative —
synced from the archived `compliance-deadline-calendar` change.

#### Scenario: A filing deadline appears on the calendar and reminds the user

- GIVEN a BTW-aangifte for period 2026-Q1 with a derivable filing due date and the
  filing category enabled
- WHEN the calendar and reminder jobs run
- THEN a VEVENT MUST appear on the app calendar for that deadline and an NC
  Notification MUST be raised within the user's lead time

The normative requirements (REQ-CDC-001 … REQ-CDC-007) below were synced from the
archived change (`openspec/changes/archive/2026-07-12-compliance-deadline-calendar/`).
They cover: the dedicated app calendar + idempotent fail-soft VEVENT upsert, filing /
payment-run / opt-in AR-due-date publication from existing sources, contract
deadlines via an extended `ObligationTaskBridge`, per-user category toggles, and a
scheduled reminder-notification job. Implementation deviations (STATUS:CANCELLED
removal semantics, writable-calendar fallback, e2e file location, the added
`DeadlineReminderNotifier`) are documented in the archived tasks.md.

### Requirement: REQ-CDC-001 — The system SHALL maintain a dedicated app-owned calendar and publish deadlines as VEVENTs fail-soft

The system MUST ensure a single dedicated app-owned calendar exists (resolved via
`OCP\Calendar\ICalendarManager`, reusing the backend-resolution approach of
`ObligationTaskBridge`) and MUST publish each deadline as an RFC 5545 VEVENT with a
stable UID `{source}:{objectId}` so re-publication is idempotent (upsert). When no
calendar backend resolves, publication MUST log the concrete reason and return a
`failed` status WITHOUT throwing — it MUST NOT block the source record's CRUD
(mirroring `ObligationTaskBridge` REQ-CLM-003). The register/source row remains the
source of truth; the VEVENT is a read-only surface.

#### Scenario: Deadline is published as an idempotent VEVENT

- GIVEN a resolvable calendar backend and a BTW filing deadline for period 2026-Q1
- WHEN the calendar service publishes it twice
- THEN exactly one VEVENT with UID `btw-filing:vatreturn-2026-Q1` MUST exist,
  carrying the derived due date

#### Scenario: No calendar backend never blocks the source

- GIVEN no calendar backend resolves on the instance
- WHEN a deadline publication is attempted
- THEN the service MUST log the reason and return `failed`, and the source record
  (VatReturn, PaymentRun, ARInvoice, ContractObligation) MUST be unaffected

### Requirement: REQ-CDC-002 — Filing deadlines (BTW / ICP / VPB) SHALL be published from existing period data

The system SHALL publish one VEVENT per open filing obligation, deriving the due
date from the existing period data of the VAT (BTW-aangifte), ICP-opgaaf and VPB
services — it MUST NOT recompute or store a parallel deadline. Each VEVENT MUST
name the obligation and period (e.g. "BTW-aangifte 2026-Q1") and MUST be removed
once the corresponding filing reaches a submitted/closed state.

#### Scenario: BTW filing deadline appears on the calendar

- GIVEN a VatReturn for period 2026-Q1 with a derivable filing due date
- WHEN the calendar service runs
- THEN a VEVENT "BTW-aangifte 2026-Q1" MUST be published on the app calendar with
  that due date

#### Scenario: Submitted filing removes its deadline VEVENT

- GIVEN a published BTW filing VEVENT for 2026-Q1
- WHEN the 2026-Q1 BTW-aangifte reaches a submitted/closed state
- THEN its VEVENT MUST be removed from the app calendar

### Requirement: REQ-CDC-003 — Payment-run execution dates SHALL be published as VEVENTs

The system SHALL publish a VEVENT for each scheduled payment-run execution date,
sourced from the existing payment-run records, named to identify the run, and
removed once the run is executed or cancelled.

#### Scenario: Scheduled payment run appears on the calendar

- GIVEN a payment run scheduled for 2026-02-28
- WHEN the calendar service runs
- THEN a VEVENT identifying that payment run MUST be published on 2026-02-28

### Requirement: REQ-CDC-004 — AR invoice due dates SHALL be publishable per-user and default off

The system SHALL publish a VEVENT per open `ARInvoice` due date **only when the
user has enabled the AR-due-date category** (default off). Publication MUST respect
the `ARInvoice` `dueDate` and MUST remove the VEVENT once the invoice reaches
`paid` or `written-off`.

#### Scenario: AR due dates hidden by default

- GIVEN a user who has not enabled the AR-due-date category
- WHEN the calendar service runs
- THEN no AR invoice due-date VEVENTs MUST be published for that user

#### Scenario: Enabling the category publishes open AR due dates

- GIVEN a user who enables the AR-due-date category and an open `ARInvoice` due
  2026-03-15
- WHEN the calendar service runs
- THEN a VEVENT for that invoice's due date MUST be published, and it MUST be
  removed when the invoice becomes `paid`

### Requirement: REQ-CDC-005 — Contract renewal / opzegtermijn alerts SHALL be published by extending ObligationTaskBridge, not duplicating it

The system SHALL publish contract renewal and opzegtermijn deadline VEVENTs by
**extending `ObligationTaskBridge`** so the contract-obligation path has a single
home; it MUST NOT introduce a parallel service that re-reads `ContractObligation`
rows or re-implements backend resolution. The existing VTODO task creation MUST
continue to work; the VEVENT publication is additive alongside it.

#### Scenario: Opzegtermijn deadline is published via the extended bridge

- GIVEN a `ContractObligation` with an opzegtermijn deadline of 2026-06-01
- WHEN the obligation is created/updated
- THEN the extended `ObligationTaskBridge` MUST publish a deadline VEVENT for
  2026-06-01 in addition to its existing VTODO, without a separate contract-reading
  service

#### Scenario: Bridge remains fail-soft

- GIVEN the extended bridge and no calendar backend
- WHEN an obligation is saved
- THEN both VTODO and VEVENT publication MUST return `failed` without throwing, and
  obligation CRUD MUST proceed

### Requirement: REQ-CDC-006 — A per-user setting SHALL toggle each deadline category

The system SHALL expose a per-user setting toggling each deadline category
(BTW/ICP/VPB filing, payment-run, AR-due-date, contract). Publication MUST honour
the toggles; disabling a category MUST remove that category's VEVENTs for the user.
Category strings and i18n keys MUST be English.

#### Scenario: Toggling a category off removes its events

- @e2e src/views/**/DeadlineCalendarSettings*.spec.js
- GIVEN a user with the payment-run category enabled and published payment-run
  VEVENTs
- WHEN the user disables the payment-run category in settings
- THEN the payment-run VEVENTs MUST be removed and no new ones published

### Requirement: REQ-CDC-007 — A scheduled job SHALL raise NC Notifications ahead of each deadline

The system SHALL run a scheduled `TimedJob` that, for each upcoming deadline within
the user's configured lead time, raises exactly one NC Notification
(`OCP\Notification\IManager`) per deadline per user, honouring the category
toggles. Notifications are scheduled bulk work (ADR-031 imperative exception).

#### Scenario: Reminder fires within the lead time

- GIVEN a BTW filing deadline 7 days out and a user lead time of 10 days with the
  filing category enabled
- WHEN the reminder job runs
- THEN exactly one NC Notification about that deadline MUST be raised for the user

#### Scenario: Disabled category suppresses the reminder

- GIVEN a user with the AR-due-date category disabled and an AR invoice due in 3
  days
- WHEN the reminder job runs
- THEN no reminder Notification MUST be raised for that invoice

## Notes

- Owns no register schema — all deadlines are derived from existing sources.
- Related canonical specs: `templates-notifications-settings` (SMS/email channels),
  `bookkeeping-accounts-receivable-core` (AR due dates), the VAT/ICP/VPB filing
  specs, and `contract-lifecycle-management` (`ObligationTaskBridge`).
- Imperative surfaces (calendar/notification integration, scheduled job) are
  ADR-031-justified; category-toggle defaults are declarative.
