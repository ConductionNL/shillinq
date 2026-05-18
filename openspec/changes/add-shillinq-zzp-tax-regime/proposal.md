# Proposal: add-shillinq-zzp-tax-regime

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + threshold + deduction seed
data. One conditional thin PHP guard permitted under ADR-031
exception (~30 LOC) for cross-period urencriterium aggregation.

## Summary

Introduce **ZZP tax regime** support for Shillinq as a T3 capability
per `adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`UrenRegistratie`, `ZzpDeduction`, and `IbAangifteExport` registers
with `x-openregister-lifecycle` rules (per ADR-031), declares the
1225-urencriterium running-total as `x-openregister-calculations`
(with ADR-031 exception path), wires navigation into
`src/manifest.json` + urencriterium-widget on the dashboard per
ADR-024, and ships `urencriterium-thresholds.json` +
`zzp-deduction-amounts-2026.json` seeds. No PHP service classes for
state machines or aggregation orchestration.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T1 `bookkeeping-general-ledger` (zelfstandigenaftrek
and MKB-winstvrijstelling derive from GL revenue/profit).

## Motivation

Every Dutch ZZP-er (freelancer / self-employed) needs to track:

- 1225 uren per jaar criterium (`urencriterium`) for
  zelfstandigenaftrek eligibility.
- Zelfstandigenaftrek + startersaftrek (annually-published
  deductions).
- MKB-winstvrijstelling (currently 13.31% per Wet IB 2001 art.
  3.79a).
- IB-aangifteformulier export with all of the above pre-filled.

Without ZZP support, every freelancer using Shillinq must
duplicate-enter into an external IB-aangifte tool — defeating
the suite's value.

## Affected Projects

- [x] Project: shillinq — adds 3 new registers/schemas
  (`UrenRegistratie`, `ZzpDeduction`, `IbAangifteExport`) to
  `lib/Settings/shillinq_register.json`, adds 4 manifest navigation
  entries (`Belastingen > Urenregistratie`, `> ZZP-aftrek`,
  `> IB-aangifte`, dashboard widget for urencriterium), ships 2 seed
  files (`urencriterium-thresholds.json`,
  `zzp-deduction-amounts-2026.json`).
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions. Conditional thin PHP guard under ADR-031 exception.

## Scope

### In Scope

- One new capability spec (`bookkeeping-zzp-tax-regime`).
- `UrenRegistratie` register with hour categories (billable,
  non-billable-admin, sick, parental-leave, vacation) and
  `excludedReason` enum per Wet IB 2001 (excluded categories do
  NOT count toward 1225).
- `ZzpDeduction` register tracking zelfstandigenaftrek + starters-
  aftrek + MKB-winstvrijstelling derived amounts.
- `IbAangifteExport` register producing the pre-filled IB
  aangifteformulier.
- 1225-urencriterium running-total as `x-openregister-calculations`
  (with ADR-031 exception path for cross-period).
- Urencriterium-tracker widget on `CnDashboardPage` via
  `x-openregister-widgets`.
- Notification when 1225 threshold is crossed (status: criterium-met).

### Out of Scope

- **Multi-currency, FX overlay** — T5.
- **Implementation code** — spec-only change.
- **BSN handling** — operator enters BSN via existing OR PII
  abstraction (per ADR-022); no app-local BSN store.

## Approach

One delta with ADDED Requirements under `REQ-ZZP-*`.

## New Dependencies

None. Consumes T1 GL + existing OR abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas with
  lifecycle + calculations + notifications + widgets.
- `lib/Settings/seeds/urencriterium-thresholds.json` (1225 full,
  800 starters opvolgers) — new file.
- `lib/Settings/seeds/zzp-deduction-amounts-2026.json` —
  annually-published deductions (zelfstandigenaftrek, starters-
  aftrek, MKB-winstvrijstelling %) — new file.
- `src/manifest.json` — adds 3 navigation entries + 1 dashboard
  widget under `Belastingen`, visibility predicate for
  `zzp`/`mkb` admins.
- Repair step extension to import the seeds.
- Conditional `lib/Lifecycle/UrencriteriumGuard.php` if discovery
  confirms exception path (~30 LOC, single method, no state).

## Cross-Project Dependencies

- **OpenRegister** — relies on cross-period
  `x-openregister-calculations` inside lifecycle preconditions. If
  unsupported, ADR-031 exception with thin guard.

## Risks

### Risk 1: Cross-period urencriterium aggregation

**Severity**: Medium
**Mitigation**: ADR-031 exception with thin PHP guard
(`UrencriteriumGuard::currentYtdHours($personId, $year)`, ~30 LOC,
single method, no state). Resolved during `opsx-ff` discovery.

### Risk 2: Excluded-hours classification ambiguity

**Severity**: Low
**Mitigation**: `UrenRegistratie.excludedReason` enum is enumerated
per Wet IB 2001 (sick, parental-leave, vacation, non-billable-
admin); operator marks per entry; audit-trail records every
change.

### Risk 3: Deduction amounts revision

**Severity**: Low
**Mitigation**: Versioned seed (`zzp-deduction-amounts-2026.json`
→ `zzp-deduction-amounts-2027.json`); operator can swap seed file.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **Cross-period aggregation** — see Risk 1.
2. **Starters definition** — `startersaftrek` requires meeting
   `urencriterium` AND being a starter (first 5 years of
   self-employment, max 3 claims). `ZzpDeduction` tracks both
   eligibility flags; confirm with ZZP-administrateur persona.
