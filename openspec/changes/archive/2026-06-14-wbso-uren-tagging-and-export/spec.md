# Spec: bookkeeping-wbso-hours-tagging-and-export

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../billable-categories-and-tags/spec.md` (project metadata tagging),
`../add-shillinq-general-ledger/spec.md` (T1 GL foundation)

## ADDED Requirements

### REQ-WBSO-001: WBSO hours tagging SHALL be declared as `WBSOTag` + `WBSOActivityCode` + `TimeEntry` FK, not duplicates of project codes

WBSO hours tagging MUST be expressed as two dedicated registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `WBSOTag` — WBSO project code (SO, TWO, SMART) with display name,
  description, RVO authority alignment notes.
- `WBSOActivityCode` — Activity classification (A001 = R&D
  programming, A002 = testing, B001 = non-eligible) with
  allow/restrict status per subsidy rules.
- `TimeEntry` extends with optional FK to `WBSOTag` +
  `WBSOActivityCode` (both null-safe for non-subsidized entries).

This capability **enables automatic WBSO compliance tracking for
Dutch R&D subsidies**, bridging the gap between Shillinq's billable
hours engine and RVO (Rijksdienst voor Ondernemend Nederland)
administrative requirements. No duplicate tag tables in legacy
schema.

#### Scenario: Reviewer confirms no parallel WBSO table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `wbso_*`, `subsidy_*`, or `rvo_*`
- **THEN** no such classes SHALL exist.

#### Scenario: TimeEntry WBSO FK resolves to real codes

- **GIVEN** T2 is live and `TimeEntry` is created with parent
  `Project` carrying a `WBSOTag` reference
- **WHEN** the `TimeEntry` is inspected
- **THEN** the entry MUST carry `wbsoTagId: <UUID of WBSOTag>` +
  `activityCodeId: <UUID of WBSOActivityCode>` (auto-assigned if
  both project fields present), **AND** the FKs MUST resolve via
  OR's relation engine.

### REQ-WBSO-002: The `WBSOTag` schema SHALL declare the official WBSO project codes

| Field | Type | Required | Purpose |
|---|---|---|---|
| `wbsoCode` | string (enum) | Yes | Official WBSO code: SO, TWO, SMART, or custom per WBSO legislation |
| `displayName` | string | Yes | Dutch display name (e.g., "Stand-alone Project", "TechnoWise Open") |
| `description` | string | No | RVO alignment notes and subsidy conditions |
| `rvoCertificationUrl` | string | No | Link to official RVO directive or legislation reference |
| `administrationId` | string | Yes | FK to administration |
| `lifecycleState` | enum | Yes | One of `active`, `archived`, `deprecated` |

Schema.org annotation: `schema:DefinedTerm`.

#### Scenario: Schema validator accepts a standard WBSO code

- **GIVEN** the schema
- **WHEN** `{wbsoCode:"SO", displayName:"Stand-alone Project", administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Custom codes supported per WBSO update

- **GIVEN** the schema
- **WHEN** `{wbsoCode:"CUSTOM-XYZ", displayName:"Custom RVO 2026 Code", administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass (not hardcoded enumeration).

### REQ-WBSO-003: The `WBSOActivityCode` schema SHALL declare allowed and restricted activity categories per RVO rules

| Field | Type | Required | Purpose |
|---|---|---|---|
| `activityCode` | string | Yes | A001–A999 (allowed R&D), B001+ (restricted/overhead), or custom per subsidy agreement |
| `description` | string | Yes | Activity description (e.g., "Software development for R&D", "Project overhead") |
| `category` | enum | Yes | One of `research-development`, `support`, `non-eligible`, `infrastructure` |
| `isAllowed` | boolean | Yes | true = eligible for WBSO subsidy, false = must be tracked but excluded from submission |
| `parentActivityCode` | string | No | FK to baseline code if this is an administration-custom variant |
| `administrationId` | string | Yes | FK to administration |
| `lifecycleState` | enum | Yes | One of `active`, `archived`, `deprecated` |

Schema.org annotation: `schema:DefinedTerm`.

#### Scenario: Baseline activity code accepted

- **GIVEN** the schema
- **WHEN** `{activityCode:"A001", description:"Software development for R&D", category:"research-development", isAllowed:true, administrationId:"adm-1", lifecycleState:"active"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Non-eligible code tracked but flagged

- **GIVEN** a `TimeEntry` with `activityCodeId` pointing to B001
  (non-eligible)
- **WHEN** an export is generated
- **THEN** the entry MUST appear in the export detail with a
  `eligible: false` flag, and MUST be excluded from subsidy-hours
  totals.

### REQ-WBSO-004: `TimeEntry` SHALL extend with WBSO tag lifecycle and auto-tagging precondition

`TimeEntry` MUST add the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `wbsoTagId` | string | No | FK to `WBSOTag` (null-safe for non-subsidized work) |
| `activityCodeId` | string | No | FK to `WBSOActivityCode` (null-safe for non-subsidized work) |
| `wbsoTaggedAt` | datetime | No | Timestamp when WBSO tags were assigned (auto or manual) |
| `tagSource` | enum | No | `auto` (inherited from project), `manual` (operator), or `untagged` (pending) |

`TimeEntry` MUST declare an `x-openregister-aggregations` precondition on creation:

```
IF parent Project.wbsoTagId is not null
  AND parent Project.activityCodeId is not null
THEN auto-assign both to TimeEntry.wbsoTagId + activityCodeId
  AND set tagSource: "auto"
  AND transition to "tagged" state
ELSE
  entry enters "untagged" state
  AND operator receives warning UI
  AND manual assignment required to proceed
```

#### Scenario: Auto-tagging on TimeEntry creation succeeds

- **GIVEN** a `Project` with `wbsoTagId: <SO>` + `activityCodeId: <A001>`
- **WHEN** an operator creates a `TimeEntry` under that project
- **THEN** the entry MUST auto-inherit both tags; `tagSource` MUST be
  `auto`; entry state MUST become `tagged`.

#### Scenario: Incomplete project metadata blocks auto-tagging

- **GIVEN** a `Project` with `wbsoTagId: <SO>` but `activityCodeId: null`
- **WHEN** an operator creates a `TimeEntry` under that project
- **THEN** the entry MUST enter `untagged` state; a warning MUST be
  surfaced in the UI ("Missing activity code; manual assignment
  required"); the operator MUST select an activity code to proceed.

### REQ-WBSO-005: The `WBSOExportLog` schema SHALL track all export operations and their RVO validation status

| Field | Type | Required | Purpose |
|---|---|---|---|
| `exportId` | string | Yes | Unique export identifier (e.g., EXP-2026-Q1-001) |
| `periodStart` | date | Yes | Export date range start |
| `periodEnd` | date | Yes | Export date range end (typically fiscal quarter or year) |
| `exportFormat` | enum | Yes | One of `csv`, `pdf`, `xml` |
| `status` | enum | Yes | One of `draft`, `generated`, `validated`, `submitted`, `rejected` |
| `recordCount` | integer | Yes | Number of time entries included in export |
| `totalHours` | number | Yes | Total billable hours (eligible entries only) |
| `totalHoursIneligible` | number | No | Total non-eligible hours tracked but excluded from submission |
| `generatedAt` | datetime | No | When the export file was generated |
| `validatedAt` | datetime | No | When RVO validation passed |
| `validationErrors` | array of string | No | List of validation errors (empty if validated) |
| `submittedAt` | datetime | No | When submitted to RVO portal (or manual upload recorded) |
| `fileUri` | string | No | Storage path / cloud URL of the export file |
| `administrationId` | string | Yes | FK to administration |

Schema.org annotation: `schema:DigitalDocument`.

#### Scenario: Export record created in draft state

- **GIVEN** the schema
- **WHEN** `{exportId:"EXP-2026-Q1-001", periodStart:"2026-01-01", periodEnd:"2026-03-31", exportFormat:"csv", status:"draft", recordCount:0, totalHours:0, administrationId:"adm-1"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Generated export carries hour totals

- **GIVEN** an export with status `generated` covering 100 time
  entries (90 A-code, 10 B-code)
- **WHEN** the export record is inspected
- **THEN** `totalHours` MUST equal the sum of eligible entries'
  durations; `totalHoursIneligible` MUST equal the sum of B-code
  entries' durations.

### REQ-WBSO-006: Export workflow SHALL enforce RVO validation on `generated → validated` transition

`WBSOExportLog` MUST declare an `x-openregister-lifecycle` block
with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `generated` | operator selects date range + format + filters | none; file materialisation deferred to T4 |
| `generated` | `validated` | operator clicks "Validate for RVO" | all included `TimeEntry` records MUST have non-null `wbsoTagId` + `activityCodeId` (aggregation predicate); `isAllowed` MUST match entry eligibility; checksum verification (if XML format) |
| `validated` | `submitted` | operator uploads to RVO portal or clicks "Mark submitted" | none |
| `submitted` | `archived` | operator marks submission closed | none |
| `generated` | `rejected` | validation fails or operator cancels | list validation errors in `validationErrors` array |

#### Scenario: Validation fails if entries lack WBSO tags

- **GIVEN** an export with 50 entries, 5 of which have `wbsoTagId: null`
- **WHEN** the operator transitions from `generated` → `validated`
- **THEN** the transition MUST fail; `validationErrors` MUST list the
  5 untagged entry IDs; status MUST remain `generated`.

#### Scenario: Validation passes with complete tags and eligible totals

- **GIVEN** an export with 100 entries, all with non-null `wbsoTagId` +
  `activityCodeId`, 90 eligible, 10 non-eligible
- **WHEN** the operator transitions from `generated` → `validated`
- **THEN** the transition MUST succeed; status MUST become `validated`;
  `validatedAt` MUST be set to current timestamp;
  `validationErrors` MUST be empty.

### REQ-WBSO-007: RVO export format SHALL be declared as schema (CSV, PDF, XML shapes); T4 computes outbound

Three export format specifications MUST be declared in the spec
schema:

**CSV Format:**
```
Columns: Date (ISO 8601), Project Name, Project Code (WBSO), Activity Code, 
Activity Description, Hours (decimal), Employee Name, Notes

Example:
2026-01-15,Project Alpha,SO,A001,Software development,8.0,Jan Jansen,Sprint planning
2026-01-15,Project Alpha,SO,A002,Testing,2.0,Jan Jansen,QA regression
```

**PDF Format:**
```
Header: WBSO Hours Report for Administration [Name] — Period [Start]–[End]
Section 1: Summary by WBSO Tag
  SO: 240 hours (eligible), 10 hours (non-eligible)
  TWO: 80 hours (eligible), 0 hours (non-eligible)
Section 2: Summary by Activity Code
  A001: 150 hours
  A002: 100 hours
  B001: 10 hours (footnote: excluded from subsidy)
Section 3: Detail rows (date, project, code, hours, employee)
Footer: Generated [timestamp], Validated [timestamp], Signed [operator]
```

**XML Format (RVO Portal Integration):**
```xml
<WBSOExport>
  <Metadata>
    <Period start="2026-01-01" end="2026-03-31"/>
    <Administration id="[...]"/>
    <GeneratedAt timestamp="[...]"/>
  </Metadata>
  <TimeEntries>
    <Entry id="[UUID]" date="2026-01-15" project="Project Alpha" 
           wbsoCode="SO" activityCode="A001" hours="8.0" 
           employee="Jan Jansen" eligible="true"/>
    ...
  </TimeEntries>
  <Totals>
    <EligibleHours>320</EligibleHours>
    <IneligibleHours>10</IneligibleHours>
  </Totals>
</WBSOExport>
```

The schema declares these field shapes (CSV columns, PDF sections,
XML element names) so T4 can attach the outbound computation
additively. T2 does NOT compute or emit the files.

#### Scenario: Schema documents all three export formats

- **GIVEN** the spec
- **WHEN** reviewed by RVO compliance officer
- **THEN** the CSV/PDF/XML field layouts MUST match RVO submission
  requirements dated 2026-05-21; documentation MUST cite the
  official RVO directive reference.

### REQ-WBSO-008: WBSO-tagged `TimeEntry` records SHALL be queryable by export filter

`WBSOExportLog` export workflow MUST support the following filter
options on generation:

- Filter by `wbsoTagId` (single or multiple)
- Filter by `activityCodeId` (single or multiple)
- Filter by `isAllowed` (eligible only, or all)
- Filter by employee (single or multiple)
- Filter by date range (start–end)

#### Scenario: Export query filters by tag and eligibility

- **GIVEN** a period with 100 time entries: 60 tagged SO/A001
  (eligible), 30 tagged TWO/A001 (eligible), 10 tagged B001
  (non-eligible)
- **WHEN** export filters: `wbsoTagId=SO AND isAllowed=true`
- **THEN** the export MUST contain exactly 60 entries; totals MUST
  reflect only those 60.

### REQ-WBSO-009: Administration-configurable activity codes SHALL support variants per subsidy agreement

`WBSOActivityCode` MUST allow custom variants per administration.
The `parentActivityCode` field enables:

- Baseline codes (A001–A999, B001+) shared across all
  administrations.
- Custom variants (A001-CUSTOM-2026, etc.) created per
  administration with `administrationId` + `parentActivityCode` FK.

Custom variants inherit the `isAllowed` status from the parent but
can override the description per local subsidy rules.

#### Scenario: Custom activity code with baseline parent

- **GIVEN** baseline A001 "Software development for R&D"
- **WHEN** admin creates A001-CUSTOM-2026 with
  `parentActivityCode: A001`, `description: "Custom R&D coding per 2026 subsidy", administrationId: "adm-1"`
- **THEN** the new code MUST save; it MUST reference the parent; both
  MUST appear in WBSO tag dropdowns for the administration.

### REQ-WBSO-010: Manifest SHALL include WBSO Tags & Export Dashboard pages

`src/manifest.json` MUST declare:

1. **WBSO Tags Dashboard** — list of active `WBSOTag` + `WBSOActivityCode` records; admin panel for adding/archiving codes; bulk re-tagging UI for existing `TimeEntry` records by project.
2. **WBSO Export Dashboard** — export generator (date range selector, format picker, filter panel); list of past exports with status + validation errors; download + submission UI.

#### Scenario: Manifest validates without errors

- **GIVEN** the manifest
- **WHEN** `node tests/validate-manifest.js` runs
- **THEN** exit code MUST be 0; no WBSO entries missing.

#### Scenario: WBSO Tags dashboard accessible to admin role

- **GIVEN** the manifest entry
- **WHEN** an admin user navigates to `/shillinq/wbso-tags`
- **THEN** the page MUST load; WBSO tag list MUST be visible.

## References

- RVO Administratie Directives (2026-05-21)
- WBSO Wet Bevordering Speur- en Ontwikkelings-activiteiten legislation
- Shillinq spec: `bookkeeping-billable-categories-and-tags`
- Architecture: ADR-022 (consume OR), ADR-024 (register declaration),
  ADR-031 (declarative business logic)
