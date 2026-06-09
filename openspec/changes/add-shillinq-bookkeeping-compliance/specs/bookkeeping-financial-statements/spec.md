# Spec: bookkeeping-financial-statements

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 `bookkeeping-general-ledger`, T2 `bookkeeping-trial-balance`

This capability delivers the legally-required financial statement
outputs for Dutch SMB administrations under RJ 270 and the IFRS for
SMEs (NL/EU). BBV (government) financial statements are explicitly
deferred to T3 (`add-shillinq-bookkeeping-operations`).

Per ADR-024, the renderer is either a new `CnReportPage` component in
`@conduction/nextcloud-vue` (preferred path, preserves Tier-4) or a
short bespoke Vue file per statement type (fallback). The spec is
shape-neutral on which renderer lands first.

## ADDED Requirements

### Requirement: REQ-FS-001 — The system SHALL assemble financial statements as compositions of trial-balance aggregations

Financial statements MUST be assembled as compositions of trial-balance aggregations against presentation manifests; no PHP report-builder SHALL exist. Per ADR-031, the Balance Sheet, Profit & Loss Statement, and Cash
Flow Statement MUST NOT be implemented as PHP report builders. They
MUST be compositions of `x-openregister-aggregations` queries
(consuming the trial-balance aggregations from REQ-TB-001 and
REQ-TB-002) keyed against a presentation manifest stored in
`lib/Settings/statements/`. No `BalanceSheetService`, no
`ProfitAndLossService`, no `CashFlowService` SHALL be created (per
ADR-022 anti-pattern list, per ADR-031).

#### Scenario: Reviewer confirms no PHP financial statement service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes naming `BalanceSheet*`,
  `ProfitAndLoss*`, `CashFlow*`, `Statement*`, or `*ReportBuilder*`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-FS-002 — The system SHALL ship RJ 270 presentation manifests for Balance Sheet, P&L, and Cash Flow

Three presentation-manifest JSON files MUST be shipped under
`lib/Settings/statements/` and imported via
`ConfigurationService::importFromApp()` in the repair step:

| File | Statement | Approximate sections |
|---|---|---|
| `rj270-balance-sheet.json` | Balance Sheet (Balans) | ~40 line items: vaste activa / vlottende activa / eigen vermogen / voorzieningen / langlopende schulden / kortlopende schulden (per RJ 270 SMB) |
| `rj270-pl.json` | Profit & Loss (Winst- en verliesrekening) | ~30 line items: netto omzet / kostprijs omzet / bedrijfskosten / financieel resultaat / belastingen / nettoresultaat |
| `rj270-cash-flow.json` | Cash Flow Statement (Kasstroomoverzicht) | ~25 line items: kasstroom uit operationele activiteiten / investeringsactiviteiten / financieringsactiviteiten (indirect method default) |

Each manifest file MUST carry:
- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- A top-level `_meta` block: `{ "source": "RJ 270 (2026)",
  "variant": "smb", "imported": "" }`.
- Sections mapping RGS 3.5 SMB account ranges to RJ 270 line items.

Per-administration override is allowed: an operator may edit the
imported manifest through normal OR object operations. The repair
step MUST NOT overwrite operator edits on subsequent runs
(idempotent import per `ConfigurationService::importFromApp()` pattern).

#### Scenario: Balance sheet manifest parses as JSON and is importable

- **GIVEN** `lib/Settings/statements/rj270-balance-sheet.json`
- **WHEN** parsed as JSON
- **THEN** parsing MUST succeed; the `_meta` block MUST exist;
  the sections array MUST contain at least 30 entries covering
  vaste activa, vlottende activa, eigen vermogen, voorzieningen,
  and schulden.

#### Scenario: Dutch bookkeeper recognises the Balance Sheet structure

- **GIVEN** a competent Dutch SMB bookkeeper persona reads
  `rj270-balance-sheet.json`
- **THEN** the line-item hierarchy SHALL match the RJ 270 model
  balance sheet for SMB administrations, recognisable without
  additional explanation.

### Requirement: REQ-FS-003 — Financial statements SHALL support year-over-year comparatives via the manifest

The presentation manifest MUST declare support for N comparison
periods. When the manifest is rendered, the aggregation runs once
per declared period and the columns are presented side-by-side.
For the Balance Sheet: current-period closing balances vs prior-year
closing balances. For P&L and Cash Flow: current-period movements
vs prior-period movements.

The Cash Flow Statement MUST default to the indirect method
(starting from net result, adjusting for non-cash items). A
direct-method variant is on the roadmap but NOT in T2.

#### Scenario: Balance sheet renders with comparative column

- **GIVEN** the Balance Sheet manifest is configured for 2 periods
  (`2026-12` and `2025-12`)
- **WHEN** the statement is rendered
- **THEN** the output MUST contain two columns: `closingBalance 2026`
  and `closingBalance 2025`, each populated from the respective
  period's trial-balance aggregation.

### Requirement: REQ-FS-004 — XBRL and PDF export SHALL be declared as `x-openregister-calculations` actions

XBRL and PDF export MUST be declared as manifest actions backed by `x-openregister-calculations` — no PHP exporter service SHALL be authored. Per ADR-031, XBRL export (SBR-compatible XML for the Nederlandse
Taxonomie / NT15+) and PDF export MUST be declared as manifest
actions triggering `x-openregister-calculations` outputs — not as
PHP exporter service classes.

- **XBRL**: a calculation field that transforms the assembled
  statement data into SBR-compatible XBRL XML.
- **PDF**: rendered server-side via the `@conduction/nextcloud-vue`
  PDF utility (or `wkhtmltopdf` adapter) bound through a manifest
  action button. No shillinq-side PDF code is authored.

#### Scenario: XBRL export action is declared on the manifest page

- **GIVEN** the `Bookkeeping > Financial Statements > Balance Sheet`
  manifest page
- **WHEN** the page definition is inspected
- **THEN** an `actions` entry named `Export XBRL` MUST exist,
  referencing the XBRL calculation field.

#### Scenario: Reviewer confirms no PHP XBRL exporter service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes naming `Xbrl*`,
  `Sbr*`, or `*Exporter*`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-FS-005 — BBV financial statement manifests are explicitly deferred to T3

The T2 spec MUST NOT include any BBV-specific line items or account ranges; BBV financial statement manifests are explicitly deferred to T3. The T2 statement manifests (`rj270-*.json`) are explicitly for SMB
administrations only. BBV-conformant manifests (`rgs-bbv-*.json`)
and the related BBV programme / exploitation / balance formats are
T3 (`add-shillinq-bookkeeping-operations` / `bookkeeping-bbv-compliance`).
The T2 spec MUST NOT include any BBV-specific line items or account
ranges in the RJ 270 manifests.

#### Scenario: BBV keywords are absent from T2 manifests

- **GIVEN** any of the three T2 statement manifests
- **WHEN** searched for BBV-specific terms (`programmabegroting`,
  `exploitatie`, `activiteiten`, `reserves en voorzieningen` in BBV
  context)
- **THEN** no BBV-specific section codes SHALL appear.

### Requirement: REQ-FS-006 — Financial statements SHALL be reachable through the shillinq manifest navigation with drill-through

`src/manifest.json` MUST declare:
- `Bookkeeping > Financial Statements > Balance Sheet` — page with
  `type: report` (preferred, `CnReportPage`) or `type: index`
  fallback; XBRL and PDF export action buttons.
- `Bookkeeping > Financial Statements > Profit & Loss` — same shape.
- `Bookkeeping > Financial Statements > Cash Flow Statement` — same
  shape; notes indirect method default.

Each page MUST declare a drill-through affordance: clicking a line
item links to the trial-balance page filtered to the relevant account
range.

#### Scenario: Financial statements navigation entries exist and validate

- **GIVEN** `src/manifest.json` is loaded
- **WHEN** `node tests/validate-manifest.js` is run
- **THEN** the script MUST exit 0 and all three Financial Statements
  sub-entries MUST be present under a `Bookkeeping > Financial
  Statements` parent.

### Requirement: REQ-FS-007 — The `FiscalPeriod` register SHALL be declared before any financial statement is rendered

The `FiscalPeriod` register MUST be declared and a period MUST be selected before any financial statement is rendered. Financial statements draw their data from `FiscalPeriod`-scoped
aggregations. A financial statement MUST only be rendered for a
period in state `closed` or `audit-locked`; rendering for an `open`
period MAY be allowed as a preview but MUST be clearly labelled
"Voorlopige cijfers (niet definitief)" to prevent confusion with
final accounts.

#### Scenario: Closed period statement is rendered without warning

- **GIVEN** `FiscalPeriod` `2026-01` in state `closed`
- **WHEN** the Balance Sheet is rendered for `2026-01`
- **THEN** no "Voorlopige cijfers" warning banner SHALL appear.

#### Scenario: Open period statement is rendered with preview warning

- **GIVEN** `FiscalPeriod` `2026-02` in state `open`
- **WHEN** the Balance Sheet is rendered for `2026-02`
- **THEN** a "Voorlopige cijfers (niet definitief)" banner MUST
  appear prominently above the statement.
