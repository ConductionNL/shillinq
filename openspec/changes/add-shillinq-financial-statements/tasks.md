# Tasks — Financial Statements

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-financial-statements` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Dedup scan complete. `openspec/specs/bookkeeping-financial-statements/spec.md` IS already shipped on `development` (precursor delivery — primary spec for the canonical `BalanceSheet` / `TrialBalance` / `ConsolidatedReport` / `ConsolidationGroup` registers landed via `add-shillinq-bookkeeping-compliance`); the present T2 umbrella refines the renderer-side composition contract (presentation manifests + `CnReportPage` Tier-4 path) without colliding. Scan for the ADR-031 anti-pattern enumeration (`lib/Service/BalanceSheet*` / `lib/Service/ProfitAndLoss*` / `lib/Service/CashFlow*` / `lib/Service/Statement*` / `lib/Service/Xbrl*` / `lib/Service/Sbr*`) finds four files: `lib/Service/CashflowPdfRenderer.php` (a thin format adapter for the `CashflowForecastHorizon` register from `add-shillinq-treasury-forecast` — NOT a financial-statement Cash Flow Statement builder; explicit ADR-031 note "this service contains no business logic; it is a thin format adapter"), `lib/Service/StatementManifestService.php` (importer of the RJ 270 presentation manifests from `lib/Settings/statements/` into the NC app-config store — explicit docblock "shillinq ships no PHP report builder or statement-storage table — the manifests are declarative presentation metadata"; this satisfies REQ-FS-002's manifest-import contract via the repair-step pattern), `lib/Service/InnovatieboxSbrExportService.php` (the `add-shillinq-innovatiebox-administratie` SBR exporter — distinct domain, not the financial-statement XBRL/SBR export REQ-FS-006 is about), and `lib/Service/PayrollSbrConversionService.php` (payroll Loonaangifte SBR converter for `add-shillinq-detachering-payroll-administratie` — distinct domain). No `BalanceSheetService`, `BalanceSheetBuilder`, `ProfitAndLossService`, `PLService`, `CashFlowStatementService`, `FinancialStatementService`, or generic `XbrlExporter`/`SbrService` exist — the ADR-031 anti-pattern enumeration is clean for the financial-statements slice.
- [ ] Task 2: Author `specs/bookkeeping-financial-statements/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-trial-balance` header, `REQ-FS-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-024 (Tier-4 manifest) + ADR-031 (declarative report assembly) inline; explicitly defer BBV to T3
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (renderer-library path, presentation-manifest expert review, XBRL taxonomy versioning) / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table, D1 (compositions of trial-balance aggregations), D2 (presentation manifest as seed), D3 (preferred CnReportPage / sunset bespoke fallback), D4 (declarative XBRL+PDF), D5 (manifest-side comparatives), D6 (BBV deferred to T3)
- [ ] Task 5: Ship `lib/Settings/statements/rj270-balance-sheet.json` with ~40 RJ 270 SMB balance-sheet line items (assets / liabilities / equity hierarchy mapped to RGS 3.5 account ranges); SPDX header + `_meta` block per `feedback_spdx-in-docblock.md`
- [ ] Task 6: Ship `lib/Settings/statements/rj270-pl.json` with ~30 RJ 270 SMB P&L line items (revenue / cost of sales / operating expenses / financial result / tax / net result)
- [ ] Task 7: Ship `lib/Settings/statements/rj270-cash-flow.json` with ~25 indirect-method cash-flow line items (operating / investing / financing activities)
- [ ] Task 8: Extend the repair step under `lib/Migration/` to import the 3 statement manifests idempotently (operator edits persist; re-runs do not overwrite) per REQ-FS-002
- [ ] Task 9: Declare statement aggregation compositions on `lib/Settings/shillinq_register.json` per REQ-FS-001 — each statement composes trial-balance aggregations; cash-flow aggregation defaults to indirect method per REQ-FS-003
- [ ] Task 10: Declare XBRL export as `x-openregister-calculations` field on each statement output per REQ-FS-007 (SBR-compatible XML; taxonomy version pinned); declare PDF export as manifest-driven action invoking `@conduction/nextcloud-vue` PDF utility
- [ ] Task 11: Resolve renderer path (`CnReportPage` library or per-statement bespoke Vue fallback) in `spec.md` discovery; if fallback chosen, annotate with sunset note mandating migration once library lands
- [ ] Task 12: Add 3 manifest navigation entries (`Balance Sheet`, `Profit & Loss`, `Cash Flow Statement`) under `Bookkeeping > Financial Statements` + their `type: report` (or fallback) pages to `src/manifest.json` per REQ-FS-003 + REQ-FS-007; XBRL + PDF export actions declared on each page; `node tests/validate-manifest.js` exits 0
- [ ] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note declaring the statement compositions + presentation-manifest pattern, citing ADR-031 and the BBV-deferred-to-T3 boundary

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the assembled outputs match known-good RJ 270 reference balance sheet + P&L + cash flow. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no report-builder service; renderer Tier-4 or fallback annotated; manifest carries the navigation; XBRL/PDF declarative). No source code changes outside `openspec/changes/add-shillinq-financial-statements/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for statement aggregation composition correctness vs RJ 270 reference, XBRL XML schema validation against SBR taxonomy, PDF generation, idempotent manifest import (pre-declared on Tasks 5–10); Playwright MCP browser tests for the 3 statement renders + XBRL + PDF export actions (pre-declared on Task 12); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/financial-statements.md` per ADR-030 journeydoc convention and commits Balance Sheet / P&L / Cash Flow screenshots to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Balance Sheet`, `Profit & Loss`, `Cash Flow Statement`, `Comparative`, `XBRL Export`, `PDF Export`, `Assets`, `Liabilities`, `Equity`, `Revenue`, `Operating Expenses`, `Net Result`.
