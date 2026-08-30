# Spec: bookkeeping-iv3-reporting

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-bbv-compliance (T3), bookkeeping-period-close (T2)

## ADDED Requirements

### Requirement: REQ-IV3-001: The system SHALL produce a quarterly Informatie voor Derden (IV3) export to CBS for municipal administrations

For administrations of type `gemeente`, `provincie`, or `waterschap`,
shillinq MUST produce a quarterly IV3 export to CBS per BBV mandate
(Wet Fido art. 8 + Besluit BBV art. 71 + huidige IV3-bestand
specificaties van CBS). The export MUST be declared as a
register-managed entity (`Iv3Export`) with a lifecycle covering
generation, validation, submission to CBS, and acceptance/rejection.
No bespoke `Iv3Service` or `CbsExportService` — per ADR-031, the
aggregation + XML generation are declarative.

Statutory basis: Wet Fido art. 8 (rapportageplicht) + Besluit BBV
art. 71 + Regeling informatie voor derden (Regeling I) +
IV3-bestand specificaties (current revision).

#### Scenario: A gemeente generates a Q1 IV3 export

- **GIVEN** administration `gem-a` (type `gemeente`) with closed
  fiscal periods for Q1 2026 (per T2 period-close)
- **WHEN** the IV3 quarterly workflow runs (or the operator
  triggers it manually)
- **THEN** an `Iv3Export` record MUST be created for Q1 2026 with
  `state: "generated"` and a referenced XML artifact in docudesk.

#### Scenario: A non-municipal admin does not generate IV3

- **GIVEN** an administration with `administrationType: "mkb"`
- **WHEN** the IV3 workflow's visibility predicate evaluates
- **THEN** the workflow MUST be skipped for that administration.

### Requirement: REQ-IV3-002: The `Iv3Export` schema SHALL declare a fixed minimum field set

Schema.org annotation: `schema:Dataset` (the `Iv3Export` register models
the IV3 data bestand submitted to CBS, with `buckets` as the aggregated
payload and `xmlAttachmentUri` as the serialised distribution).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `reportingYear` | integer | Yes | Calendar year (e.g. 2026) |
| `reportingQuarter` | enum | Yes | `Q1`, `Q2`, `Q3`, `Q4` |
| `iv3Version` | string | Yes | CBS IV3 specification version (e.g. `"2026.1"`) |
| `buckets` | object | Yes | Aggregated values keyed by IV3 bucket code |
| `xmlAttachmentUri` | string | No | docudesk URI of the generated XML file |
| `state` | enum | Yes | `generated`, `validated`, `submitted`, `accepted`, `rejected`, `corrected` |
| `generatedAt`, `submittedAt`, `acceptedAt` | datetime | No | Lifecycle timestamps |
| `cbsMessageId` | string | No | CBS-side message identifier |
| `correctionOf` | string | No | FK to a prior `Iv3Export.id` superseded by this one |

#### Scenario: A minimal generated export validates

- **GIVEN** the schema
- **WHEN** an `Iv3Export` with `administrationId: "gem-a"`,
  `reportingYear: 2026`, `reportingQuarter: "Q1"`, `iv3Version:
  "2026.1"`, `buckets: {...}`, `state: "generated"` is created
- **THEN** validation MUST pass.

### Requirement: REQ-IV3-003: The `buckets` field SHALL be derived from aggregations over GL postings filtered by BBV `iv3Bucket`

The `buckets` object MUST be populated via `x-openregister-aggregations`
over `GLLine` (T1) joined with `BbvAccountMapping` (T3
bookkeeping-bbv-compliance) filtered by the quarter's period IDs
(T2 period-close determines closed-period inclusion). The
projection key is `BbvAccountMapping.iv3Bucket`; the aggregate is
SUM of `debit - credit`. Per ADR-031, no `Iv3AggregationService`.

#### Scenario: A Q1 export aggregates the closed-period postings

- **GIVEN** `gem-a` has Q1 2026 closed (per T2) with posted GL
  transactions across 4 IV3 buckets
- **WHEN** the aggregation runs
- **THEN** the resulting `buckets` MUST contain 4 keys, one per
  IV3 bucket present in Q1's postings.

#### Scenario: Open-period postings are excluded

- **GIVEN** `gem-a` has Q1 closed but Q2 still open
- **WHEN** the Q1 IV3 aggregation runs
- **THEN** Q2 postings MUST NOT appear in the result — the
  aggregation filter MUST use only closed-period IDs.

### Requirement: REQ-IV3-004: The IV3 XML SHALL be generated via OR's mapping/transformation engine, not a PHP renderer

The IV3 XML payload MUST be generated via OR's mapping/transformation
engine (per ADR-022 mappings abstraction) configured with the CBS
IV3-bestand schema. shillinq MUST NOT author an `Iv3XmlRenderer` or
ship a Twig template — the mapping is declared as a register
artifact (`Iv3Mapping`) consumed by the OR engine.

If OR's mapping engine cannot express the CBS schema's structure
(unusual repeating-section patterns), ADR-031 exception path
applies: a thin single-method PHP renderer is permitted, documented
in the implementing cycle's design with citation to the engine
shortfall.

#### Scenario: Generated XML validates against the CBS schema

- **GIVEN** an `Iv3Export` with computed buckets
- **WHEN** the XML is generated via the mapping engine
- **THEN** the resulting file MUST validate against the published
  CBS IV3 schema for the declared `iv3Version`.

#### Scenario: Reviewer scans for forbidden XML generation

- **GIVEN** the shillinq codebase post-implementation
- **WHEN** scanned for `XMLWriter`, `DOMDocument`, or `simplexml_load`
  in `lib/`
- **THEN** at most one short renderer SHALL exist, AND if present
  it MUST carry an ADR-031 exception annotation linking back to
  design.md.

### Requirement: REQ-IV3-005: The `Iv3Export` lifecycle SHALL be declarative per ADR-031

| From | To | Trigger | Guard |
|---|---|---|---|
| (new) | `generated` | scheduled workflow or operator trigger | period MUST be closed (per T2) |
| `generated` | `validated` | operator action | XML MUST validate against the CBS schema |
| `validated` | `submitted` | operator action | approval-workflow `requires` (controller approval per administration policy) |
| `submitted` | `accepted` | event from OpenConnector source `cbs-iv3` | none |
| `submitted` | `rejected` | event from OpenConnector source `cbs-iv3` | none |
| `rejected` | `validated` | operator action after fix | none |
| `accepted` | `corrected` | operator action creating a new export with `correctionOf` set | none |

Per ADR-031 anti-pattern list, shillinq MUST NOT author an
`Iv3LifecycleService`.

#### Scenario: A direct submission without validation fails

- **GIVEN** an `Iv3Export` in `state: "generated"`
- **WHEN** the operator tries to skip `validated` and trigger `submit`
- **THEN** the transition MUST fail.

### Requirement: REQ-IV3-006: Submission to CBS SHALL be an OR `ScheduledWorkflow` consuming the `cbs-iv3` OpenConnector source

The submission MUST be expressed as an OR `ScheduledWorkflow` (per
ADR-031 §"Background jobs that orchestrate external systems")
consuming an OpenConnector source named `cbs-iv3`. The cron MUST
default to quarterly (`0 0 1 */3 *` at 1st of January/April/July/
October), operator-configurable. shillinq MUST NOT author a
`CbsClient` — per ADR-019 + ADR-022.

#### Scenario: The quarterly cron triggers generation for all gemeente admins

- **GIVEN** the date is the 1st of a calendar quarter
- **WHEN** the scheduled workflow fires
- **THEN** an `Iv3Export` MUST be queued for each gemeente
  administration that has the prior quarter closed.

#### Scenario: Reviewer scans for forbidden HTTP

- **GIVEN** the shillinq codebase post-implementation
- **WHEN** scanned for direct `cbs.nl` / `informatievoorderden.cbs.nl`
  URLs in `lib/`
- **THEN** no matches SHALL exist.

### Requirement: REQ-IV3-007: IV3 exports SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Overheid >
IV3-rapportages` with a `type: index` page binding to `Iv3Export`
and a `type: detail` page showing the export's buckets, XML link,
state, and lifecycle history. Visibility MUST be predicated on
`administrationType ∈ {gemeente, provincie, waterschap}`.

#### Scenario: The IV3 detail page shows buckets and XML link

- **GIVEN** a `bbv-controller` opens an Iv3Export detail page
- **WHEN** the page renders
- **THEN** the page MUST surface `reportingYear`, `reportingQuarter`,
  `state`, the bucket table, AND a link to the docudesk XML
  attachment.

### Requirement: REQ-IV3-008: Validation against the CBS schema SHALL be a declarative precondition, not a runtime PHP call

The XML validation step (REQ-IV3-005 `generated → validated`
guard) MUST be declared as a precondition on the lifecycle,
backed by an OR schema-validation engine call (the same engine
that validates JSON schemas, extended to XML schemas if OR
supports it). If OR's engine does not support XML schema
validation, the implementing cycle MAY ship a thin XML validator
PHP class — documented as an ADR-031 exception.

#### Scenario: An invalid XML payload fails validation

- **GIVEN** a generated IV3 export whose XML omits a required CBS
  bucket
- **WHEN** the `generated → validated` transition is attempted
- **THEN** the transition MUST fail with a "schema validation
  failed" error naming the missing bucket.

### Requirement: REQ-IV3-009: Audit trail and retention SHALL be consumed from OR's abstractions

Every `Iv3Export` operation MUST be audited via OR's
audit-trail-immutable (ADR-022). Retention MUST be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.4" }`
referencing the Selectielijst rule for verantwoording-naar-derden
records (typically 10 years per Archiefwet 1995 + Selectielijst
Gemeenten 2020).

#### Scenario: A submitted IV3 export remains queryable for 10 years

- **GIVEN** an IV3 export submitted in 2017
- **WHEN** queried in 2026 (within the 10-year retention)
- **THEN** the record MUST be returned with audit trail intact.
