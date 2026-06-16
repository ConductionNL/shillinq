# Spec: bookkeeping-sbr-xbrl-reporting

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-financial-statements (T3), bookkeeping-vat-btw-filing (T3)

## ADDED Requirements

### Requirement: REQ-SBR-001 — SBR/XBRL instance documents SHALL be produced from the bookkeeping-financial-statements output as a declarative transformation

Annual filings for KvK (jaarrekening) and Belastingdienst (aangifte VPB/IB) MUST be generated as a declarative transformation
on top of the `FinancialStatement` output owned by the T3
`bookkeeping-financial-statements` capability. The XBRL instance document MUST
NOT re-aggregate the underlying ledger — it consumes the already-
balanced statement object and maps each line to the configured
NL-taxonomie concept via an OpenRegister `Mapping` record (per
ADR-022 mappings abstraction). No PHP report engine; no app-local
aggregation pass.

#### Scenario: Reviewer confirms no parallel aggregation path

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Service.php` classes whose
  method names match `generate*Report` / `compute*Total` /
  `sum*ForReport` against ledger objects
- **THEN** no such classes SHALL exist; the XBRL builder MUST read
  pre-aggregated `FinancialStatement` values exclusively.

#### Scenario: An XBRL instance is built from a posted financial statement

- **GIVEN** a `FinancialStatement` record in state `posted` for
  fiscal year `2026`, administration `adm-1`, variant `jaarrekening`
- **WHEN** the operator triggers XBRL generation for KvK
  deponering
- **THEN** the system MUST emit one `XbrlInstance` record whose
  `concepts[]` array maps every statement line to the matching
  NL-taxonomie concept per the configured `Mapping`; **AND** the
  instance MUST validate against the published NL-taxonomie schema
  for the entry point and reporting period.

### Requirement: REQ-SBR-002 — The `XbrlInstance` register SHALL declare a fixed minimum field set

The `XbrlInstance` register MUST declare the following fields with the listed required/optional flag:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `instanceNumber` | string | Yes | Sequential reference unique per administration + reporting period |
| `entryPoint` | enum | Yes | One of `kvk-jaarrekening`, `belastingdienst-vpb`, `belastingdienst-ib`, `sbr-banken-kredietrapportage`, `sbr-wonen` |
| `taxonomyVersion` | string | Yes | NL-taxonomie version pinned at generation (e.g. `nt17`, `nt18`) |
| `reportingPeriodStart` | date | Yes | Start of the period covered |
| `reportingPeriodEnd` | date | Yes | End of the period covered |
| `sourceStatementId` | string | Yes | FK to the `FinancialStatement` record this instance derives from |
| `mappingId` | string | Yes | FK to the `Mapping` record that defines line → concept mapping |
| `instanceXml` | string | Yes | The generated XBRL instance document, stored as a UTF-8 string |
| `instanceHash` | string | Yes | SHA-256 of the canonicalised XML, used for tamper-evidence |
| `state` | enum | Yes | One of `draft`, `validated`, `submitted`, `accepted`, `rejected` |
| `digipoortReceiptId` | string | No | Receipt ID returned by Digipoort on submission acknowledgement |
| `administrationId` | string | Yes | FK to the administration |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `createdAt`,
`updatedAt`, `owner`, `auditTrail`) are not redeclared.

#### Scenario: A draft instance can be created without a receipt

- **GIVEN** the schema is loaded
- **WHEN** an `XbrlInstance` is created in state `draft` with no
  `digipoortReceiptId`
- **THEN** the save MUST succeed.

#### Scenario: An `accepted` instance requires a receipt

- **GIVEN** the schema
- **WHEN** an `XbrlInstance` is transitioned to `accepted` without a
  `digipoortReceiptId`
- **THEN** the transition MUST fail with a "missing receipt" error.

### Requirement: REQ-SBR-003 — A state-machine lifecycle on `XbrlInstance` SHALL be declared per ADR-031

The `XbrlInstance` schema MUST declare an `x-openregister-lifecycle`
block with the following transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `validated` | operator action | XML validates against the NL-taxonomie schema for the entry point |
| `validated` | `submitted` | operator action | the openconnector Digipoort source is reachable and the submission call succeeds |
| `submitted` | `accepted` | inbound Digipoort acknowledgement | a Digipoort receipt with `status: accepted` arrives |
| `submitted` | `rejected` | inbound Digipoort error | a Digipoort receipt with `status: rejected` arrives |
| `rejected` | `draft` | operator action | none — operator amends and re-submits |

The transitions MUST be declared via `x-openregister-lifecycle`
(per ADR-031); no PHP service class authors the state machine.

#### Scenario: An unvalidated instance cannot be submitted

- **GIVEN** an `XbrlInstance` in state `draft`
- **WHEN** the operator attempts the `submitted` transition
- **THEN** the transition MUST fail with a "must validate first"
  error.

#### Scenario: A rejected submission can be amended and resubmitted

- **GIVEN** an `XbrlInstance` in state `rejected`
- **WHEN** the operator edits the source statement, regenerates
  the instance, validates, and submits
- **THEN** the new state MUST be `submitted` and the audit trail
  MUST record both submission attempts.

### Requirement: REQ-SBR-004 — Digipoort submission SHALL be consumed from openconnector per ADR-022

The submission HTTP path to Digipoort (the government gateway for SBR filings) MUST be consumed from openconnector as a `Source`
configured in the openconnector registry — shillinq MUST NOT embed
a Digipoort HTTP client, MUST NOT store WS-Security certificates,
and MUST NOT implement Digipoort's WUS / SMPP / SMP protocols
itself. Per ADR-022, when a sibling app provides the integration,
the app consumes it.

#### Scenario: Reviewer confirms no embedded HTTP client

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `Guzzle` / `Symfony\HttpClient` / `curl_init`
  usages in `lib/Service/Xbrl*` or `lib/Service/Sbr*`
- **THEN** no such usages SHALL exist; Digipoort calls MUST route
  through openconnector.

#### Scenario: Submission invokes the openconnector source by reference

- **GIVEN** an `XbrlInstance` in state `validated` and an
  openconnector `Source` with slug `digipoort-prod` configured
- **WHEN** the operator triggers `submit`
- **THEN** the lifecycle action MUST call the openconnector source
  by slug, attach the instance XML as the request body, and persist
  the response receipt; **AND** no Digipoort credentials SHALL be
  read from shillinq's AppConfig.

### Requirement: REQ-SBR-005 — The five canonical SBR entry points SHALL be supported

The supported entry points (`entryPoint` enum) MUST be:

| Entry point | Filing authority | Purpose |
|---|---|---|
| `kvk-jaarrekening` | KvK | Annual accounts deposit (deponering) for limited-liability entities |
| `belastingdienst-vpb` | Belastingdienst | Vennootschapsbelasting (corporate income tax) |
| `belastingdienst-ib` | Belastingdienst | Inkomstenbelasting (winst uit onderneming) |
| `sbr-banken-kredietrapportage` | SBR-banken consortium | Credit reporting to consortium banks |
| `sbr-wonen` | Aw / WSW | Reporting for housing corporations |

Each entry point pins a `taxonomyVersion` published by Logius;
the `Mapping` for an entry point MUST reference the NL-taxonomie
concepts of that version.

#### Scenario: Selecting an unsupported entry point fails validation

- **GIVEN** the `XbrlInstance` schema
- **WHEN** an instance is created with `entryPoint: "kvk-deelneming"`
  (not in the enum)
- **THEN** schema validation MUST fail with an enum-violation error.

### Requirement: REQ-SBR-006 — XBRL line → taxonomy mapping SHALL be declared via the OpenRegister Mappings abstraction

The mapping from each `FinancialStatement` line to its NL-taxonomie concept MUST live in an OpenRegister `Mapping` record
consumed via the OR Mappings abstraction (per ADR-022). One mapping
record MUST exist per entry point + taxonomy version pair; mappings
are referenced by FK from `XbrlInstance.mappingId`.

#### Scenario: A mapping resolves every statement line

- **GIVEN** a `FinancialStatement` with N lines and a `Mapping` for
  `kvk-jaarrekening` taxonomy `nt18`
- **WHEN** XBRL generation runs
- **THEN** every line MUST resolve to a NL-taxonomie concept;
  **AND** any unmapped line MUST cause generation to fail with a
  "missing concept mapping" error naming the line.

#### Scenario: Mapping evolution does not invalidate historical instances

- **GIVEN** an `XbrlInstance` in state `accepted` filed with
  `taxonomyVersion: "nt17"` and `mappingId: "map-nt17"`
- **WHEN** a new mapping `map-nt18` is created for taxonomy `nt18`
- **THEN** the historical instance MUST remain queryable with its
  original mapping reference and original `instanceHash` intact.

### Requirement: REQ-SBR-007 — XBRL instances SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
SBR/XBRL Filings`) with a `type: index` page binding to the
`XbrlInstance` register and a `type: detail` page for individual
filings. Both pages MUST be rendered by the generic
`@conduction/nextcloud-vue` `CnIndexPage` / `CnDetailPage`
components driven by manifest config — no bespoke Vue files (per
ADR-024 Tier-4).

#### Scenario: The index page lists filings filterable by state and entry point

- **GIVEN** the manifest declares the SBR/XBRL pages
- **WHEN** an operator opens
  `/index.php/apps/shillinq/sbr-xbrl-filings`
- **THEN** the page MUST render via `CnIndexPage` showing default
  columns (instanceNumber, entryPoint, reportingPeriodEnd, state,
  digipoortReceiptId) and filter chips for `state` and
  `entryPoint`.
