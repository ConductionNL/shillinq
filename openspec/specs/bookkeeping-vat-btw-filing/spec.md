---
status: done
---

# Spec: bookkeeping-vat-btw-filing

**Status:** done
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-general-ledger (T1), bookkeeping-period-close (T2)
**OpenSpec changes**:
- [bookkeeping-vat-btw-filing](../../changes/archive/2026-06-14-bookkeeping-vat-btw-filing/) _(archived 2026-06-14)_
- [add-shillinq-bookkeeping-operations](../../changes/archive/2026-06-14-add-shillinq-bookkeeping-operations/) _(archived 2026-06-14)_
- [btw-suppletie-detection](../../changes/archive/2026-07-13-btw-suppletie-detection/) _(archived 2026-07-13)_

## Purpose

This specification defines the requirements for bookkeeping vat btw filing in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: BTW filing index page not yet implemented


### Requirement: REQ-VBTW-001 — The system SHALL store BTW returns as an OpenRegister-managed `VatReturn` register

BTW (omzetbelasting) periodic returns MUST be declared as a register
in `lib/Settings/shillinq_register.json` per ADR-024, with the
`VatReturn` schema as the canonical entity. No custom PHP model, no
custom database table, no parallel storage (ADR-022 anti-pattern
list applies). The register is exposed through OpenRegister's
generic CRUD HTTP surface; shillinq adds no per-app controller for
BTW filing.

Statutory basis: Wet OB 1968 (Wet op de omzetbelasting) art. 14 +
14a + 17 (periodieke aangifte; ICP; suppletie).

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `vat_`,
  `btw_`, or `aangifte_`
- **THEN** no such classes SHALL exist; all VAT data flows through
  the OR object API.

#### Scenario: A BTW return is created via OR's generic API

- **GIVEN** shillinq is installed and the `VatReturn` schema is loaded
- **WHEN** an authenticated `vat-administrator` POSTs a draft return
  to `/index.php/apps/openregister/api/objects/shillinq/VatReturn`
- **THEN** the save MUST succeed via OR's generic endpoint, with no
  shillinq-side controller in the call path.

### Requirement: REQ-VBTW-002 — The `VatReturn` schema SHALL declare a fixed minimum field set

The schema MUST declare the following fields. Additional fields MAY
be added later (additive only).

Schema.org annotation: `schema:Invoice` (the filing object — a periodic statement of VAT owed/refundable; the closest Schema.org type for a statutory monetary declaration. The act of filing is covered by REQ-VBTW-010's workflow.)

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to the administration owning the return |
| `periodType` | enum | Yes | `month` or `quarter` (operator-configured per administration) |
| `periodStart` | date | Yes | First day of the BTW period |
| `periodEnd` | date | Yes | Last day of the BTW period |
| `rubrieken` | object | Yes | The 7 standard BTW rubrieken (1a, 1b, 1c, 1d, 1e, 2a, 3a, 3b, 3c, 4a, 4b, 5a, 5b, 5c, 5d, 5e, 5f, 5g) per Belastingdienst aangifteformulier — each with `omzet` and (where applicable) `btw` |
| `verschuldigdeOmzetbelasting` | number | Yes | Calculated total payable; derived via `x-openregister-calculations` from rubrieken |
| `voorbelasting` | number | Yes | Input VAT deductible; derived from GL postings |
| `teBetalenOfTeruggave` | number | Yes | Net payable (positive) or refund (negative) |
| `state` | enum | Yes | `draft`, `submitted`, `accepted`, `rejected`, `corrected` |
| `submittedAt` | datetime | No | Set on transition to `submitted` |
| `acceptedAt` | datetime | No | Set on receipt of Belastingdienst ack |
| `digipoortMessageId` | string | No | SBR message identifier returned by Digipoort |
| `attachmentUri` | string | No | docudesk URI of the rendered aangifte PDF |
| `correctionOf` | string | No | FK to a prior `VatReturn.id` this one supersedes (only on `corrected`) |

#### Scenario: A minimal draft return validates

- **GIVEN** the `VatReturn` schema
- **WHEN** an object with `administrationId: "adm-1"`, `periodType:
  "quarter"`, `periodStart: "2026-01-01"`, `periodEnd: "2026-03-31"`,
  `rubrieken: {...}`, `state: "draft"` is validated
- **THEN** validation MUST pass.

#### Scenario: A submitted return without `submittedAt` is rejected

- **GIVEN** the schema's lifecycle precondition on the `submit` transition
- **WHEN** an object claiming `state: "submitted"` is saved without
  `submittedAt`
- **THEN** the save MUST fail with a precondition error.

### Requirement: REQ-VBTW-003 — BTW rate seed data SHALL be loaded as a register, not hard-coded as enums

A `VatTariff` register MUST be declared and seeded from
`lib/Settings/seeds/btw-tariffs-2026.json`. Each tariff record
carries `code` (e.g. `21pct`, `9pct`, `0pct`, `vrij`, `verlegd`),
`rate` (decimal — `0.21`, `0.09`, `0`, `0`, `0`), `description`,
`category` (`standaard`, `verlaagd`, `nul`, `vrijgesteld`,
`verleggingsregeling`), `effectiveFrom`, `effectiveTo` (nullable),
and the RGS account hints under `defaultAccounts`.

Schema.org annotation for `VatTariff`: `schema:PriceSpecification` (a tariff is a structured rate specification applied to monetary amounts).

Per ADR-031, rates are NOT baked as schema enums — they evolve
with statute (the 9% category was 6% before 2019; a future
`13pct` is plausible). Per-administration override is not allowed
(rates are statutory); operators MAY however add tariffs the seed
doesn't include (e.g. new EU-imposed rates).

#### Scenario: The seed file parses and validates

- **GIVEN** `btw-tariffs-2026.json`
- **WHEN** parsed as JSON
- **THEN** every record MUST validate against the `VatTariff` schema
  AND the file MUST contain at minimum the codes `21pct`, `9pct`,
  `0pct`, `vrij`, `verlegd`.

#### Scenario: An operator-added tariff coexists with seeded ones

- **GIVEN** an operator adds a new tariff `12pct` with
  `effectiveFrom: "2027-01-01"`
- **WHEN** a BTW return for Q1 2027 is computed
- **THEN** the rate MUST be available for selection on GL postings;
  re-running the repair step MUST NOT delete the operator-added record.

### Requirement: REQ-VBTW-004 — The BTW journal SHALL be derived from period-filtered GL aggregations

The `rubrieken` field of `VatReturn` MUST be populated via
`x-openregister-aggregations` over `GLLine` (T1) joined with
`Account` (T1) and tagged BTW-rate, filtered by the return's
`periodStart`/`periodEnd`. shillinq MUST NOT author a
`VatJournalService` walking the GL — per ADR-031, this is the
exact aggregation anti-pattern.

The mapping from GL account → rubriek is declared in
`btw-tariffs-2026.json`'s `defaultAccounts` field per tariff;
operator-edited tariff records override.

#### Scenario: A quarterly return aggregates the period's postings

- **GIVEN** a `VatReturn` for `2026-Q1` is created
- **WHEN** the aggregation runs
- **THEN** rubriek 1a (`Hoog tarief 21%`) MUST equal
  SUM(`GLLine.amount` WHERE `side='credit'` AND `Account` tagged
  `21pct` AND `periodId` in Q1).

#### Scenario: A correction return references the prior period

- **GIVEN** a Q1 return was submitted with an error
- **WHEN** the operator creates a `corrected` return with
  `correctionOf` pointing at the original
- **THEN** the new return's aggregations MUST re-read the GL as it
  stands now (including any post-submission corrections); the
  `teBetalenOfTeruggave` delta MUST equal the new total minus the
  submitted total.

### Requirement: REQ-VBTW-005 — The `VatReturn` lifecycle SHALL be declarative per ADR-031

The schema MUST declare an `x-openregister-lifecycle` block with the
following states and transitions:

- `draft` — operator is composing; aggregations recompute on each save
- `submitted` — handed to Digipoort/SBR; aggregations frozen
- `accepted` — Belastingdienst ack received via the
  `digipoort-sbr` OpenConnector source
- `rejected` — Belastingdienst nack received; operator must amend
  or supplete
- `corrected` — superseded by a later return (`correctionOf` FK)

Transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `submitted` | operator action | approval-workflow `requires` (per REQ-VBTW-006) + balance precondition (`teBetalenOfTeruggave` equals the rubriek arithmetic) |
| `submitted` | `accepted` | event from OpenConnector source `digipoort-sbr` | none |
| `submitted` | `rejected` | event from OpenConnector source `digipoort-sbr` | none |
| `rejected` | `draft` | operator action | none |
| `accepted` | `corrected` | operator action via creating a new `VatReturn` with `correctionOf` set | none |

Per ADR-031 anti-pattern list, shillinq MUST NOT author a
`VatReturnService::transition*` method. The lifecycle is the only
state machine.

#### Scenario: A draft transitions to submitted on operator action

- **GIVEN** a draft return whose `teBetalenOfTeruggave` matches the
  rubriek arithmetic and the approval gate has passed
- **WHEN** the operator triggers `submit`
- **THEN** the state MUST transition to `submitted`, `submittedAt`
  MUST be set, the audit trail MUST record the transition, AND the
  SBR submission workflow MUST be dispatched.

#### Scenario: A direct write to `state: "accepted"` is rejected

- **GIVEN** any actor (operator, integration, API client)
- **WHEN** they attempt to save a return with `state: "accepted"`
  via the generic OR API without going through the lifecycle
- **THEN** the save MUST fail with a "lifecycle transition required"
  error.

### Requirement: REQ-VBTW-006 — Submission gates SHALL consume OR's approval-workflow extension per ADR-022

The `draft → submitted` transition MUST declare
`x-openregister-lifecycle.requires.approval-workflow` with a policy
reference. The policy MAY route to a single approver (controller) or
to multiple approvers depending on administration policy. shillinq
MUST NOT author a custom approval table, queue, or notification
service — this is the ADR-022 anti-pattern.

#### Scenario: Submission without approval fails

- **GIVEN** a draft return whose administration policy requires
  approval
- **WHEN** the operator triggers `submit` before the controller has
  approved
- **THEN** the transition MUST fail with an "approval required"
  error surfaced by OR's approval-workflow engine.

#### Scenario: After approval, submission succeeds

- **GIVEN** the controller has approved the return via OR's
  approval-workflow UI
- **WHEN** the operator (or the workflow auto-trigger) triggers
  `submit`
- **THEN** the transition MUST succeed AND the audit trail MUST
  record both the approval event and the submission event.

### Requirement: REQ-VBTW-007 — The system SHALL provide ICP-opgaaf for intracommunautaire prestaties as a separate `IcpStatement` register

EU intracommunautaire prestaties MUST be filed quarterly via
ICP-opgaaf. T3 ships an `IcpStatement` schema with:

Schema.org annotation for `IcpStatement`: `schema:Invoice` (a periodic statutory statement of intra-EU supply totals — same Invoice-as-statement framing as `VatReturn`).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `periodStart` / `periodEnd` | date | Yes | Quarterly period |
| `lines` | array | Yes | One line per EU customer per period — `vatNumber` (EU-VAT), `countryCode` (ISO 3166-1 alpha-2), `omzet` (number), `type` enum (`goederen`, `diensten`, `driehoekshandel`) |
| `state` | enum | Yes | Same lifecycle as `VatReturn` (`draft`, `submitted`, `accepted`, `rejected`, `corrected`) |
| `submittedAt`, `acceptedAt`, `digipoortMessageId`, `correctionOf` | (same) | | |

The line aggregation MUST be derived from `Invoice` (T2 AR sub-ledger)
filtered by EU customers, per ADR-031 aggregation pattern. No
`IcpService`.

Statutory basis: Wet OB 1968 art. 37a (opgaaf intracommunautaire
prestaties).

#### Scenario: An ICP statement aggregates EU customers automatically

- **GIVEN** the AR sub-ledger (T2) contains invoices to a Belgian
  customer (`vatNumber: "BE0123456789"`) and a German customer
  (`vatNumber: "DE123456789"`) for Q1
- **WHEN** an `IcpStatement` for Q1 is generated
- **THEN** `lines` MUST contain at minimum two records, one per
  customer, with `omzet` equal to the sum of invoices' net amounts
  in the period.

#### Scenario: A domestic invoice does not appear in ICP

- **GIVEN** an invoice to a Dutch customer (`vatNumber: "NL...B01"`)
- **WHEN** the same Q1 ICP statement is generated
- **THEN** the Dutch customer MUST NOT appear in `lines`.

### Requirement: REQ-VBTW-008 — Verleggingsregeling (reverse-charge) handling SHALL be a tariff category, not a separate code path

Reverse-charge transactions (verleggingsregeling) MUST be modelled
as a `VatTariff` with `category: "verleggingsregeling"` and `rate: 0`.
GL postings with this tariff MUST appear in rubriek 2a (binnenland)
or 4a/4b (buitenland EU/non-EU) of the BTW return automatically via
the standard aggregation. No separate `ReverseChargeService`.

#### Scenario: A reverse-charge invoice posts to the right rubriek

- **GIVEN** an AP invoice from a non-EU subcontractor with
  `vatTariff: "verlegd-nietEU"` (a seeded variant)
- **WHEN** the BTW return aggregation runs for that period
- **THEN** the net amount MUST appear in rubriek 4b (`Leveringen/diensten
  uit landen buiten de EU`) and the same amount MUST appear as
  `voorbelasting` in rubriek 5b — the net effect on
  `teBetalenOfTeruggave` is zero per Belastingdienst rules.

### Requirement: REQ-VBTW-009 — Suppletie-aangifte SHALL be modelled as `VatCorrection` with mandatory link to the original return

A `VatCorrection` register MUST be declared for material corrections
(suppletie) where a previously-submitted return needs amendment.
Fields:

Schema.org annotation for `VatCorrection`: `schema:Invoice` (a corrective statutory statement; carries the same Invoice-as-statement framing as the original `VatReturn` it supersedes).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | |
| `originalVatReturnId` | string | Yes | FK to the `VatReturn` being corrected |
| `correctionAmount` | number | Yes | Delta (positive = additional payable, negative = additional refund) |
| `reason` | string | Yes | Operator-authored explanation |
| `state` | enum | Yes | `draft`, `submitted`, `accepted`, `rejected` |
| `submittedAt`, `acceptedAt`, `digipoortMessageId`, `attachmentUri` | (same as `VatReturn`) | | |

The suppletie threshold (€1.000 — corrections below this MAY be
absorbed in the next regular return) is seeded in
`btw-tariffs-2026.json`'s `_meta.suppletieThreshold` field. The
operator-facing UI MUST surface the threshold; the lifecycle MUST
NOT auto-decide whether a correction is suppletie-eligible (that's
an accountant judgment).

Statutory basis: Wet OB 1968 art. 14a + Uitvoeringsbesluit OB 1968
art. 24c.

#### Scenario: A material correction creates a `VatCorrection`

- **GIVEN** an accepted Q1 return contained an underreported
  voorbelasting of €1.500
- **WHEN** the operator creates a `VatCorrection` referencing the
  Q1 return with `correctionAmount: -1500`
- **THEN** the save MUST succeed AND the `VatCorrection` lifecycle
  MUST be reachable for `submit` like a regular `VatReturn`.

#### Scenario: Sub-threshold correction warning

- **GIVEN** an operator creates a `VatCorrection` with
  `correctionAmount: 500` (below the €1.000 suppletie threshold)
- **WHEN** the lifecycle precondition runs
- **THEN** the operator MUST see a warning suggesting they amend the
  next regular return instead; the operator MAY still proceed.

### Requirement: REQ-VBTW-010 — SBR/Digipoort submission SHALL be an OR `ScheduledWorkflow` consuming an OpenConnector source

The actual SBR/Digipoort submission MUST be expressed as an OR
`ScheduledWorkflow` (per ADR-031 §"Background jobs that orchestrate
external systems") consuming an OpenConnector source named
`digipoort-sbr`. shillinq MUST NOT author a PHP `DigipoortClient` or
`SbrSubmissionService` — that is the ADR-019 anti-pattern.

The workflow is declared in `shillinq_register.json` under the
`x-openregister-workflows` key (or the equivalent OR convention),
referencing the source by symbolic name. The OpenConnector source
registration lands in a separate change (`add-openconnector-nl-
overheid-sources`) and is not blocking for the spec but IS blocking
for the implementing cycle's first end-to-end test.

#### Scenario: Submission dispatches via OpenConnector

- **GIVEN** a `VatReturn` transitions `draft → submitted`
- **WHEN** the lifecycle's `onTransition` hook fires
- **THEN** a workflow MUST be dispatched targeting the
  `digipoort-sbr` source with the rendered SBR XML payload AND the
  shillinq code path MUST NOT contain a direct HTTP call to
  `digipoort.nl` or `belastingdienst.nl`.

#### Scenario: Reviewer scans for forbidden HTTP

- **GIVEN** the shillinq codebase post-implementation
- **WHEN** scanned for `curl_init`, `Http::request`, `GuzzleHttp\Client`,
  or hardcoded `digipoort.nl` / `logius.nl` / `belastingdienst.nl`
  URLs in `lib/`
- **THEN** no matches SHALL exist (the workflow is the only path).

### Requirement: REQ-VBTW-011 — BTW filing SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Belastingen >
BTW-aangiften` with a `type: index` page binding to `VatReturn`, a
`type: detail` page for individual returns, and secondary entries
for `ICP-opgaaf` (binding `IcpStatement`) and `BTW-correcties`
(binding `VatCorrection`). All MUST be rendered by the generic
`@conduction/nextcloud-vue` page renderers per ADR-024 Tier-4.

#### Scenario: The BTW index lists returns

- **GIVEN** the manifest declares the BTW pages
- **WHEN** a `vat-administrator` opens
  `/index.php/apps/shillinq/btw-aangiften`
- **THEN** the page MUST render via `CnIndexPage` showing the
  administration's returns with columns (periodEnd, state,
  teBetalenOfTeruggave).

#### Scenario: Visibility predicate hides BTW for administrations not subject to BTW

- **GIVEN** an administration with `vatRegime: "kor"` (KOR opt-in
  per REQ-KOR-003) AND `state: "opted-in"`
- **WHEN** the operator opens the dashboard
- **THEN** the BTW menu entry MAY be hidden (or shown with a
  "vrijgesteld" badge) per the manifest's visibility predicate.

### Requirement: REQ-VBTW-012 — The audit trail and retention SHALL be consumed from OR's abstractions

Every `VatReturn`, `IcpStatement`, `VatCorrection`, and `VatTariff` operation MUST be audited via OR's audit-trail-immutable (per ADR-022) — shillinq MUST NOT write to a private audit table.

Retention MUST be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }`
referencing the seed rule for financial records (7 years per
Selectielijst Gemeenten 2020 + AWR art. 52 for non-municipal
administrations).

#### Scenario: A submitted return is queryable after 6 years

- **GIVEN** a return submitted in 2020
- **WHEN** queried in 2026 (within the 7-year retention)
- **THEN** the record MUST be returned with its full audit trail
  intact.

#### Scenario: A return past retention is archived per OR's lifecycle

- **GIVEN** a return submitted in 2017 (past 7 years in 2026)
- **WHEN** OR's retention engine sweeps
- **THEN** the record MUST be archived (or anonymised, depending on
  the rule's disposition) per the Selectielijst rule, AND the audit
  trail's immutable hash chain MUST remain verifiable.

### Requirement: REQ-VBTW-013 — The system SHALL detect drift between a filed `VATReturn` and its underlying GL ledger

`VatSuppletieDetectionService::detect()` MUST accept a filed (submitted or
later) `VATReturn` id, recompute the same GL-derived per-rubriek grouping
`VATReturnService::deriveVATLines()` produces (grouped by `type` × `taxRate`)
**without persisting it**, and diff it bucket-by-bucket against the
`VATDeclaration` rows already persisted for that return (the as-filed
snapshot, stable because nothing re-derives them outside an explicit
`rebase`). The method MUST create a `VatCorrection` in `draft` state
carrying both the filed snapshot and the current snapshot, and MUST NOT
mutate the original `VATReturn`, its `VATDeclaration`s, or its `VATLine`s.
When the two snapshots are identical (no drift), `detect()` MUST return
without creating a `VatCorrection`.

#### Scenario: Drift is detected after a late-posted GL transaction

- **GIVEN** a `VATReturn` submitted for Q1-2026 with a persisted
  `VATDeclaration` of `21% collected: €3.150,00`
- **AND** a new `GLTransaction` posted after submission adds €500,00 of
  taxable revenue at 21% within the Q1-2026 date range on a
  `vatApplicable` account
- **WHEN** `VatSuppletieDetectionService::detect()` runs for that return
- **THEN** a `VatCorrection` MUST be created in `draft` state with
  `filedSnapshot` containing the original €3.150,00 bucket and
  `currentSnapshot` containing the recomputed €3.255,00 bucket (21% of the
  extra €500,00 = €105,00 collected VAT added)
- **AND** the original `VATReturn`'s `VATDeclaration` rows MUST remain
  unchanged.

#### Scenario: No drift produces no correction

- **GIVEN** a filed `VATReturn` whose GL data has not changed since filing
- **WHEN** `detect()` runs
- **THEN** no `VatCorrection` MUST be created.

@e2e exclude pure backend/data: GL diff computation is not browser-testable

### Requirement: REQ-VBTW-014 — The system SHALL compile per-rubriek deltas, decide suppletie-eligibility against the €1.000 grens, and stage a GL correction posting

`VatSuppletieDetectionService::prepare()` MUST take a `detected` (draft,
`preparedAt` null) `VatCorrection`, compute `rubriekDeltas` (one entry per
`type:taxRate` bucket with a non-zero difference between filed and current
snapshots), sum them into a net `correctionAmount`/`adjustmentAmount`, set
`thresholdExceeded` to `true` when `abs(correctionAmount) >= 1000` (the
statutory suppletie grens — see Notes) and `false` otherwise, stamp a
`filingDeadline` of `preparedAt + 8 weeks` (per the Belastingdienst's
discovery-to-filing obligation), and create a companion `draft`
`GLTransaction` with one balanced `GLLine` per non-zero rubriek delta
against the account originally used for that bucket, offset by a clearing
account. The method MUST NOT auto-post the `GLTransaction` and MUST NOT
auto-transition the `VatCorrection` past `draft` — the operator decides
whether and when to file, per REQ-VBTW-009's existing non-auto-decide rule.

#### Scenario: Above-grens correction is flagged threshold-exceeded with an 8-week deadline

- **GIVEN** a `detected` `VatCorrection` whose recomputed delta nets to
  €1.450,00 additional payable
- **WHEN** `prepare()` runs
- **THEN** `thresholdExceeded` MUST be `true`
- **AND** `filingDeadline` MUST be set to 8 weeks after `preparedAt`
- **AND** a `draft` `GLTransaction` MUST exist with balanced `GLLine`s
  summing to €1.450,00.

#### Scenario: Below-grens correction is flagged as next-return-eligible

- **GIVEN** a `detected` `VatCorrection` whose recomputed delta nets to
  €320,00
- **WHEN** `prepare()` runs
- **THEN** `thresholdExceeded` MUST be `false`
- **AND** the `VatCorrection` MUST still be fully compiled (deltas, GL
  posting) so the operator can choose to file anyway or fold it into the
  next return.

#### Scenario: The audit trail preserves the original filed figures

- **GIVEN** a `VatCorrection` compiled from a `VATReturn` originally filed
  with `totalVATCollected: €3.150,00`
- **WHEN** an auditor inspects the `VatCorrection`
- **THEN** `filedSnapshot` MUST reproduce the exact figures that were true
  at filing time, independent of any later GL changes
- **AND** the `VatCorrection` object itself MUST be covered by OR's
  immutable audit-trail (already satisfied by
  `add-shillinq-audit-trail.json`'s `x-openregister-audit-trail.enabled`
  flag per REQ-VBTW-012 — confirmed, not modified, by this change).

@e2e exclude pure backend/data: threshold decision + GL posting compilation is not browser-testable

## Notes

- **€1.000 threshold, verified 2026-07-13 via WebSearch against
  belastingdienst.nl** (not from training-data memory): "Hebt u bij uw
  btw-aangifte een bedrag van maximaal €1000 te veel of te weinig ingevuld?
  Geef dit dan aan in uw eerstvolgende btw-aangifte." Source:
  https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/btw/btw_aangifte_doen_en_betalen/aangifte_corrigeren/
  — corrections at or above €1.000 require the formal suppletie form and,
  per the Belastingdienst's 1 January 2025 update, must be filed within
  8 weeks of discovery or risk a "vergrijpboete" (up to 100% of the
  underpaid amount).
- This delta bridges a pre-existing dual-schema situation
  (`VATReturn`/all-caps vs. `VatReturn`/mixed-case) documented in
  `design.md`; `originalVatReturnId` on the compiled `VatCorrection`
  points at the all-caps `VATReturn.id` because that is the only schema
  with real, computed per-rubriek data today.
- Status lifecycle `detected → prepared → filed` is layered on the
  already-landed `draft/submitted/accepted/rejected` states via
  `preparedAt` rather than new states — see `design.md` Decision 3.
