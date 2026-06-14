# Spec: bookkeeping-audit-trail

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** none (capability is a wiring + UI surface on top of T1 + T2 registers)

## ADDED Requirements

### Requirement: REQ-AT-001: Every bookkeeping register SHALL declare `x-openregister-audit: true` to enable OR's audit-trail-immutable abstraction

Every register declared by T1 (`Account`, `GLTransaction`, `GLLine`,
`JournalEntry`) and T2 (`FiscalPeriod`, `VendorMaster`,
`APInvoice`, `PaymentRun`, `CustomerMaster`, `ARInvoice`,
`DunningRecord`, `BankStatement`, `BankStatementLine`,
`ReconciliationMatch`) MUST carry `x-openregister-audit: true` in
its schema declaration in `lib/Settings/shillinq_register.json`.

This switches on OR's built-in audit-trail-immutable abstraction
per ADR-022 — every create / update / state-transition emits an
audit event recorded in OR's append-only hash-chained log with
actor, before/after, timestamp.

The implementation MUST NOT introduce an `AuditService.php`,
`AuditLogger.php`, app-local `audit_*` Mapper, or any parallel
audit-event table. Per ADR-022's anti-pattern list ("Home-grown
audit trails") this is review-blocking.

#### Scenario: Reviewer confirms no parallel audit storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `audit_*`,
  `event_log_*`, `change_log_*` or `lib/Service/Audit*.php`
- **THEN** no such classes or files SHALL exist.

#### Scenario: Every bookkeeping register declares audit on

- **GIVEN** `lib/Settings/shillinq_register.json`
- **WHEN** every register declared by T1 + T2 is inspected
- **THEN** each MUST carry `x-openregister-audit: true` (or the
  OR-canonical equivalent) in its schema metadata.

### Requirement: REQ-AT-002: The OR audit trail SHALL record actor, action, timestamp, before/after diff, and hash chain for every state change

Per OR's audit-trail-immutable contract (ADR-022), every audit
event MUST include:

| Field | Required | Purpose |
|---|---|---|
| `actor` | Yes | User UUID + display name of the operator (or `system` for OR-scheduled actions) |
| `action` | Yes | The OR action that produced the event (`create`, `update`, `lifecycle:<from>→<to>`, `delete`) |
| `timestamp` | Yes | ISO-8601 timestamp |
| `objectId` | Yes | UUID of the audited register record |
| `objectType` | Yes | The register name (e.g. `APInvoice`, `JournalEntry`) |
| `beforeState` | Yes | The full object snapshot prior to the change (or null on create) |
| `afterState` | Yes | The full object snapshot after the change (or null on delete) |
| `previousHash` | Yes | Hash of the immediately-prior audit event |
| `eventHash` | Yes | Hash of this event (including `previousHash`) — provides tamper-evident chain |

The hash chain MUST be cryptographically verifiable per OR's
abstraction; shillinq adds no integrity-check code.

#### Scenario: A journal-post audit event records before/after

- **GIVEN** a `JournalEntry` transitioning `pending → posted`
- **WHEN** the OR audit log is queried for that event
- **THEN** the event MUST include `actor`, `action:
  lifecycle:pending→posted`, `timestamp`, `objectType:
  JournalEntry`, `beforeState` (with `state: pending`),
  `afterState` (with `state: posted`, `glTransactionId: <new
  UUID>`); **AND** `previousHash` + `eventHash` MUST be present.

#### Scenario: The hash chain verifies end-to-end

- **GIVEN** N audit events for a register
- **WHEN** OR's verification API is called against the chain
- **THEN** the API MUST report `valid: true`; any tampering with
  an intermediate event MUST be detected per the hash chain.

### Requirement: REQ-AT-003: Shillinq SHALL expose a manifest entry into OR's audit-log UI pre-filtered to bookkeeping objects

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping
> Audit Trail`) that opens OR's audit-log UI pre-filtered to the
bookkeeping object types (the union of every register declared by
T1 + T2). The filter MUST be expressed as a manifest query
parameter, not as a shillinq-side wrapper UI.

No bespoke Vue files (per ADR-024 Tier-4) — OR's audit-log
component renders the filtered list, including the actor /
timestamp / action / diff / hash-chain verification UI that comes
from OR.

#### Scenario: Audit Trail entry links to OR's pre-filtered log

- **GIVEN** the manifest declares the Audit Trail entry
- **WHEN** the operator clicks it
- **THEN** the page MUST open OR's audit-log UI filtered to
  `objectType ∈ {Account, GLTransaction, GLLine, JournalEntry,
  FiscalPeriod, VendorMaster, APInvoice, PaymentRun,
  CustomerMaster, ARInvoice, DunningRecord, BankStatement,
  BankStatementLine, ReconciliationMatch}`.

#### Scenario: Reviewer confirms no shillinq audit-log component

- **GIVEN** the shillinq frontend
- **WHEN** scanned for `src/views/Audit*.vue`,
  `src/components/Audit*.vue`
- **THEN** no such files SHALL exist; the audit-log UI comes from
  OR.

### Requirement: REQ-AT-004: Bookkeeping detail pages SHALL surface a per-object audit-trail side panel via the manifest

The manifest entries for every bookkeeping `type: detail` page
(per T1 REQ-CoA-008, REQ-GL-007, REQ-JE-009 and per T2 capability
specs' respective REQ-*-010) MUST declare a side panel that
surfaces OR's audit log filtered to the detail page's object ID.

The side panel uses OR's audit-log component (no bespoke Vue),
the same way the integration-registry pattern (ADR-019) surfaces
files / notes / tasks side panels.

#### Scenario: AP invoice detail surfaces audit side panel

- **GIVEN** the manifest declares the AP Invoice detail page
- **WHEN** an operator opens an `APInvoice` detail
- **THEN** a side panel MUST render OR's audit-log component
  filtered to that invoice's UUID; the panel MUST list every
  state transition with actor + timestamp.

#### Scenario: Audit side panel is consistent across registers

- **GIVEN** any bookkeeping detail page
- **WHEN** the side panel is inspected
- **THEN** the same OR audit-log component MUST render across
  every register; no per-register custom panel MUST exist.

### Requirement: REQ-AT-005: Audit-trail retention SHALL be governed by OR's archival + destruction workflow, not by a shillinq cleanup job

Per ADR-022, retention and destruction policies are OR's
abstraction — Archiefwet-aligned retention classification +
archival + purge workflow. Shillinq MUST NOT ship an audit-cleanup
job, an audit-purge controller, or any deletion logic against the
audit log.

The administration configures retention through OR's settings
(typically 7 years for Belastingdienst-mandated retention on
financial records, longer for archival classes); shillinq's
bookkeeping retention follows whatever OR's policy says for the
audited register types.

If the administration has not explicitly configured retention,
the OR default applies; the audit log persists indefinitely.

#### Scenario: Reviewer confirms no audit-cleanup code

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/BackgroundJob/*Audit*.php`,
  `lib/Service/*Retention*.php`, `lib/Cron/*Audit*.php`
- **THEN** no such files SHALL exist; retention comes from OR.

#### Scenario: Audit log respects OR retention policy

- **GIVEN** an administration has configured OR's retention to
  `7 years` for `objectType: APInvoice`
- **WHEN** OR's archival sweep runs on a 7+ year old AP invoice
  audit event
- **THEN** OR MUST apply its archival/destruction workflow per
  ADR-022; shillinq MUST NOT intervene.

### Requirement: REQ-AT-006: Retention disposition SHALL itself be an audited lifecycle event whose chain entry survives payload destruction

The append-only audit chain (REQ-AT-001..005) and the Archiefwet
retention engine described in capability
`bookkeeping-archiefwet-retention` (REQ-ARC-001..006) operate on
the same lifecycle: a regulated record is created, transitions
through working states, is archived, and is eventually
destroyed or anonymised per its `x-openregister-lifecycle.retention`
rule. The two abstractions MUST NOT contradict each other at that
end-of-life boundary; instead they MUST compose as follows:

1. **Retention disposition is itself an audited lifecycle event.**
   When OR's retention engine acts on a record under a rule from
   the `RetentionRule` register, the action (purge, archive,
   anonymise) MUST be emitted to the audit chain as a
   `lifecycle:<from>→<to>` event (e.g. `archived → purged` or
   `archived → anonymised`) per REQ-AT-002. The event MUST
   record `actor: system` (or the operator UUID for
   manually-triggered dispositions), the
   `selectielijstCode` + `legalBasis` of the applied rule (in
   the action metadata), the `timestamp`, and the
   `previousHash` + `eventHash` chain links.

2. **The hash chain survives the record's destruction.** The
   audit-chain entry for the disposition (and all prior entries
   in the chain for that `objectId`) MUST be retained even when
   the source record's payload is tombstoned or anonymised per
   the rule. The retained chain entry preserves the chain's
   end-to-end cryptographic verifiability (REQ-AT-002 hash
   verification, REQ-ARC-006) without preserving the underlying
   personal data. After disposition, the entry's
   `afterState` MUST be either `null` (destroy) or the
   anonymised payload (anonymise) — never the pre-disposition
   payload.

3. **Cross-reference: OR's retention engine MUST emit the
   disposition event to the audit chain BEFORE the purge or
   anonymisation completes.** This ordering ensures a tampered
   chain cannot be hidden by a too-eager purge: the disposition
   event itself is the proof that the rule was applied. Capability
   `bookkeeping-archiefwet-retention` (REQ-ARC-001, REQ-ARC-006)
   declares the same ordering from the retention-engine side;
   this REQ declares it from the audit-chain side.

shillinq MUST NOT implement the emit-then-purge ordering itself
(per ADR-022 and REQ-AT-005, retention is OR's responsibility);
shillinq declares the expectation as the consumer of both
abstractions.

#### Scenario: A GL transaction reaches retention end-of-life and the chain survives the payload

- **GIVEN** a `GLTransaction` created in 2017 with
  `x-openregister-lifecycle.retention` pointing at
  Selectielijst `5.1.2` (disposition: `archive` after 7 years,
  then `destroy` after the archival window per the rule)
- **AND** the record has accumulated N audit-chain events
  (create, posted transitions, archive)
- **WHEN** OR's retention engine sweeps in the year the
  destruction window closes
- **THEN** OR MUST first emit a `lifecycle:archived → purged`
  event to the audit chain with `actor: system`, the rule's
  `selectielijstCode: "5.1.2"` + `legalBasis` in the action
  metadata, `previousHash` linking to event N, and a fresh
  `eventHash`
- **AND** OR MUST then purge the source record's payload
- **AND** the audit chain entry (event N+1) MUST be retained as
  a tombstone (`afterState: null`) after the payload is gone
- **AND** OR's hash-chain verification API MUST still report
  `valid: true` for the surviving chain entries — the chain's
  verifiability MUST survive the record's destruction.

#### Scenario: An anonymisation disposition preserves the chain without preserving PII

- **GIVEN** a `UrenRegistratie` record with PII (`personId`)
  reaches the retention rule's anonymisation date (per
  REQ-ARC-004)
- **WHEN** OR's retention engine applies the rule
- **THEN** OR MUST emit a `lifecycle:archived → anonymised`
  event to the audit chain referencing the rule's
  `selectielijstCode` + `legalBasis` BEFORE clearing the PII
- **AND** the event's `afterState` MUST contain the anonymised
  payload (PII cleared or hashed; aggregate fields retained per
  REQ-ARC-004), never the pre-anonymisation payload
- **AND** the chain MUST remain end-to-end verifiable per
  REQ-AT-002.
