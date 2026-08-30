# Spec: bookkeeping-bcf-vat-compensation

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-vat-btw-filing (T3), bookkeeping-bbv-compliance (T3)

## ADDED Requirements

### Requirement: REQ-BCF-001: The system SHALL administer Btw-compensatiefonds claims as an OpenRegister-managed `BcfClaim` register

For administrations of type `gemeente`, `provincie`, or `waterschap`,
shillinq MUST provide a separate claim administration for the
Btw-compensatiefonds (BCF) per Wet BCF (Wet op het
btw-compensatiefonds). BCF is **distinct from regular BTW** — it
is a claim against the central BCF fund for VAT incurred on
overheidstaken (non-economic activities). The system MUST NOT
co-mingle BCF claims with `VatReturn` records.

Statutory basis: Wet op het btw-compensatiefonds (Wet BCF) +
Uitvoeringsregeling BCF.

#### Scenario: A non-municipal admin does not generate BCF claims

- **GIVEN** an administration with `administrationType: "mkb"`
- **WHEN** the BCF workflow's visibility predicate evaluates
- **THEN** the BCF claim workflow MUST be skipped.

#### Scenario: Reviewer confirms no co-mingling with VatReturn

- **GIVEN** the `VatReturn` schema (T3 bookkeeping-vat-btw-filing)
- **WHEN** scanned for BCF-specific fields
- **THEN** no `bcfAmount` or `bcfClaimable` field SHALL exist on
  `VatReturn` — BCF lives in its own register.

### Requirement: REQ-BCF-002: The `BcfClaim` schema SHALL declare a fixed minimum field set

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to gemeente/provincie/waterschap administration |
| `periodType` | enum | Yes | `quarter` (most common) or `month` |
| `periodStart` / `periodEnd` | date | Yes | The claim period |
| `claimLines` | array | Yes | Per-account aggregated compensable VAT — `accountNumber`, `taakveld`, `omzet`, `vatPaid`, `claimAmount` |
| `totalClaim` | number | Yes | Sum of `claimAmount` across lines; derived via `x-openregister-calculations` |
| `state` | enum | Yes | `draft`, `submitted`, `accepted`, `settled`, `rejected` |
| `submittedAt`, `acceptedAt`, `settledAt` | datetime | No | Lifecycle timestamps |
| `digikoppelingMessageId` | string | No | DigiKoppeling identifier on submission |
| `attachmentUri` | string | No | docudesk URI of the claim file (PDF or XML) |
| `correctionOf` | string | No | FK to a prior claim this one supersedes |

#### Scenario: A minimal draft claim validates

- **GIVEN** the schema
- **WHEN** an object with `administrationId: "gem-a"`, `periodType:
  "quarter"`, `periodStart: "2026-01-01"`, `periodEnd: "2026-03-31"`,
  `claimLines: [...]`, `state: "draft"` is created
- **THEN** validation MUST pass.

### Requirement: REQ-BCF-003: BCF compensable accounts SHALL be flagged via `BbvAccountMapping.bcfCompensable`

The set of compensable accounts MUST be determined by the
`bcfCompensable` boolean on each `BbvAccountMapping` record (per
T3 bookkeeping-bbv-compliance REQ-BBV-002). The default `false`
value is set on every seeded mapping; operators flip to `true` on
accounts whose VAT is recoverable per Wet BCF (typically all
non-economic / overheidstaken expense accounts; excluded:
economic activities, certain education + healthcare).

Per ADR-022, there MUST NOT be a parallel "compensable accounts"
table.

#### Scenario: A flagged account contributes to a claim

- **GIVEN** account `4100 ICT-kosten` is mapped with
  `bcfCompensable: true` in administration `gem-a`
- **AND** Q1 has GL postings of €10.000 net + €2.100 BTW against
  that account from an AP invoice
- **WHEN** the Q1 BCF claim is generated
- **THEN** a `claimLines` row MUST exist for account `4100` with
  `vatPaid: 2100` and `claimAmount: 2100` (assuming 100%
  compensability — see REQ-BCF-005 for partial).

#### Scenario: An unflagged account is excluded

- **GIVEN** account `4200 Subsidies onderwijs` has
  `bcfCompensable: false` (onderwijs is excluded per Wet BCF
  art. 4 lid 2)
- **WHEN** the Q1 claim is generated
- **THEN** no `claimLines` row for account `4200` SHALL appear.

### Requirement: REQ-BCF-004: Claim aggregation SHALL be a declarative aggregation over GL postings filtered by the compensable flag

The `claimLines` array MUST be populated via
`x-openregister-aggregations` over `GLLine` (T1) joined with
`Account` (T1) and `BbvAccountMapping` (T3) filtered by
`bcfCompensable = true` for the claim period. Per ADR-031, no
`BcfAggregationService`.

The aggregation projection MUST group by `(accountNumber,
taakveld)` and SUM the VAT-paid component of each line. The VAT
component is identified by the line's `vatTariffCode` (per T3
bookkeeping-vat-btw-filing REQ-VBTW-003) — only `21pct` and `9pct`
lines on compensable accounts contribute to the claim; `0pct`,
`vrij`, and `verlegd` lines do not.

#### Scenario: A Q1 claim correctly aggregates 21% and 9% lines

- **GIVEN** `gem-a` has Q1 postings with mixed BTW rates on
  compensable accounts: €5.000 at 21% (€1.050 VAT) and €2.000 at
  9% (€180 VAT)
- **WHEN** the Q1 claim aggregation runs
- **THEN** the total `claimAmount` MUST be €1.230 (sum of the
  recoverable VAT on flagged accounts).

### Requirement: REQ-BCF-005: Partial-compensability accounts SHALL be supported via a `compensablePercentage` field

Some accounts are partially compensable (mixed-use; e.g. an
account whose postings split between economic and non-economic
activities). The `BbvAccountMapping` schema (T3) MUST be extended
with an optional `compensablePercentage` field (number 0–100,
default 100 when `bcfCompensable: true`). The BCF aggregation
(REQ-BCF-004) MUST multiply each line's VAT by this percentage.

The percentage is an administration-level operator decision; the
spec does not prescribe a default per account.

#### Scenario: A 60% compensable account claims 60% of its VAT

- **GIVEN** account `4500 Onderhoud` is flagged
  `bcfCompensable: true` with `compensablePercentage: 60`
- **AND** a Q1 line has €1.000 VAT
- **WHEN** the Q1 claim aggregation runs
- **THEN** the `claimAmount` contribution from that line MUST be
  €600 (60% × €1.000).

### Requirement: REQ-BCF-006: The `BcfClaim` lifecycle SHALL be declarative per ADR-031

| From | To | Trigger | Guard |
|---|---|---|---|
| (new) | `draft` | scheduled workflow or operator trigger | none |
| `draft` | `submitted` | operator action | approval-workflow `requires` + claim arithmetic verification (`totalClaim` equals SUM(`claimLines[].claimAmount`)) |
| `submitted` | `accepted` | event from OpenConnector source `digikoppeling-bcf` | none |
| `submitted` | `rejected` | event from OpenConnector source `digikoppeling-bcf` | none |
| `accepted` | `settled` | event from OpenConnector source `digikoppeling-bcf` (settlement payment confirmed) | none |
| `accepted` | `corrected` | operator action creating a new claim with `correctionOf` set | none |

Per ADR-031 anti-pattern list, shillinq MUST NOT author a
`BcfClaimService`.

#### Scenario: An unbalanced claim fails submission

- **GIVEN** a draft claim where `totalClaim` does not match the
  sum of `claimLines[].claimAmount`
- **WHEN** the operator triggers `submit`
- **THEN** the transition MUST fail with a "claim arithmetic
  mismatch" error.

### Requirement: REQ-BCF-007: Submission to the BCF SHALL be an OR `ScheduledWorkflow` consuming the `digikoppeling-bcf` OpenConnector source

Per ADR-019 + ADR-022, the submission MUST consume an OpenConnector
source named `digikoppeling-bcf`. shillinq MUST NOT author a
custom DigiKoppeling client. The cron MUST default to quarterly
(`0 0 1 */3 *`), operator-configurable.

#### Scenario: The quarterly cron queues a draft claim for each gemeente

- **GIVEN** the date is the 1st of a calendar quarter
- **WHEN** the scheduled workflow fires
- **THEN** a `BcfClaim` MUST be queued for each gemeente/provincie/
  waterschap administration with at least one `bcfCompensable`
  posting in the prior quarter.

### Requirement: REQ-BCF-008: BCF claims SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Overheid >
BCF-claims` with a `type: index` page binding to `BcfClaim` and a
`type: detail` page showing the claim's `claimLines`, totals,
state, and lifecycle history. Visibility MUST be predicated on
`administrationType ∈ {gemeente, provincie, waterschap}`.

#### Scenario: The BCF index lists claims

- **GIVEN** the manifest declares the BCF pages
- **WHEN** a `bcf-administrator` opens `/index.php/apps/shillinq/bcf-claims`
- **THEN** the page MUST render via `CnIndexPage` with columns
  (periodEnd, state, totalClaim, submittedAt).

### Requirement: REQ-BCF-009: Audit trail and retention SHALL be consumed from OR's abstractions

Every `BcfClaim` operation MUST be audited via OR's
audit-trail-immutable (ADR-022). Retention MUST be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.4" }`
(verantwoording-naar-derden, typically 10 years per Selectielijst
Gemeenten 2020).

#### Scenario: A settled claim is retained for 10 years

- **GIVEN** a BCF claim settled in 2018
- **WHEN** queried in 2026 (within the 10-year retention)
- **THEN** the record MUST be returned with the full audit trail.
