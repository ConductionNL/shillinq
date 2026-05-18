# Proposal: add-shillinq-kor-kleine-ondernemersregeling

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + threshold seed data. One
conditional thin PHP guard permitted under ADR-031 exception (~30
LOC) for cross-period YTD aggregation if the engine cannot express
it declaratively.

## Summary

Introduce **KOR (Kleine Ondernemersregeling) opt-in/opt-out
lifecycle** for Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`KorRegime` and `KorThreshold` registers with
`x-openregister-lifecycle` rules tracking the €20.000 omzetdrempel
(per ADR-031), declares the YTD revenue aggregation as
`x-openregister-calculations` over T1 GL / T2 Invoice records
(with ADR-031 exception path for cross-period aggregation), wires
the KOR-status widget into the dashboard per ADR-024, and ships
the `kor-thresholds-2026.json` seed. No PHP service classes for
state machines; auto-regime switch is triggered by calculation-
crossing — NOT by a daily cron job.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T3 `bookkeeping-vat-btw-filing` (KOR opt-out
generates a `JournalEntry` for the regime change; KOR opt-in
suppresses BTW on outputs).

## Motivation

Every small MKB operator approaching €20.000 jaaromzet needs:

- A way to opt into KOR for a fiscal year (vrijstelling van BTW
  op outputs).
- A YTD revenue tracker with alarm at 80% (warning) and 100%
  (alarm) of the omzetdrempel.
- Auto opt-out when threshold is crossed — the regime change
  itself generates a `JournalEntry` template the operator + accountant
  review (NEVER auto-posted per ADR's safety constraint).
- Audit-grade record of opt-in / opt-out events and the threshold
  crossings that triggered them.

Without KOR support, a small MKB user must run Shillinq alongside
an external KOR-tracker — defeating the suite's value.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas (`KorRegime`,
  `KorThreshold`) to `lib/Settings/shillinq_register.json`, adds 1
  manifest navigation entry (`Belastingen > KOR-status`) +
  KOR-threshold widget on `CnDashboardPage`, ships
  `lib/Settings/seeds/kor-thresholds-2026.json`.
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions (lifecycle, calculations, notifications, widgets).
  Conditional thin PHP guard ships under ADR-031 exception if
  cross-period aggregation isn't expressible declaratively.

## Scope

### In Scope

- One new capability spec (`bookkeeping-kor-kleine-ondernemersregeling`).
- `KorRegime` register tracking `(administrationId, fiscalYear,
  state, ytdRevenue, optedInOn, optedOutOn, exceededOn)`.
- `KorThreshold` register seeded from `kor-thresholds-2026.json`.
- Lifecycle `outside → opted-in → threshold-warning → threshold-
  exceeded → opted-out` triggered by calculation-crossing per
  ADR-031.
- YTD revenue aggregation as `x-openregister-calculations` over T1
  GL revenue accounts within the current fiscal year — ADR-031
  exception path for thin PHP guard if engine cannot express.
- Notification at 80% (warning) and 100% (alarm) thresholds via
  `x-openregister-notifications`.
- KOR-status widget on `CnDashboardPage` via
  `x-openregister-widgets`.
- Auto-generated `JournalEntry` template on opt-out — NEVER
  auto-posted; operator + accountant approval gate.

### Out of Scope

- **BTW/VAT filing** — owned by sibling
  `add-shillinq-vat-btw-filing` (KOR suppresses BTW on outputs;
  the suppression logic lives on the VAT spec's posting
  precondition referencing `KorRegime.state`).
- **Implementation code** — spec-only change.
- **Bespoke Vue components** beyond the manifest-driven
  `CnDashboardPage` widget.

## Approach

One delta with ADDED Requirements under `REQ-KOR-*`. The cross-
period YTD aggregation is declared first via
`x-openregister-calculations`; if `opsx-ff` discovery confirms the
engine cannot express it, the spec's `REQ-KOR-004` documents the
ADR-031 exception and the implementing cycle ships a
`KorThresholdGuard::currentYtdRevenue($adminId, $year)` single-
method PHP guard (~30 LOC, no state).

## New Dependencies

None. Consumes T3 VAT-filing + existing OR abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas with
  lifecycle + calculations + notifications + widgets.
- `lib/Settings/seeds/kor-thresholds-2026.json` — new file
  (omzetdrempel €20.000, warning 80%), SPDX header,
  `_meta.source: "Wet OB 1968 art. 25 lid 1"`.
- `src/manifest.json` — adds 1 navigation entry under `Belastingen`
  + the KOR-threshold dashboard widget.
- Repair step extension to import the KOR threshold seed.
- Conditional `lib/Lifecycle/KorThresholdGuard.php` if discovery
  confirms exception path (~30 LOC, single method, no state).

## Cross-Project Dependencies

- **OpenRegister** — relies on cross-period `x-openregister-calculations`
  inside `x-openregister-lifecycle.requires`. If unsupported,
  ADR-031 exception path; file OR issue.

## Risks

### Risk 1: Cross-period YTD aggregation expressibility

**Severity**: Medium
**Mitigation**: ADR-031 exception path with thin PHP guard
(~30 LOC, ADR-031 exception annotation). Resolved during
`opsx-ff` discovery.

### Risk 2: Opt-out auto-posting safety

**Severity**: Medium → mitigated
**Mitigation**: `REQ-KOR-006` MANDATES the opt-out journal entry
ships in `state: pending` (NOT `posted`); operator + accountant
approval gates posting. Tested explicitly in implementing cycle's
PHPUnit.

### Risk 3: Threshold revision (€20.000 may move)

**Severity**: Low
**Mitigation**: Versioned seed (`kor-thresholds-2026.json` →
`kor-thresholds-2027.json`); `_meta.source` tracks the statutory
citation.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **Cross-period aggregation** — see Risk 1.
2. **Fiscal-year boundary** — calendar year per Wet OB 1968;
   gebroken boekjaar (non-calendar) operators are rare for KOR
   eligibility but if present, `fiscalYear` aligns with the
   administration's declared fiscal year, not the calendar year.
   Confirm with bookkeeper persona.
