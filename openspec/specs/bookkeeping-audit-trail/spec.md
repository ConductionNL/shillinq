---
status: done
---

# Spec: bookkeeping-audit-trail

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** none (this capability is a wiring + UI surface, not a code dependency)

This capability wires OR's audit-trail-immutable abstraction into all
bookkeeping registers and surfaces a UI entry in the shillinq manifest
for auditors and bookkeepers to query the OR audit log filtered to
bookkeeping objects. It exists to satisfy AVG/Woo/Archiefwet compliance
requirements and the Belastingdienst-mandated 7-year retention period.

Per ADR-022, all audit-trail functionality MUST come from OpenRegister.
An app-local audit table is explicitly forbidden.

## Purpose

This specification defines the requirements for bookkeeping audit trail in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/data: audit hash chain, immutable log — not browser-testable


### REQ-AT-001: Every bookkeeping register SHALL declare `x-openregister-audit: true`

The system SHALL satisfy this requirement: Every bookkeeping register SHALL declare `x-openregister-audit: true`.

Every T1 and T2 register declared in `lib/Settings/shillinq_register.json`
MUST carry `x-openregister-audit: true` (or the OR-canonical equivalent
field). This activates OR's immutable audit trail (append-only hash-chained
event log) for every create, update, and lifecycle transition on each
object. The following registers MUST carry the flag:

**T1 (confirm existing):** `Account`, `GLTransaction`, `GLLine`, `JournalEntry`

**T2 (new, must declare from creation):** `FiscalPeriod`, `VendorMaster`,
`APInvoice`, `PaymentRun`, `CustomerMaster`, `ARInvoice`, `DunningRecord`,
`BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`

No app-local audit table (`lib/Db/Audit*`, `lib/Service/Audit*`,
`appinfo/routes.php` route `/audit/purge`) SHALL exist (per ADR-022
anti-pattern list).

#### Scenario: Every bookkeeping register carries the audit flag

- **GIVEN** `lib/Settings/shillinq_register.json` is loaded
- **WHEN** each of the 15 registers listed above is inspected
- **THEN** each MUST carry `x-openregister-audit: true` (or the
  OR-canonical equivalent).

#### Scenario: Reviewer confirms no app-local audit infrastructure

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/Audit*`, `lib/Service/Audit*`, or
  routes matching `/audit/purge`
- **THEN** no such classes or routes SHALL exist.

### REQ-AT-002: Every bookkeeping object's lifecycle transition SHALL produce an audit event

The system SHALL satisfy this requirement: Every bookkeeping object's lifecycle transition SHALL produce an audit event.

For every bookkeeping register with an `x-openregister-lifecycle` block,
each lifecycle transition MUST produce an audit event in OR's immutable
log. The event MUST record at minimum: the object UUID, the schema name,
the `from` state, the `to` state, the actor UUID, and the ISO 8601
timestamp. Hash-chain integrity is OR's responsibility; shillinq makes
no additional assertions on the hash chain.

#### Scenario: FiscalPeriod close transition produces an audit event

- **GIVEN** `FiscalPeriod` `2026-01` transitions from `open` to `closing`
- **WHEN** the OR audit-log endpoint is queried for the period's UUID
- **THEN** an audit event MUST exist with `from: "open"`, `to: "closing"`,
  the actor's UUID, and a timestamp.

#### Scenario: APInvoice `approved → posted` transition produces an audit event

- **GIVEN** `APInvoice` `INK-2026-0001` transitions from `approved` to
  `posted`
- **WHEN** the OR audit-log endpoint is queried for the invoice's UUID
- **THEN** an audit event MUST exist with `from: "approved"`,
  `to: "posted"`, the actor's UUID, and the `glTransactionId` written.

### REQ-AT-003: The audit trail SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a `Bookkeeping > Audit Trail`
navigation entry. This entry MUST open OR's audit-log UI
pre-filtered to bookkeeping object types (the list of register names:
`Account`, `GLTransaction`, `GLLine`, `JournalEntry`, `FiscalPeriod`,
`VendorMaster`, `APInvoice`, `PaymentRun`, `CustomerMaster`,
`ARInvoice`, `DunningRecord`, `BankStatement`, `BankStatementLine`,
`MatchingRule`, `ReconciliationMatch`). The page MUST support filtering
by: object type, actor, date range, and lifecycle transition.

The `auditor` role MUST have read access. The `bookkeeper` role MAY
have read access. No shillinq-side code is authored — the manifest
entry points to OR's existing audit-log UI surface.

#### Scenario: Audit Trail navigation entry exists in the manifest

- **GIVEN** `src/manifest.json` is loaded
- **WHEN** scanned for navigation entries
- **THEN** a `Audit Trail` entry under the `Bookkeeping` section MUST
  exist with a valid page reference pre-filtering to bookkeeping object
  types.

#### Scenario: Auditor can query the audit log

- **GIVEN** a user with `auditor` role opens `Bookkeeping > Audit Trail`
- **WHEN** filtering to object type `FiscalPeriod` and date range
  `2026-01-01` to `2026-01-31`
- **THEN** all lifecycle transition events for `FiscalPeriod` objects
  in that date range MUST be visible.

### REQ-AT-004: Every bookkeeping `type: detail` manifest page SHALL declare the OR audit-log side panel

The system SHALL satisfy this requirement: Every bookkeeping `type: detail` manifest page SHALL declare the OR audit-log side panel.

Per ADR-024, every `type: detail` page entry for bookkeeping registers
in `src/manifest.json` MUST declare the OR audit-log side panel
(integration panel) filtered to the object's UUID. This allows
bookkeepers and auditors to view the full audit history of any
individual object without navigating away from the detail page.

#### Scenario: APInvoice detail page shows audit events in side panel

- **GIVEN** an `APInvoice` detail page is open in the UI
- **WHEN** the auditor opens the audit-log side panel
- **THEN** all audit events for that specific invoice UUID MUST
  be visible, including the `draft → received`, `received → matched`,
  `matched → approved`, and `approved → posted` transitions.

### REQ-AT-005: Audit retention SHALL be governed by OR per ADR-022

The system SHALL satisfy this requirement: Audit retention SHALL be governed by OR per ADR-022.

The retention period for bookkeeping audit events is 7 years
(Belastingdienst requirement, per Archiefwet). This retention policy
MUST be configured in OR's archival-destruction-workflow — NOT as
a shillinq cron job or a PHP purge service. Shillinq MUST NOT author
any code that deletes or purges audit trail records. The audit trail
is read-only from shillinq's perspective.

#### Scenario: Shillinq contains no audit purge route or service

- **GIVEN** the shillinq codebase and `appinfo/routes.php`
- **WHEN** scanned for route paths containing `/audit/purge`,
  `/audit/delete`, or similar, and for PHP classes implementing
  purge or delete operations on audit records
- **THEN** no such routes or classes SHALL exist.
