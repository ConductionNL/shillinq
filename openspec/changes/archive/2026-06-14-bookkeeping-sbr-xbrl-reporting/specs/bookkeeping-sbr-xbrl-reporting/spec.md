# Spec: bookkeeping-sbr-xbrl-reporting

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (regulatory reporting + compliance)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md` (T2 chart mapping)

## ADDED Requirements

### Requirement: REQ-SBR-001 SBR / XBRL reporting capability SHALL be declared as XBRLTaxonomy + SBRDocumentType + XBRLMapping registers

Shillinq MUST declare the SBR / XBRL reporting capability as three new OpenRegister-managed registers (`XBRLTaxonomy`, `SBRDocumentType`, `XBRLMapping`) declared in `lib/Settings/shillinq_register.json` per ADR-024, with no app-local XBRL or SBR PHP service class.

Standard Business Reporting (SBR) and XBRL (eXtensible Business
Reporting Language) compliance for Dutch financial institutions is
REQUIRED per Belastingdienst regulations (annual financial statement
and tax filing). Shillinq MUST express the SBR/XBRL surface as three
new registers in `lib/Settings/shillinq_register.json` per ADR-024:

- `XBRLTaxonomy` — official XBRL GL (General Ledger) taxonomy versions
  published annually by Belastingdienst (e.g. XBRL GL 2026,
  2025); includes taxonomy identifier, name, publication date,
  taxonomy version code, and effective date.
- `SBRDocumentType` — defines SBR filing types (annual financial
  statement, tax filing); includes applicable entity types,
  submission deadline, filing rules, Belastingdienst endpoint, and
  auth method.
- `XBRLMapping` — transformation rules mapping Shillinq `Account`
  codes to XBRL GL taxonomy concepts (source account UUID → target
  XBRL concept URI).

No app-local XBRL generation service, no parallel `Reporting*` table.
Per ADR-031, all mapping and validation is declarative (schema
fields + aggregations) or config-driven.

#### Scenario: Reviewer confirms no custom XBRL service classes

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/XBRL*.php`,
  `lib/Service/SBR*.php`, `lib/Service/Reporting*.php`
- **THEN** no such classes SHALL exist.

#### Scenario: Three registers declared in register.json

- **GIVEN** `lib/Settings/shillinq_register.json`
- **WHEN** inspected for schema declarations
- **THEN** MUST contain `XBRLTaxonomy`, `SBRDocumentType`,
  `XBRLMapping` schema keys.

### Requirement: REQ-SBR-002 The XBRLTaxonomy schema SHALL declare official taxonomy versions

The `XBRLTaxonomy` schema MUST declare the field set below; the six fields marked `Yes` in the Required column MUST be enforced as schema-level required properties so a record cannot be saved without them.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `taxonomyId` | string | Yes | Unique identifier (e.g., `xbrl-gl-2026`) |
| `name` | string | Yes | Human-readable name (e.g., "XBRL GL 2026 — Belastingdienst") |
| `taxonomyVersion` | string | Yes | Official version code (e.g., `2026-01`) |
| `publicationDate` | date | Yes | Date published by Belastingdienst |
| `effectiveDate` | date | Yes | Date taxonomy becomes effective |
| `expiryDate` | date | No | Date taxonomy is superseded (null = active indefinitely) |
| `status` | enum | Yes | One of `active`, `archived`, `deprecated` |
| `description` | string | No | Regulatory reference (e.g., Handboek reference) |

Schema.org annotation: `schema:Thing`.

#### Scenario: Schema accepts a 2026 XBRL GL record

- **GIVEN** the schema
- **WHEN** `{taxonomyId: "xbrl-gl-2026", name: "XBRL GL 2026", taxonomyVersion: "2026-01", publicationDate: "2025-12-15", effectiveDate: "2026-01-01", status: "active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Multiple taxonomy versions coexist

- **GIVEN** both XBRL GL 2026 and XBRL GL 2025 are registered with
  `status: "active"`
- **WHEN** an operator selects the active taxonomy for an
  administration at fiscal year start
- **THEN** both versions MUST remain available for historical
  reference and prior-year filings.

### Requirement: REQ-SBR-003 The SBRDocumentType schema SHALL declare filing types and rules

The `SBRDocumentType` schema MUST declare the field set below; the ten fields marked `Yes` in the Required column MUST be enforced as schema-level required properties so a filing type without a deadline, endpoint, auth method, applicable entity types or administration cannot be saved.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `name` | string | Yes | Human-readable filing name (e.g., "Jaarverslag") |
| `code` | string | Yes | Unique code (e.g., `JAARVERSLAG`, `BELASTINGAANGIFTE`) |
| `description` | string | No | Filing description and regulatory reference |
| `applicableEntityTypes` | array of string | Yes | Entity types requiring this filing (e.g., `["BV", "NV", "Eenmanszaak"]`) |
| `filingDeadline` | date | Yes | Regulatory deadline (e.g., 2026-05-31 for annual report) |
| `requiredFields` | array of string | No | Mandatory GL accounts or data elements (e.g., `["assets", "liabilities", "revenue"]`) |
| `submissionEndpoint` | string | Yes | Belastingdienst / DNB endpoint URL |
| `authMethod` | string | Yes | Authentication scheme (e.g., `oauth2`, `mutual-tls`, `pki-cert`) |
| `status` | enum | Yes | One of `active`, `draft`, `archived` |
| `administrationId` | string | Yes | FK to administration (filing applies per administration) |

Schema.org annotation: `schema:Thing`.

#### Scenario: Annual report type is properly defined

- **GIVEN** the schema
- **WHEN** `{name: "Jaarverslag", code: "JAARVERSLAG", applicableEntityTypes: ["BV", "NV"], filingDeadline: "2026-05-31", submissionEndpoint: "https://belastingdienst.nl/xbrl-filing/submit", authMethod: "oauth2", status: "active", administrationId: "adm-1"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Filing deadline alerts trigger per administration

- **GIVEN** an `SBRDocumentType` with `filingDeadline: 2026-05-31`
- **WHEN** the administration's entity type matches
  `applicableEntityTypes`
- **THEN** a fiscal-year event MUST surface the deadline (triggered
  at `today = filingDeadline - 30 days`).

### Requirement: REQ-SBR-004 The XBRLMapping schema SHALL map accounts to XBRL GL concepts

The `XBRLMapping` schema MUST declare the field set below; the five fields marked `Yes` in the Required column MUST be enforced as schema-level required properties. Mappings MUST be unique per (sourceAccountId, taxonomyVersion) so a single account cannot carry two active mappings for the same taxonomy version.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `sourceAccountId` | string | Yes | FK to `Account` UUID |
| `targetXBRLConcept` | string | Yes | XBRL GL concept URI (e.g., `http://xbrl.gl/concept/CurrentAssets`) |
| `taxonomyVersion` | string | Yes | FK to `XBRLTaxonomy.taxonomyVersion` (mappings are version-specific) |
| `mappingDate` | date | Yes | Date mapping was established |
| `status` | enum | Yes | One of `active`, `archived`, `pending-review` |
| `notes` | string | No | Mapping rationale or special cases |

Schema.org annotation: `schema:Thing`.

#### Scenario: Account 1000 maps to CurrentAssets

- **GIVEN** `Account` with `accountNumber: "1000"` exists
- **WHEN** a mapping is created with `targetXBRLConcept: "http://xbrl.gl/concept/CurrentAssets"` and `taxonomyVersion: "2026-01"`
- **THEN** validation MUST pass; the mapping MUST persist for GL
  validation checks.

#### Scenario: Mapping version tracks taxonomy updates

- **GIVEN** XBRL GL 2025 and 2026 are both active
- **WHEN** an account is mapped to different XBRL concepts in 2025
  vs. 2026
- **THEN** both mapping versions MUST coexist with their respective
  `taxonomyVersion` field values.

### Requirement: REQ-SBR-005 SBR document lifecycle SHALL be declarative via x-openregister-lifecycle

`SBRDocumentType` MUST declare an `x-openregister-lifecycle` block
with the following state machine:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `validated` | operator validation request | GL completeness check (REQ-SBR-007) MUST pass |
| `validated` | `submitted` | operator submission | none — prepare XBR outbound contract (filing-ready) |
| `submitted` | `approved` | webhook event from Belastingdienst | none — submission accepted by authority |
| `submitted` | `rejected` | webhook event from Belastingdienst | none — authority rejection; reason recorded |
| `validated` | `draft` | operator revert | none — returns to editing |
| `rejected` | `validated` | operator remediation | none — revalidates per REQ-SBR-007 |

The lifecycle MUST NOT attempt to call Belastingdienst's submission
endpoint; the contract is declared (endpoint URL, auth scheme,
required fields) but outbound submission is T4 scope.

#### Scenario: Validation enforces GL completeness

- **GIVEN** a draft SBR document and an incomplete GL (unmapped
  accounts)
- **WHEN** the operator requests validation
- **THEN** the transition to `validated` MUST fail; an error MUST
  name the unmapped accounts.

#### Scenario: Submission prepares filing contract

- **GIVEN** a `validated` SBR document
- **WHEN** the operator clicks "Submit"
- **THEN** the document MUST transition to `submitted`; the filing
  contract (GL snapshot, mapping rules, XBRL metadata) MUST be
  prepared for T4 outbound processing; no actual submission occurs
  in T3.

#### Scenario: Webhook from Belastingdienst updates filing status

- **GIVEN** a `submitted` document
- **WHEN** Belastingdienst's webhook posts `{status: "approved", filingId: "..."}`
- **THEN** the document MUST transition to `approved`; the webhook
  payload MUST be logged for audit trail.

### Requirement: REQ-SBR-006 Pre-filing validation SHALL enforce GL completeness, mapping coverage, and mandatory fields

Pre-filing validation SHALL block the `draft -> validated` transition unless every check below passes; each check MUST be expressed as an `x-openregister-aggregations` predicate (no PHP validator class).

Before transitioning an SBR document to `validated`, the following
checks MUST run as `x-openregister-aggregations` predicates:

1. **GL Completeness**: All GL entries in the reporting period MUST be
   assigned to accounts with active `XBRLMapping` entries matching the
   selected `XBRLTaxonomy.taxonomyVersion`. Unmapped accounts MUST be
   flagged (error, blocks validation).

2. **Mapping Coverage**: All active `Account` records in the chart of
   accounts MUST have at least one `XBRLMapping` entry for the active
   taxonomy version (warning; operator can override with justification).

3. **Mandatory Fields**: `FiscalYear.startDate` and `.endDate` MUST be
   set, `Administration.businessYear` MUST match the fiscal year,
   `SBRDocumentType.applicableEntityTypes` MUST include the entity's
   business type.

4. **GL Balance Check**: Total GL debits MUST equal total GL credits
   per the standard GL invariant (REQ-GL-001).

No PHP `Validator` class; all checks are aggregations defined in
`lib/Settings/shillinq_register.json` on the `SBRDocumentType`
lifecycle.

#### Scenario: Unmapped account blocks validation

- **GIVEN** a GL entry posted to account `5500` (Other Expenses)
  which has no `XBRLMapping` entry
- **WHEN** an operator requests validation
- **THEN** the transition to `validated` MUST fail; the error MUST
  list account `5500` and the missing mapping.

#### Scenario: All accounts mapped permits validation

- **GIVEN** GL has 50 entries across 20 accounts, all 20 accounts
  have active `XBRLMapping` entries
- **WHEN** an operator requests validation
- **THEN** the validation MUST pass; the document MUST transition to
  `validated`.

#### Scenario: Mandatory fields enforce proper setup

- **GIVEN** a `FiscalYear` with no `startDate`
- **WHEN** an operator requests validation of an SBR document
  referencing that fiscal year
- **THEN** validation MUST fail with "Fiscal year start date
  required".

### Requirement: REQ-SBR-007 XBRL mapping validation SHALL be pre-filing aggregation, not imperative

XBRL mapping validation MUST be expressed as a declarative aggregation on `SBRDocumentType`; no PHP `XBRLValidator` / `SBRValidator` service SHALL be authored.

Mapping validation is performed via aggregations that answer: "For
this FiscalYear and XBRLTaxonomy version, is every GL account
covered?" The aggregation MUST:

- Query all active `Account` records in the chart.
- For each account, check for an `XBRLMapping` entry with
  `status: "active"` and matching `taxonomyVersion`.
- Return a list of uncovered accounts (if any) or pass (if all covered).

The check is idempotent and side-effect-free; no service maintains
state.

#### Scenario: Aggregation returns uncovered accounts

- **GIVEN** 25 accounts in the chart, 24 with active mappings
- **WHEN** the aggregation is invoked
- **THEN** it MUST return a list containing 1 account UUID
  (unmapped).

#### Scenario: Aggregation passes with full coverage

- **GIVEN** 25 accounts in the chart, all 25 with active mappings
- **WHEN** the aggregation is invoked
- **THEN** it MUST return an empty list (all accounts covered).

### Requirement: REQ-SBR-008 Filing deadline notifications SHALL alert operators before regulatory deadline

The system SHALL dispatch a filing-deadline notification 30 days before each applicable `SBRDocumentType.filingDeadline`; notifications MUST be declared as an `x-openregister-notifications` scheduled trigger on the schema, not as a PHP cron job.

For each `SBRDocumentType` where the entity's business type matches
`applicableEntityTypes`, a notification MUST fire 30 days before
`filingDeadline` (using OR's notification engine or the shared
`bookkeeping-notifications` capability if available).

The notification MUST include: document type name, deadline date,
filing URL, and a link to the SBR document draft page.

#### Scenario: Notification fires 30 days before deadline

- **GIVEN** an `SBRDocumentType` with `filingDeadline: 2026-05-31`
- **WHEN** the date ticks to `2026-05-01`
- **THEN** an operator notification MUST be dispatched naming the
  filing type, deadline, and link to draft the document.

#### Scenario: No notification if deadline passed

- **GIVEN** today is `2026-06-01` (past the deadline)
- **WHEN** checking notification state
- **THEN** no notification MUST fire.

### Requirement: REQ-SBR-009 Manifest navigation SHALL expose XBRL Taxonomy, SBR Documents, and Mapping Validation

`src/manifest.json` MUST declare three new top-level navigation
entries:

1. **XBRL Taxonomies** (`type: index` + `type: detail`)
   - List view: filterable by `status` (active/archived), sortable by
     `publicationDate`.
   - Detail view: shows `XBRLTaxonomy` fields and a linked list of
     accounts currently mapped to this version.

2. **SBR Documents** (`type: index` + `type: detail`)
   - List view: filterable by `status` (draft/validated/submitted/approved/rejected),
     sortable by `filingDeadline`.
   - Detail view: shows lifecycle state, validation status, mapping
     coverage %, GL completeness check, and a "Submit" button
     (disabled until validated).

3. **Mapping Validation** (`type: index` + `type: detail`)
   - Index: aggregation query showing account coverage % for the
     active taxonomy version; highlights uncovered accounts.
   - Detail: account → XBRL concept mapping visualization; "Review
     & Fix" workflow for uncovered accounts.

All three pages MUST use schema-driven CRUD (`CnIndexPage` +
`CnDetailPage` from `@conduction/nextcloud-vue`); no custom
components.

#### Scenario: XBRL Taxonomies list is sortable

- **GIVEN** the manifest navigation
- **WHEN** the operator clicks "XBRL Taxonomies"
- **THEN** an index page MUST load with all `XBRLTaxonomy` records;
  columns: name, version, effective date, status; sort by date ↑/↓.

#### Scenario: SBR Documents detail shows lifecycle state

- **GIVEN** a `SBRDocumentType` in `validated` state
- **WHEN** the operator opens the detail page
- **THEN** the state MUST display as a badge; GL completeness check
  MUST show "✓ Pass"; the "Submit" button MUST be enabled.

#### Scenario: Mapping Validation highlights coverage gaps

- **GIVEN** the chart has 25 accounts; 24 have mappings
- **WHEN** the operator opens "Mapping Validation"
- **THEN** the index MUST show "Coverage: 96%"; account view MUST
  highlight the 1 unmapped account in red.

## Verification

`openspec validate` must exit clean on the change folder. Dutch
regulatory bookkeeper peer review (e.g. via Belastingdienst
compliance checklist) confirms the filing flow matches Dutch SBR
requirements. Architecture reviewer confirms ADR-031 compliance (no
app-local XBRL service; lifecycle declarative; manifest carries
navigation). No source code changes outside `openspec/changes/bookkeeping-sbr-xbrl-reporting/`.
