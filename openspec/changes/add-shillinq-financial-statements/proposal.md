# Proposal: add-shillinq-financial-statements

`kind: config` per ADR-032 — the centre of mass is declarative
compositions of trial-balance aggregations + presentation manifests
under `lib/Settings/statements/` + `x-openregister-calculations` for
XBRL + manifest entries. No PHP report builder is authored. A new
`CnReportPage` library component in `@conduction/nextcloud-vue` is
the preferred renderer (per ADR-024 Tier-4); a short bespoke Vue
fallback is the sunset path.

## Summary

Introduce the **financial statements** capability for Shillinq as one
of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
Balance Sheet, Profit & Loss, and Cash Flow statements as
compositions of trial-balance aggregations keyed against a
presentation manifest (RJ 270 / IFRS for SMEs for v1) for SMB
administrations. Year-over-year comparatives are a manifest-side
affordance; drill-through links to the trial balance + GL.
XBRL/PDF export ships as declarative calculations per ADR-031. BBV
(Dutch government) statements are explicitly T3.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(underlying `GLLine` data),
[`add-shillinq-trial-balance`](../add-shillinq-trial-balance/proposal.md)
(financial statements compose the trial-balance aggregation).

## Motivation

Financial statements (Balance Sheet, P&L, Cash Flow) are the
legally-required output for SMB administrations under RJ 270 and
the IFRS for SMEs (NL/EU). Without them, T1 + T2's foundation is
unauditable and unreportable.

Per ADR-031, statement assembly is declarative: compositions of
trial-balance aggregations + a presentation manifest. The
`BalanceSheetReportService.php` / `PLReportService.php` / etc. that
shillinq could otherwise grow into are the canonical anti-pattern.

This is one of eight T2 capability changes; this proposal scopes
only the financial-statements slice. BBV (government) statements
are explicitly deferred to T3.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-financial-statements`); ships 3 statement
  presentation manifests under `lib/Settings/statements/`
  (`rj270-balance-sheet.json`, `rj270-pl.json`,
  `rj270-cash-flow.json`); extends repair step to import them;
  declares XBRL calculation on each statement output; adds 3
  manifest navigation entries (Balance Sheet, Profit & Loss,
  Cash Flow Statement).
- [ ] Project: openregister — no source changes; consumes
  `x-openregister-aggregations` (composed trial-balance
  aggregations) and `x-openregister-calculations` (XBRL).
- [ ] Project: nextcloud-vue — preferred path requires a new
  `CnReportPage` component that takes a presentation manifest and
  renders any statement. Library change tracked separately per
  ADR-024 Tier-4 — if it doesn't ship in time, fallback is a short
  bespoke Vue per statement (with sunset note).

## Scope

### In Scope

- One new capability spec (`bookkeeping-financial-statements`) —
  see the `specs/` folder.
- 3 RJ 270 / IFRS-for-SMEs presentation manifests under
  `lib/Settings/statements/`:
  `rj270-balance-sheet.json`, `rj270-pl.json`,
  `rj270-cash-flow.json` (indirect method default for cash flow).
- Statement assembly: composition of trial-balance aggregations +
  the presentation manifest. Declarative; no PHP report builder.
- Year-over-year comparatives: the report manifest declares N
  comparison periods; aggregation runs once per period. No
  bespoke logic.
- Drill-through: each line item links to a filtered trial-balance
  (which itself drills to GL).
- XBRL export: declarative `x-openregister-calculations` producing
  SBR-compatible XML.
- PDF export: manifest-driven action invoking the existing
  `@conduction/nextcloud-vue` PDF utility.
- Renderer path: `CnReportPage` (preferred, ADR-024 Tier-4) or
  short bespoke Vue per statement (fallback, sunset note).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **BBV (Dutch government) statements** — T3.
- **Full IFRS** — T5.
- **Group consolidation, intercompany eliminations, segment
  reporting** — T5.
- **Multi-currency translation, FX revaluation, CTA postings** —
  T5.
- **Direct-method cash flow** — roadmap; T2 ships indirect method.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-financial-statements`** — declares the statement
composition + presentation manifest format + XBRL/PDF export + the
renderer-path decision (preferred + fallback). The spec follows
the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each
requirement is prefixed `REQ-FS-*` for traceability.

## New Dependencies

- **`@conduction/nextcloud-vue`** — preferred renderer path
  requires a new `CnReportPage` component (`add-cn-report-page`
  change on the library). If the library change doesn't ship in
  time, fallback is a short bespoke Vue per statement (with sunset
  note).

Otherwise none.

## Impact

- `lib/Settings/shillinq_register.json` — declares the statement
  aggregations + XBRL calculations.
- `lib/Settings/statements/rj270-balance-sheet.json`,
  `lib/Settings/statements/rj270-pl.json`,
  `lib/Settings/statements/rj270-cash-flow.json` — new files.
- Repair step (`lib/Migration/Version*.php` or repair class) —
  extends the existing seed-import pattern to load the 3 statement
  manifests.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: report` (preferred) or per-statement bespoke page entries.
- No new PHP services. Possibly 0–3 bespoke Vue files if
  `CnReportPage` isn't ready (sunset path).

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-aggregations`
  composition + multi-period parameterisation and
  `x-openregister-calculations` for XBRL string emission.
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  for underlying `GLLine` data.
- **T2 trial balance** — depends on
  `add-shillinq-trial-balance` (statements compose the trial-
  balance aggregation).
- **nextcloud-vue** — preferred `CnReportPage` component.

## Risks

### Risk 1: OR aggregation engine cannot express opening/closing buckets in one query

**Severity**: Medium
**Mitigation**: Same as for trial-balance: each bucket is its own
aggregation, presentation layer composes them (still declarative).
The shared resolution lives in the trial-balance spec.

### Risk 2: Financial-statement renderer (`CnReportPage`) not yet in nextcloud-vue

**Severity**: Low-Medium
**Mitigation**: Two paths declared. Path (a) — `CnReportPage` in
`@conduction/nextcloud-vue` — preserves ADR-024 Tier-4 and is
preferred. Path (b) — short bespoke Vue per statement — is the
fallback if the library doesn't ship in time, with a sunset note
mandating migration to (a) once the library lands. Decision lives
in `spec.md` discovery during `opsx-ff`.

### Risk 3: XBRL schema versioning

**Severity**: Low
**Mitigation**: Pin SBR taxonomy version in the spec; coordinate
with the SBR governance body on any future schema bump. The
calculation extension treats the taxonomy as a parameter.

### Risk 4: RJ 270 / IFRS for SMEs presentation manifests need expert review

**Severity**: Medium
**Mitigation**: The 3 manifest files are reviewed by a competent
Dutch SMB bookkeeper persona (`/test-persona-janwillem`) before
seeding; the assembled output is reconciled against a known-good
RJ 270 reference. The implementing cycle gates merge on bookkeeper
peer-review.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
statement manifests are seeded but non-destructive — disabling the
seed import + reverting the manifest leaves stranded but queryable
records.

## Open Questions

1. **Single-query vs three-composed bucket aggregation** — see
   Risk 1; resolved in trial-balance spec discovery; financial-
   statements inherits the resolution.
2. **`CnReportPage` library availability** — see Risk 2; resolved
   in `spec.md` discovery; spec shape-neutral.
3. **Direct vs indirect cash flow default** — T2 defaults to
   indirect; direct-method variant on roadmap.
4. **XBRL taxonomy version** — pin during implementing cycle
   against current SBR catalogue.
