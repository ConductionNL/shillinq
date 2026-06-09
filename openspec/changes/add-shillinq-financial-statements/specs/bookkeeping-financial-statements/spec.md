# Spec: bookkeeping-financial-statements

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`./bookkeeping-trial-balance/spec.md` (T2 trial-balance)

## ADDED Requirements

This capability is bound by **ADR-022** (consume OpenRegister abstractions; no
parallel app-local report storage) and **ADR-031** (declarative composition
over imperative report builders). Each requirement below is a restatement of
those ADRs applied to the financial-statements slice, plus **ADR-024 Tier-4**
for the renderer path (`CnReportPage` library component preferred, per-statement
bespoke Vue fallback). BBV (Dutch government) statement manifests are
explicitly deferred to T3 (`add-shillinq-bbv-compliance`).

### Requirement: REQ-FS-001 — Financial statements SHALL be assembled as compositions of trial-balance aggregations + a presentation manifest; no PHP report engine

Balance Sheet, Profit & Loss, and Cash Flow Statement MUST be
expressed as compositions of `bookkeeping-trial-balance`
aggregations (per REQ-TB-001) grouped against a presentation
manifest (a JSON file mapping statement line items to account-number
ranges). The implementation MUST NOT introduce a
`FinancialStatementService.php`, `BalanceSheetBuilder.php`,
`ProfitAndLossService.php`, or any PHP class whose responsibility
is "assemble a financial statement". This is the ADR-031
anti-pattern explicitly enumerated under "Aggregation service".

Per ADR-031, the presentation-manifest is schema metadata; the
renderer consumes it through `@conduction/nextcloud-vue` per
ADR-024 Tier-4.

#### Scenario: Reviewer confirms no statement-builder service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*Statement*.php`,
  `lib/Service/*BalanceSheet*.php`,
  `lib/Service/*ProfitAndLoss*.php`,
  `lib/Service/*CashFlow*.php`
- **THEN** no such files SHALL exist.

#### Scenario: Statement composition lives in the presentation manifest

- **GIVEN** `lib/Settings/statements/`
- **WHEN** scanned
- **THEN** three JSON manifests MUST exist:
  `rj270-balance-sheet.json`, `rj270-pl.json`, `rj270-cash-flow.json`;
  each maps statement line items to account-number ranges per
  RJ 270 / IFRS for SMEs (per design.md Seed Data).

### Requirement: REQ-FS-002 — Each presentation manifest SHALL declare a tree of statement line items mapped to account-number ranges

A presentation manifest MUST be a JSON document with the shape:

```jsonc
{
  "_meta": { "source": "RJ 270 (2026)", "variant": "smb", "imported": "<iso-timestamp>" },
  "statement": "balance-sheet",      // or "profit-and-loss", or "cash-flow"
  "sections": [
    {
      "id": "fixed-assets",
      "label": "Vaste activa",        // i18n key per ADR-005
      "subSections": [
        {
          "id": "tangible-fixed-assets",
          "label": "Materiële vaste activa",
          "accountRanges": [{"from": "0100", "to": "0399"}],
          "naturalSide": "debit"
        }
      ]
    }
  ]
}
```

The renderer (per REQ-FS-005) MUST traverse the tree, issue one
trial-balance aggregation per leaf section, sum the resulting
account balances by `naturalSide`, and render the result.

T2 ships three manifests per design.md Seed Data:
`rj270-balance-sheet.json` (~40 line items),
`rj270-pl.json` (~30 line items), `rj270-cash-flow.json`
(~25 line items, indirect method by default).

#### Scenario: Each ships file parses as JSON and validates

- **GIVEN** any of the three presentation manifests
- **WHEN** parsed as JSON
- **THEN** parsing MUST succeed; **AND** every section MUST
  carry an `id`, `label`, and at least one `accountRanges` entry
  or `subSections` array.

#### Scenario: A bookkeeper persona recognises RJ 270 layout

- **GIVEN** a competent Dutch SMB bookkeeper persona reads
  `rj270-balance-sheet.json`
- **THEN** the structure SHALL match the RJ 270 / IFRS-for-SMEs
  Balance Sheet layout (assets → equity + liabilities, with
  fixed assets / current assets / equity / provisions /
  long-term debt / short-term debt sections).

### Requirement: REQ-FS-003 — Balance Sheet, P&L, and Cash Flow SHALL each be a distinct statement-type page bound through the manifest

`src/manifest.json` MUST declare three navigation entries:

- `Bookkeeping > Financial Statements > Balance Sheet`
- `Bookkeeping > Financial Statements > Profit & Loss`
- `Bookkeeping > Financial Statements > Cash Flow Statement`

Each page MUST bind to the corresponding presentation manifest in
`lib/Settings/statements/` and the renderer MUST issue the
underlying trial-balance aggregations against the requested
period(s).

The cash-flow statement defaults to **indirect method** (starting
from net result and adjusting for non-cash items). A direct-method
variant is on the roadmap for a future change; T2 ships indirect
only.

#### Scenario: Balance sheet page renders for the open period

- **GIVEN** the manifest declares the Balance Sheet page and the
  active `FiscalPeriod` is `2026-Q2`
- **WHEN** an operator opens
  `/index.php/apps/shillinq/bookkeeping-financial-statements/balance-sheet`
- **THEN** the renderer MUST issue trial-balance aggregations for
  period `2026-Q2` filtered to the account ranges in
  `rj270-balance-sheet.json`; **AND** display the assembled
  statement.

#### Scenario: P&L page renders for the open period

- **GIVEN** the manifest declares the P&L page and the active
  period is `2026-Q2`
- **WHEN** an operator opens
  `/index.php/apps/shillinq/bookkeeping-financial-statements/profit-and-loss`
- **THEN** the renderer MUST issue trial-balance aggregations
  for `2026-Q2` filtered to the account ranges in `rj270-pl.json`;
  **AND** display the assembled P&L statement.

### Requirement: REQ-FS-004 — Financial statements SHALL support year-over-year comparatives via repeated aggregation calls; no bespoke comparison code

Financial statements MUST support year-over-year comparatives. When an
operator requests N comparative periods (typically current-year +
prior-year for SMB), the renderer MUST issue N independent trial-balance
aggregation calls (one per period per statement section) and compose
the columnar result in the manifest, per REQ-TB-006's multi-period
pattern.

No bespoke "comparative report" assembly code in shillinq.

#### Scenario: Two-year comparative balance sheet

- **GIVEN** an operator requests the Balance Sheet with
  comparative period `2025-Q4` alongside current `2026-Q4`
- **WHEN** the page loads
- **THEN** the renderer MUST issue two sets of aggregation calls
  (one per period × per section); **AND** display two columns side
  by side (prior year / current year) per RJ 270's standard
  comparative layout.

### Requirement: REQ-FS-005 — Each statement row SHALL be drill-through-able to the underlying GL transactions

Every statement line item MUST carry sufficient identifiers
(statement `period_id` + section `accountRanges`) to construct a
filtered URL into the General Ledger index page (per T1 REQ-GL-007)
showing every transaction contributing to that line item's balance.

Drill-through is a manifest-side affordance; per REQ-TB-004's
pattern, no bespoke drill-through code.

#### Scenario: Drill-through from "Materiële vaste activa" to GL

- **GIVEN** a Balance Sheet row for "Materiële vaste activa"
  (account range `0100`–`0399`) and period `2026-Q4`
- **WHEN** the operator clicks the row
- **THEN** the page MUST navigate to
  `/index.php/apps/shillinq/general-ledger?period=2026-Q4&account_range=0100-0399`,
  where the GL index page (per T1 REQ-GL-007) shows every line
  in the range for that period.

### Requirement: REQ-FS-006 — XBRL/SBR export SHALL be a declarative `x-openregister-calculations` output, not a PHP exporter

XBRL (SBR-compatible) export MUST be an
`x-openregister-calculations` field on the statement output
producing the XBRL XML directly from the assembled statement.
The implementation MUST NOT introduce an `XbrlExporter.php`,
`SbrService.php`, or similar PHP exporter — per ADR-031, XML
composition from object data is the calculation extension's
domain.

The XBRL output MUST conform to the SBR taxonomy version the
administration is configured for (default: NT2026 for FY2026,
per Belastingdienst's published rolling schedule). Taxonomy
version selection is an administration setting carried via the
presentation manifest's `_meta` block.

PDF export is similarly declarative — the existing
`@conduction/nextcloud-vue` PDF utility (or a `wkhtmltopdf`
adapter) is bound through a manifest action button; no shillinq-
side PDF code.

#### Scenario: Reviewer confirms no XBRL exporter

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Xbrl*.php`,
  `lib/Service/Sbr*.php`, `lib/Export/*.php`
- **THEN** no such files SHALL exist.

#### Scenario: XBRL output validates against NT2026 taxonomy

- **GIVEN** a posted P&L statement for FY2026 with the manifest
  configured for `NT2026`
- **WHEN** the operator triggers the XBRL export
- **THEN** the calculation MUST emit XBRL XML; **AND**
  validation against the NT2026 SBR taxonomy MUST pass; **AND**
  the file MUST be downloadable via the manifest's "Export XBRL"
  action button.

#### Scenario: PDF export uses the shared utility

- **GIVEN** a rendered Balance Sheet page
- **WHEN** the operator triggers PDF export
- **THEN** the manifest's "Export PDF" action MUST invoke the
  `@conduction/nextcloud-vue` PDF utility (or the configured
  `wkhtmltopdf` adapter) — no shillinq PHP code MUST be involved
  in the rendering pipeline.

### Requirement: REQ-FS-007 — Financial statements SHALL render via a `CnReportPage` library component or a thin per-statement Vue fallback

Financial statements MUST render through a manifest-bound renderer.
The financial-statement renderer SHOULD be a new `CnReportPage`
component in `@conduction/nextcloud-vue` (the preferred path —
preserves ADR-024 Tier-4 across the fleet). The component takes
a presentation manifest + a period (or periods) and renders any
statement type.

If `CnReportPage` does not yet exist in the library at T2
implementation time, the fallback is a short bespoke Vue file
per statement type (`BalanceSheetView.vue`, `ProfitAndLossView.vue`,
`CashFlowView.vue`) consuming the manifest. The fallback MUST be
documented in `bookkeeping-financial-statements/design.md`
discovery and the bespoke files MUST be removed when
`CnReportPage` lands.

The library-path is preferred and tracked in the nextcloud-vue
roadmap; the spec is shape-neutral on which renderer.

#### Scenario: Library-path renderer is used when CnReportPage is available

- **GIVEN** `@conduction/nextcloud-vue` ships `CnReportPage`
- **WHEN** the bookkeeping-financial-statements manifest entries are inspected
- **THEN** all three (Balance Sheet, P&L, Cash Flow) MUST bind
  `type: report` with `component: CnReportPage`; no per-statement
  bespoke Vue MUST exist.

#### Scenario: Fallback bespoke Vue ships only if CnReportPage is unavailable

- **GIVEN** `CnReportPage` is not yet in the library at T2
  implementation time
- **WHEN** the bespoke Vue files are authored
- **THEN** each MUST carry a SPDX header + an
  `// TODO(ADR-024 Tier-4): replace with CnReportPage when library
  ships` comment naming the tracking issue.

### Requirement: REQ-FS-008 — Financial statements SHALL be limited to SMB (RJ 270 / IFRS for SMEs) in T2; BBV is T3

Financial statements MUST be limited to SMB presentation in T2.
T2 ships presentation manifests conformant with RJ 270 and IFRS
for SMEs only. The BBV (Besluit Begroting en Verantwoording)
financial-statement formats for Dutch municipal / government
administrations are explicitly deferred to a future
`add-shillinq-bookkeeping-bbv-compliance` change (T3). T1 already
ships a `rgs-bbv.json` chart-of-accounts seed; the BBV-specific
balance sheet / programme / exploitation statement manifests
ship with T3.

T2's renderer is format-agnostic — once T3 ships BBV manifests,
they consume the same renderer per REQ-FS-007 without rework.

#### Scenario: T2 ships no BBV statement manifests

- **GIVEN** `lib/Settings/statements/`
- **WHEN** scanned for `bbv-*.json`
- **THEN** no BBV files SHALL exist in T2 (T3 ships them).

#### Scenario: Renderer is format-agnostic

- **GIVEN** the renderer per REQ-FS-007
- **WHEN** a hypothetical T3 BBV balance sheet manifest is fed
- **THEN** the renderer MUST consume it identically — no
  renderer changes MUST be required to support BBV layouts.
