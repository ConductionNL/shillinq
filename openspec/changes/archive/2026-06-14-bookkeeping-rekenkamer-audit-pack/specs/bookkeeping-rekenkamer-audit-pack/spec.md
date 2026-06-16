# Spec: bookkeeping-rekenkamer-audit-pack

**Status:** proposed
**Scope:** shillinq
**Tier:** T2/T3 (compliance + operations + governance)
**Depends on:** bookkeeping-chart-of-accounts, accounts-payable-receivable, procurement-compliance

## ADDED Requirements

### Requirement: REQ-RAP-001 — Every financial and procurement register SHALL declare `x-openregister-audit-trail.enabled: true` to enable OR's audit-trail-immutable abstraction

Every register declared by T1, T2, and T3 — including `Account`, `GLTransaction`, `GLLine`, `JournalEntry`, `APInvoice`, `ARInvoice`, `PurchaseOrder`, `Tender`, `Bid`, `Payment`, `Receipt`, `ApprovalRequest` — MUST carry `x-openregister-audit-trail.enabled: true` in its schema declaration in `lib/Settings/shillinq_register.json` (or the matching `lib/Settings/register.d/*.json` fragment per ADR-037).

This switches on OR's built-in audit-trail-immutable abstraction per ADR-022 —
every create / update / state-transition emits an audit event recorded in OR's
append-only hash-chained log with actor, before/after, timestamp.

The implementation MUST NOT introduce an `AuditService.php`, `AuditLogger.php`,
app-local `audit_*` Mapper, or any parallel audit-event table. Per ADR-022's
anti-pattern list ("Home-grown audit trails") this is review-blocking.

#### Scenario: Reviewer confirms no parallel audit storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `audit_*`, `event_log_*`,
  `change_log_*` or `lib/Service/Audit*.php`
- **THEN** no such classes or files SHALL exist.

#### Scenario: Every financial register declares audit on

- **GIVEN** `lib/Settings/shillinq_register.json`
- **WHEN** every register declared by T1 + T2 + T3 is inspected
- **THEN** each MUST carry `x-openregister-audit: true` (or the OR-canonical
  equivalent) in its schema metadata.

### Requirement: REQ-RAP-002 — Shillinq SHALL expose a Signing Audit Trail manifest entry pre-filtered to signing decisions

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping > Signing Audit Trail`)
that opens OR's audit-log UI pre-filtered to document signing events. The UI MUST display:

- Document/record identifier
- Approval actor (user name + ID)
- Approval timestamp
- Signature status (approved, rejected, pending)
- Approval comment or rejection reason

The filter expression MUST be constructed as a manifest query parameter targeting
events where `action: lifecycle:*→signed` or `action: update` on fields marking
approval (e.g. `signedBy`, `approvedBy`).

#### Scenario: Bookkeeper views who signed a GL transaction

- **GIVEN** a `JournalEntry` transitioned to `status: signed`
- **WHEN** the operator clicks "Bookkeeping > Signing Audit Trail"
- **THEN** the OR audit-log UI MUST appear filtered to show only signing events
  for financial records, sorted by newest first, with actor + timestamp + signature
  status visible.

#### Scenario: Auditor traces approval chain for a purchase order

- **GIVEN** a `PurchaseOrder` with three approval levels (department manager,
  budget owner, accountant)
- **WHEN** the auditor views the signing trail
- **THEN** the UI MUST list all three approval events chronologically with each
  approver's name and signature timestamp.

### Requirement: REQ-RAP-003 — Shillinq SHALL expose a Destruction Report manifest entry for archived records

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping > Destruction Report`)
that opens OR's audit-log UI pre-filtered to `status: marked-for-destruction` and
`status: destruction-completed` lifecycle transitions. The UI MUST display:

- Record identifier and type
- Destruction approval actor (compliance officer)
- Approval timestamp
- Legal basis (Selectielijst code, Archiefwet article)
- Destruction completion timestamp (if completed)
- Audit certification hash (from REQ-RAP-006)

The report serves as legal proof of Archiefwet-compliant disposal for external auditors.

#### Scenario: Compliance officer generates destruction report for 7+ year-old invoices

- **GIVEN** 50 `APInvoice` records from 2016, all marked for destruction per
  Archiefwet section 7
- **WHEN** the compliance officer clicks "Bookkeeping > Destruction Report"
- **THEN** the UI MUST list the batch with approval date, destruction date, and
  the hash-chain entry certifying the destruction per ADR-022.

#### Scenario: External auditor verifies destruction compliance

- **GIVEN** a destruction report exported as PDF with the OR audit chain entry
- **WHEN** the auditor verifies the hash chain
- **THEN** OR's verification API MUST report `valid: true`, proving the destruction
  was not tampered with.

### Requirement: REQ-RAP-004 — Shillinq SHALL expose a Change History manifest entry showing before/after diffs

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping > Change History`)
that opens OR's audit-log UI pre-filtered to ALL mutations (create, update, state
transitions) for bookkeeping objects. The UI MUST display:

- Record identifier and type
- Change actor (user name + ID)
- Change timestamp
- Before/after field diff (which field changed, old value → new value)
- Change reason (if logged in audit event metadata)

#### Scenario: Bookkeeper audits a mistaken invoice amount edit

- **GIVEN** an `APInvoice` amount changed from 1000.00 EUR to 1100.00 EUR by Bookkeeper A
  at 2024-05-15 10:30 UTC
- **WHEN** the bookkeeper views the change history
- **THEN** the UI MUST show the diff: `amount: 1000.00 → 1100.00`, actor `Bookkeeper A`,
  timestamp `2024-05-15 10:30:00 UTC`.

#### Scenario: Supervisor traces a GL posting sequence

- **GIVEN** a `JournalEntry` created → edited twice → posted
- **WHEN** the supervisor views change history
- **THEN** the UI MUST list all four events (create, edit 1, edit 2, post) with
  actor + timestamp + field diffs for each change.

### Requirement: REQ-RAP-005 — Shillinq SHALL expose a Compliance Export API for external auditors

Shillinq MUST expose `GET /index.php/apps/shillinq/api/audit/export` with query parameters `from=YYYY-MM-DD&to=YYYY-MM-DD&format=csv|xlsx|json&scope=all|subject-id`; the endpoint MUST query the OR audit trail, filter out PII fields, and render an export file.

**Export fields (PII-safe):**
- `timestamp` (ISO-8601)
- `objectType` (register name)
- `objectId` (UUID)
- `action` (create, update, lifecycle:X→Y)
- `actor` (user UUID only, NOT display name)
- `fields_changed` (JSON array of field names changed)
- `beforeValue` (non-PII fields only; email, phone, address fields excluded)
- `afterValue` (non-PII fields only)

**Excluded fields (PII):**
- `email`, `phone`, `address`, `displayName`, `firstName`, `lastName`, `birthDate`,
  `socialSecurityNumber`, `taxId`, `personId`, `ipAddress`

**RBAC:** Endpoint is `#[NoAdminRequired]` but MUST check that the caller is in the
`auditor` group; otherwise return `403 Forbidden`. Superadmins may export without
group membership.

#### Scenario: External auditor exports AP invoice audit trail for 2024

- **GIVEN** an external auditor with `auditor` group membership
- **WHEN** they call `GET /api/audit/export?from=2024-01-01&to=2024-12-31&scope=all&format=xlsx`
- **THEN** the API MUST return an Excel file with all audit events for APInvoice,
  ARInvoice, and related records, with no email/phone/address fields, and a timestamp
  showing the export was made at 2024-05-15 10:45:00 UTC.

#### Scenario: Subject access request for GDPR article 15 with PII excluded

- **GIVEN** an employee requests their audit trail for a specific 30-day period
- **WHEN** they call `GET /api/audit/export?from=2024-04-15&to=2024-05-15&scope=subject&format=csv`
- **THEN** the API MUST return a CSV with events where the caller is the actor,
  excluding fields like email, phone, address, social security number.

#### Scenario: Export audit trail is itself audited

- **GIVEN** a compliance officer exports audit data
- **WHEN** the export API is called
- **THEN** the export request MUST be logged in the audit trail with
  `action: export_request`, `actor: <caller>`, `timestamp`, `scope: all`,
  so an auditor can verify "who exported what, when".

### Requirement: REQ-RAP-006 — Shillinq SHALL integrate with Nextcloud Activity app for decision lifecycle events

`lib/Services/*Service.php` MUST emit Nextcloud Activity events on approval /
signing / rejection lifecycle transitions using `IActivityManager::publish()`.

**Event types to emit:**

| Event | Trigger | Activity message |
|---|---|---|
| `approval_requested` | ApprovalRequest created | "Approval requested for {object}" |
| `approval_approved` | ApprovalRequest approved | "{Actor} approved {object}" |
| `approval_rejected` | ApprovalRequest rejected | "{Actor} rejected {object}: {reason}" |
| `document_signed` | SigningAuthority signature recorded | "{Actor} signed {object}" |
| `decision_made` | Decision status changed | "{Actor} made decision on {object}: {status}" |

Each event MUST include:
- `timestamp` (ISO-8601)
- `actor` (user UUID)
- `objectId` (UUID of the decision/approval)
- `objectType` (register name)
- A human-readable `summary` for the Nextcloud Activity UI

#### Scenario: Staff member sees approval in Nextcloud Activity feed

- **GIVEN** an `ApprovalRequest` approved by Manager B
- **WHEN** Staff member A views their Activity feed (Nextcloud dashboard)
- **THEN** the feed MUST include an entry: "Manager B approved AP Invoice #12345"
  with timestamp and a link to the record.

#### Scenario: Activity feed respects object-level permission

- **GIVEN** Staff A approves an `APInvoice` that Staff B does NOT have read access to
- **WHEN** Staff B views the Activity feed
- **THEN** the approval event MUST NOT appear (permission filtered by Nextcloud).

### Requirement: REQ-RAP-007 — Bookkeeping detail pages SHALL surface an audit trail side panel via the manifest

The manifest entries for every bookkeeping `type: detail` page MUST declare a side
panel that surfaces OR's audit log filtered to the detail page's object ID. The side
panel uses OR's audit-log component (no bespoke Vue), displaying:

- Timestamp
- Actor (user name)
- Action (create, update, lifecycle:X→Y)
- Field changes (before/after diff)
- Hash chain certification (green checkmark if verified)

The side panel MUST be permission-scoped — the user sees only audit events for
objects they have read access to.

#### Scenario: AP invoice detail surfaces audit side panel

- **GIVEN** the manifest declares the AP Invoice detail page
- **WHEN** an operator opens an `APInvoice` detail
- **THEN** a side panel MUST render OR's audit-log component filtered to that
  invoice's UUID; the panel MUST list every state transition with actor + timestamp.

#### Scenario: Side panel shows field-level diffs

- **GIVEN** a `PurchaseOrder` amount changed from 5000 → 5500 EUR
- **WHEN** the detail page is opened
- **THEN** the side panel MUST show the edit with `amount: 5000 → 5500 EUR` and the
  actor who made the change.

### Requirement: REQ-RAP-008 — Destruction schedule lifecycle transitions SHALL be audited and certified

Records eligible for destruction (per Archiefwet, >7 years) MUST follow the state machine documented below; every transition MUST be recorded as an immutable OR audit event and MUST be approved by a `compliance-officer` role.

```
status: active → status: marked-for-destruction → status: destruction-completed
```

Each transition MUST be recorded as an audit event with:
- `actor` (compliance officer who approved the destruction)
- `action` = `lifecycle:active→marked-for-destruction` or
  `lifecycle:marked-for-destruction→destruction-completed`
- `selectielijstCode` (legal basis; e.g., "5.1.2" for Financial Records)
- `legalBasis` (citation; e.g., "Archiefwet Article 7")
- `previousHash` + `eventHash` (per ADR-022, for tamper-proof chain)

The destruction MUST be approved by a `compliance-officer` role (or equivalent);
the approval creates the destruction order which is itself an audited transition.

#### Scenario: Compliance officer marks invoices for destruction

- **GIVEN** 100 `APInvoice` records from 2015 (10+ years old per Archiefwet)
- **WHEN** a compliance officer selects them and clicks "Mark for Destruction"
- **THEN** a destruction order MUST be created with actor = compliance officer,
  and each invoice's audit trail MUST record
  `lifecycle:active→marked-for-destruction` with `selectielijstCode: 5.1.2`.

#### Scenario: Destruction completion is certified with hash chain

- **GIVEN** a destruction order approved and ready for execution
- **WHEN** the destruction task executes (manual or scheduled)
- **THEN** each record's audit trail MUST emit
  `lifecycle:marked-for-destruction→destruction-completed` with `actor: system` or
  the operator UUID, and the event's `eventHash` MUST be verifiable per ADR-022.

### Requirement: REQ-RAP-009 — GDPR/AVG subject access requests SHALL be supported with PII-excluded audit export

When a data subject (employee, contractor, vendor) requests their personal data per GDPR article 15, the system MUST export audit events where they are the actor or the subject, excluding direct PII fields (see REQ-RAP-005 exclusion list).

The export MUST timestamp when the request was made and who fulfilled it (for
accountability per article 5(1)(a) "transparency").

#### Scenario: Employee requests their activity log (GDPR article 15)

- **GIVEN** an employee requests "show me what I changed in the past 90 days"
- **WHEN** the compliance officer calls `GET /api/audit/export?from=...&to=...&scope=subject&actor={employee-id}`
- **THEN** the API MUST return a CSV with only events where the employee is the
  actor, with no email/phone/address/SSN fields, timestamped as requested at
  2024-05-15 10:45:00 UTC by Compliance Officer X.

#### Scenario: Vendor requests destruction proof under GDPR article 17

- **GIVEN** a vendor is deleted and requests proof their records were destroyed
- **WHEN** they request their audit trail for retention/destruction events
- **THEN** the system MUST export events showing `lifecycle:*→destruction-completed`
  for any records linked to that vendor, with timestamp + actor + legal basis.

### Requirement: REQ-RAP-010 — App-local audit tables, services, and loggers SHALL be explicitly forbidden

Per ADR-022 anti-pattern enumeration, the following patterns are REVIEW-BLOCKING and MUST NOT be introduced:

- NO `lib/Db/Audit*.php` Mapper classes
- NO `lib/Service/Audit*.php` service classes
- NO `lib/Db/EventLog*.php` or `lib/Db/ChangeLog*.php` tables
- NO `lib/Cron/*Audit*.php` or `lib/BackgroundJob/*Audit*.php` cleanup jobs
- NO `AuditLogger`, `EventLogger`, `ChangeTracker` services
- NO app-local deletion logic for audit records

All audit functionality MUST flow through OpenRegister's `audit-trail-immutable`
per ADR-022. Any PR introducing these patterns MUST be rejected at code review.

#### Scenario: Reviewer rejects app-local audit service

- **GIVEN** a PR introduces `lib/Service/AuditService.php`
- **WHEN** the code reviewer runs `scripts/run-hydra-gates.sh`
- **THEN** the gate MUST fail with a message citing ADR-022 and this REQ.

## Verification

`openspec validate` must exit clean on the change folder.

Architecture reviewer confirms ADR-022 compliance (no app-local audit code).
Activity event emission is tested to verify events flow to Nextcloud Activity.
Destruction schedule state machine is tested for legal requirements (7-year age,
approval required, hash chain logging).
GDPR export filters PII correctly.
All exports are logged in the audit trail.
No source code changes outside
`openspec/changes/bookkeeping-rekenkamer-audit-pack/specs/`.
