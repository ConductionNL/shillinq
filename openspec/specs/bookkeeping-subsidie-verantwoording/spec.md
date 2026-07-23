---
status: done
---

# Spec: bookkeeping-subsidie-verantwoording

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-general-ledger (T1)

## Purpose

This specification defines the requirements for bookkeeping subsidie verantwoording in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.
## Requirements

@e2e exclude pure backend/compliance: subsidie verantwoording — not browser-testable

### REQ-SUB-001: The system SHALL administer grants/subsidies as an OpenRegister-managed `Subsidie` register

shillinq MUST provide a grant administration covering the full
ASV-model lifecycle (aanvraag → verleend → vastgesteld →
uitbetaald → eventueel teruggevorderd) per Awb 4.2 + the VNG
ASV-model. The administration MUST work for both **outgoing**
grants (a gemeente granting to a beneficiary) and **incoming**
grants (a beneficiary receiving from a granting body) — the
`direction` field distinguishes.

Statutory basis: Algemene wet bestuursrecht (Awb) afdeling 4.2
(subsidies) + VNG ASV-model 2022.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `subsidie_`,
  `grant_`, `subsidy_`
- **THEN** no such classes SHALL exist.

### REQ-SUB-002: The `Subsidie` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `Subsidie` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `direction` | enum | Yes | `outgoing` (admin grants) or `incoming` (admin receives) |
| `subsidieNumber` | string | Yes | Unique per administration + tax year |
| `counterpartyName` | string | Yes | Beneficiary (outgoing) or granting body (incoming) |
| `counterpartyId` | string | No | FK to a contact record if available |
| `regelingNaam` | string | Yes | Name of the underlying regeling (e.g. "Subsidieregeling cultuur 2026") |
| `regelingArtikel` | string | No | Specific article reference within the regeling |
| `aanvraagDate` | date | Yes | Date of the aanvraag |
| `beschikkingDate` | date | No | Date of the verleningsbeschikking |
| `vaststellingDate` | date | No | Date of the vaststellingsbeschikking |
| `aangevraagdBedrag` | number | Yes | Applied-for amount |
| `verleendBedrag` | number | No | Granted amount (set on `verleen`) |
| `vastgesteldBedrag` | number | No | Settled amount (set on `vaststel`) |
| `uitbetaaldBedrag` | number | No | Paid amount (set on `uitbetaal`) |
| `teruggevorderdBedrag` | number | No | Reclaimed amount (set on `terugvorder`) |
| `state` | enum | Yes | `aanvraag`, `verleend`, `vastgesteld`, `uitbetaald`, `teruggevorderd`, `afgehandeld` |
| `beschikkingUri` | string | No | docudesk URI of the verleningsbeschikking PDF |
| `vaststellingUri` | string | No | docudesk URI of the vaststellingsbeschikking PDF |
| `prestatieverantwoording` | string | No | Free-text or structured field describing the prestatieverantwoording |
| `repaymentPlanId` | string | No | FK to a `RepaymentInstallment` parent record if a settlement plan applies |

#### Scenario: A minimal aanvraag validates

- **GIVEN** the schema
- **WHEN** an object with `administrationId: "gem-a"`, `direction:
  "outgoing"`, `subsidieNumber: "SUB-2026-001"`, `counterpartyName:
  "Stichting Cultuur"`, `regelingNaam: "Subsidieregeling cultuur
  2026"`, `aanvraagDate: "2026-02-01"`, `aangevraagdBedrag: 25000`,
  `state: "aanvraag"` is created
- **THEN** validation MUST pass.

### REQ-SUB-003: The `Subsidie` lifecycle SHALL be declarative per ADR-031

The system SHALL satisfy this requirement: The `Subsidie` lifecycle SHALL be declarative per ADR-031.

| From | To | Trigger | Guard |
|---|---|---|---|
| (new) | `aanvraag` | operator action (admin records the incoming/outgoing aanvraag) | none |
| `aanvraag` | `verleend` | operator action | approval-workflow `requires` (subsidie-coordinator + manager dual control) + `verleendBedrag` MUST be set + `beschikkingUri` SHOULD be set |
| `aanvraag` | `afgehandeld` | operator action (afwijzing) | reason field MUST be set |
| `verleend` | `vastgesteld` | operator action | `prestatieverantwoording` MUST be set + `vastgesteldBedrag` MUST be set + `vaststellingUri` SHOULD be set |
| `vastgesteld` | `uitbetaald` | operator action | `uitbetaaldBedrag` MUST equal `vastgesteldBedrag` (or operator-justified discrepancy) + journal-entry to AP/AR sub-ledger MUST exist |
| `uitbetaald` | `teruggevorderd` | operator action (typically on findings post-uitbetaling) | `teruggevorderdBedrag` MUST be set + reason field MUST be set + approval-workflow `requires` |
| `uitbetaald` | `afgehandeld` | calendar trigger (after the retention period of obligations) | none |
| `teruggevorderd` | `afgehandeld` | operator action (when fully repaid) | repaymentBalance MUST equal 0 |

Per ADR-031 anti-pattern list, shillinq MUST NOT author a
`SubsidieLifecycleService`.

#### Scenario: A direct `aanvraag → uitbetaald` skip fails

- **GIVEN** a `Subsidie` in `state: "aanvraag"`
- **WHEN** the operator attempts to transition directly to
  `uitbetaald`
- **THEN** the transition MUST fail (no direct path is declared).

### REQ-SUB-004: Approval gates on `verleend` and `teruggevorderd` SHALL consume OR's approval-workflow

The system SHALL satisfy this requirement: Approval gates on `verleend` and `teruggevorderd` SHALL consume OR's approval-workflow.

The `aanvraag → verleend` and `uitbetaald → teruggevorderd`
transitions MUST declare
`x-openregister-lifecycle.requires.approval-workflow` blocks.
Default policy: subsidie-coordinator initiates, manager approves
(dual control). Per ADR-022, no app-local approval table.

#### Scenario: Verlening without approval fails

- **GIVEN** a `Subsidie` in `aanvraag` whose administration
  requires manager approval on verlening
- **WHEN** the subsidie-coordinator triggers `verleen` before
  manager approval
- **THEN** the transition MUST fail with "approval required"
  surfaced from OR's approval-workflow.

### REQ-SUB-005: The uitbetaling SHALL create a GL posting via a journal entry, not a parallel payment table

The `vastgesteld → uitbetaald` transition MUST create a T1
`JournalEntry` (bookkeeping-journal-entries) of sub-type `manual`
that posts the cash leg: debit subsidiekosten (outgoing) or
credit cash + debit subsidieopbrengsten (incoming). Per ADR-022,
there MUST NOT be a parallel "subsidie payments" table; the
payment IS a journal entry.

The journal entry MUST be created in `state: "pending"` —
operator approval still gates the post (per T1 REQ-JE-008).

#### Scenario: Uitbetaling materialises a pending journal

- **GIVEN** a `Subsidie` transitions `vastgesteld → uitbetaald` for
  an outgoing grant of €15.000
- **WHEN** the post-transition action fires
- **THEN** a `JournalEntry` MUST exist with `state: "pending"`,
  two balanced lines (debit subsidiekosten €15.000, credit cash
  €15.000), referencing the `Subsidie.id` in `sourceReference`.

### REQ-SUB-006: ASV-model lifecycle state metadata SHALL ship as seed data

`lib/Settings/seeds/asv-model-lifecycle.json` MUST hold the
canonical state-to-Awb-citation mapping:

| State | Awb article | Description |
|---|---|---|
| `aanvraag` | Awb 4:5 | Aanvraag indiening |
| `verleend` | Awb 4:43 / 4:48 | Verlening of weigering |
| `vastgesteld` | Awb 4:46 | Vaststelling |
| `uitbetaald` | Awb 4:52 | Bevoorschotting / betaling |
| `teruggevorderd` | Awb 4:57 | Terugvordering |
| `afgehandeld` | (administrative) | Sluiten dossier |

The seed allows the UI to surface "Vereisten voor deze stap" per
Awb article and gives reviewers a citation table for compliance
audits.

#### Scenario: The seed parses and validates

- **GIVEN** `asv-model-lifecycle.json`
- **WHEN** parsed as JSON
- **THEN** every record MUST validate AND the file MUST contain
  at minimum the 6 canonical lifecycle states with their Awb
  citations.

### REQ-SUB-007: A repayment-plan (afbetalingsregeling) on terugvordering SHALL be a separate `RepaymentInstallment` register linked by FK

The system SHALL satisfy this requirement: A repayment-plan (afbetalingsregeling) on terugvordering SHALL be a separate `RepaymentInstallment` register linked by FK.

When a terugvordering leads to a settlement plan
(afbetalingsregeling), the system MUST create a
`RepaymentInstallment` parent record with N child instalment
records. The parent FK is stored on `Subsidie.repaymentPlanId`.
Per ADR-022, no parallel state machine — the parent is a register
record, the instalments are register records, the relations are
`x-openregister-relations`.

The `RepaymentInstallment` schema MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `subsidieId` | string | Yes | FK back to `Subsidie.id` |
| `installmentNumber` | integer | Yes | Sequential (1, 2, 3…) |
| `dueDate` | date | Yes | When this instalment is due |
| `amount` | number | Yes | Instalment amount |
| `state` | enum | Yes | `scheduled`, `paid`, `overdue`, `cancelled` |
| `paidDate` | date | No | Set on transition to `paid` |
| `journalEntryId` | string | No | Back-reference to the T1 journal entry posting the receipt |

#### Scenario: A terugvordering with 6-month plan creates 6 instalments

- **GIVEN** a `Subsidie` is in `teruggevorderd` for €6.000 with a
  6-month repayment plan
- **WHEN** the plan is created
- **THEN** 6 `RepaymentInstallment` records MUST exist with
  `installmentNumber` 1..6 and `amount: 1000` each.

#### Scenario: An overdue instalment auto-flags

- **GIVEN** an instalment with `dueDate: "2026-03-15"` and
  `state: "scheduled"`
- **WHEN** today is 2026-03-16
- **THEN** the instalment's calculated `isOverdue` derived field
  MUST be `true` AND a notification SHOULD fire (per
  `x-openregister-notifications`).

### REQ-SUB-008: Subsidie administration SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Subsidies`
with:

- `type: index` on `Subsidie` (outgoing + incoming, filterable by
  `direction`).
- `type: detail` on `Subsidie` showing the full lifecycle + the
  repayment-plan child grid if applicable.

Visibility predicated on `administrationType ∈ {gemeente,
provincie, waterschap, mkb, zzp}` (every operator may either
grant or receive subsidies).

#### Scenario: A subsidie-coordinator filters by direction

- **GIVEN** a `subsidie-coordinator` opens the Subsidies index
- **WHEN** they filter on `direction: outgoing`
- **THEN** only outgoing grants MUST be listed.

### REQ-SUB-009: Audit trail and retention SHALL be consumed from OR's abstractions

Every `Subsidie` and `RepaymentInstallment` operation MUST be
audited via OR's audit-trail-immutable (ADR-022). Retention MUST
be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:3.5.1" }`
(subsidie-records, typically 10 years after settlement per
Selectielijst Gemeenten 2020 + Awb 4:52). The relative trigger
("10 years after settlement") MUST be resolved via the OR
lifecycle engine using `vaststellingDate` (or `paidDate` of the
last instalment if a repayment plan applies).

#### Scenario: A historical subsidie remains queryable for 10 years

- **GIVEN** a subsidie vastgesteld in 2015 with no terugvordering
- **WHEN** queried in 2024 (within 10-year retention)
- **THEN** the record MUST be returned with full audit trail.

#### Scenario: A subsidie past retention is archived per OR's lifecycle

- **GIVEN** a subsidie vastgesteld in 2010
- **WHEN** OR's retention engine sweeps after 2020-12-31
- **THEN** the record MUST be archived per the Selectielijst rule
  AND the audit trail's hash chain MUST remain verifiable.

### REQ-SUB-010: Notifications on lifecycle transitions SHALL fire via `x-openregister-notifications`

The system SHALL satisfy this requirement: Notifications on lifecycle transitions SHALL fire via `x-openregister-notifications`.

Each lifecycle transition (`verleend`, `vastgesteld`, `uitbetaald`,
`teruggevorderd`, repayment-instalment `overdue`) MUST declare an
`x-openregister-notifications` block targeting the appropriate
role (`subsidie-coordinator`, optionally the `counterpartyId`'s
user-account if linked). Per ADR-022, no app-local notification
service.

#### Scenario: Verlening notifies the counterparty user

- **GIVEN** a `Subsidie` transitions `aanvraag → verleend` AND
  the counterparty has a linked NC user account
- **WHEN** the notification fires
- **THEN** that user MUST receive an NC notification of the form
  "Uw subsidie-aanvraag is verleend (€15.000)".

### Requirement: SubsidieVerantwoording notification rules SHALL use the canonical `x-openregister-notifications` dialect

The `SubsidieVerantwoording` and `AuditorStatement` schemas' notification rules MUST declare `trigger` as a structured object
(`{"type": "transition", "action": "<name>"}`, matching
`OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher`'s
`matches(array $triggerSpec, ...)` contract) — NOT a bare lifecycle string
(`"lifecycle.submit"`). Every rule MUST declare `recipients` as a non-empty
array of `{kind: ...}` objects — NOT a singular `recipient` object. A rule
resolving recipients through a role name (`finance-officer`,
`subsidie-coordinator`, `administration-treasurer`) MUST use
`{"kind": "expression", "resolver": "OCA\\Shillinq\\Notification\\RoleFallbackResolver::<role>"}`,
where `RoleFallbackResolver` implements the original resolver-then-fallback
NC-group semantics in PHP.

@e2e exclude declarative notification config: JSON register fragment + PHP resolver class, asserted via unit test + JSON-schema validation, not UI

#### Scenario: SubsidieVerantwoording notification rules pass OpenRegister's annotation validator

- **GIVEN** the `SubsidieVerantwoording` and `AuditorStatement` schemas in `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`
- **WHEN** `OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator` validates every `x-openregister-notifications` rule on both schemas
- **THEN** validation MUST pass with zero `notification-no-recipients` / `notification-recipient-malformed` / `notification-bad-recipient-kind` errors

#### Scenario: Finance officer is notified when a report is submitted

- **GIVEN** a `SubsidieVerantwoording` object in state `draft`
- **WHEN** an operator transitions it to `submitted` (the `submit` lifecycle action)
- **THEN** `AnnotationNotificationDispatcher` MUST resolve at least one recipient via `RoleFallbackResolver::finance-officer` (falling back to the configured fallback NC group when the primary group has zero members) and dispatch the declared notification — replacing the current silent no-op caused by the missing `recipients` key

<!--
  OUT OF SCOPE (deferred to follow-up): the declarative `isOverdue` calculated
  field + `onOverdue` scheduled rule are NOT part of this narrowed change —
  they need either a new `awardDate` schema field or a string-split calc
  operator OpenRegister does not have (see tasks 3.1/3.2). ⚠️ FOLLOW-UP GAP:
  the bespoke `OverdueVerantwoordingJob` was already deleted on development, so
  overdue-report notification currently has NO implementation (neither the job
  nor the declarative replacement). This must be restored by the follow-up
  change.
-->

### Requirement: SubsidieVerantwoording SHALL declare a real awardDate field and a declarative isOverdue calculation

`SubsidieVerantwoording` MUST declare a nullable `awardDate` (`string`,
`format: date`) property — a grant-award-date snapshot copied from the
`Subsidie` grant, following the same pattern as `awardedAmount`. The schema
MUST declare `x-openregister-calculations.daysSinceAward` (materialised,
integer, `{"diffDays": [{"now": []}, {"prop": "awardDate"}]}`) and
`x-openregister-calculations.isOverdue` (materialised, boolean,
`{"and": [{"ne": [status, "final"]}, {"gt": [daysSinceAward, 90]}]}`),
restoring the 90-day accountability deadline rule (REQ-SUBV-010) the
retired `OverdueVerantwoordingJob::isOverdue()` implemented imperatively.
Every operator used MUST be present in
`OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator::VALID_OPS`.
A null or unparseable `awardDate` MUST resolve `isOverdue` to `false`
(never overdue) — no reportingPeriod-string-split fallback is implemented.
`x-openregister-lifecycle.final` MUST declare `["final"]` so
`TemporalCalculationSweepJob`'s hourly re-evaluation (required to keep the
`now`-dependent `isOverdue` live without an object write) excludes
already-finalised records.

@e2e exclude declarative schema + calc config: JSON register fragment, asserted via unit test (schema-shape assertions + a functional mirror-evaluator against the retired job's original test matrix), not UI

#### Scenario: A non-final report more than 90 days past award is flagged overdue

- **GIVEN** a `SubsidieVerantwoording` object with `status: draft` (or `submitted`/`approved`) and `awardDate` more than 90 days before the evaluation instant
- **WHEN** `x-openregister-calculations.daysSinceAward` and `.isOverdue` are evaluated (at save time by `CalculationOnSaveListener`, or by `TemporalCalculationSweepJob`'s hourly sweep for an untouched object)
- **THEN** `isOverdue` MUST materialise to `true`

#### Scenario: A final report is never overdue regardless of age

- **GIVEN** a `SubsidieVerantwoording` object with `status: final` and any `awardDate`, however old
- **WHEN** `isOverdue` is evaluated
- **THEN** `isOverdue` MUST materialise to `false`

#### Scenario: A record with no awardDate is never overdue

- **GIVEN** a `SubsidieVerantwoording` object with `awardDate` null or absent (e.g. a pre-existing record created before this field existed)
- **WHEN** `isOverdue` is evaluated
- **THEN** `daysSinceAward` MUST resolve to `null` and `isOverdue` MUST materialise to `false`, with no attempt to derive a reference date from `reportingPeriod`

### Requirement: SubsidieVerantwoording SHALL fire a daily overdue reminder to the assigned approver

The schema MUST declare `x-openregister-notifications.onOverdue` with
`trigger: {type: scheduled, intervalSec: 86400, filter: {isOverdue: true}}`
and `recipients: [{kind: field, field: approverUserId}]`, restoring the
retired `OverdueVerantwoordingJob`'s daily (24h) re-check cadence and
recipient resolution declaratively (ADR-031). A record with no assigned
`approverUserId` MUST resolve to zero recipients (matching the job's
original fail-closed behaviour), not an error.

@e2e exclude declarative notification config: JSON register fragment, asserted via unit test (schema-shape assertions against NotificationAnnotationValidator's documented shape rules), not UI

#### Scenario: The assigned approver is reminded daily while a report remains overdue

- **GIVEN** a `SubsidieVerantwoording` object with `isOverdue: true` and a non-empty `approverUserId`
- **WHEN** `ScheduledNotificationJob` evaluates the `onOverdue` rule's `filter` against the object's stored data
- **THEN** `AnnotationNotificationDispatcher` MUST dispatch the notification to the `approverUserId` user, re-firing again after each subsequent `intervalSec` while the object still matches the filter

#### Scenario: An overdue record with no assigned approver receives no notification

- **GIVEN** a `SubsidieVerantwoording` object with `isOverdue: true` and an empty/absent `approverUserId`
- **WHEN** `ScheduledNotificationJob` evaluates the `onOverdue` rule
- **THEN** zero recipients are resolved and no notification is dispatched for that object

### Requirement: SubsidieVerantwoording and AuditorStatement notification rules SHALL declare only validator-recognised channels

Every `x-openregister-notifications` rule on both schemas in this fragment MUST declare `channels` using
only values in `NotificationAnnotationValidator::VALID_CHANNELS` (`nc-notification, email,
activity, webhook, talk, web-push`). The literal `"in-app"` MUST NOT
appear as a channel value anywhere in this fragment — it is not recognised
by `NotificationAnnotationValidator` or `AnnotationNotificationDispatcher`,
so a rule declaring it never renders an in-app Nextcloud notification.

@e2e exclude declarative notification config: JSON register fragment, asserted via unit test (regression lock grepping the raw fragment + validating every declared channel), not UI

#### Scenario: No notification rule in this fragment uses the invalid "in-app" channel string

- **GIVEN** every `x-openregister-notifications` rule declared on `SubsidieVerantwoording` and `AuditorStatement` in `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`
- **WHEN** each rule's `channels` array is checked against `VALID_CHANNELS`
- **THEN** every declared channel MUST be one of `nc-notification`, `email`, `activity`, `webhook`, `talk`, `web-push`, and none MUST be the string `"in-app"`

