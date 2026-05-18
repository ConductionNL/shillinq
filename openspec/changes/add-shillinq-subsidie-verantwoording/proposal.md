# Proposal: add-shillinq-subsidie-verantwoording

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + ASV-model lifecycle seed. No
PHP service classes are authored.

## Summary

Introduce **subsidie verantwoording (grant lifecycle)** for
Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change declares the
`Subsidie` and `RepaymentInstallment` registers with
`x-openregister-lifecycle` rules per Awb 4.2 + ASV-model (per
ADR-031), declares the terugvordering settlement plan as an
FK-related register (NOT a parallel state machine per ADR-022),
wires navigation into `src/manifest.json` (per ADR-024), and ships
`asv-model-lifecycle.json` seed. No PHP service classes for state
machines.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T1 `bookkeeping-general-ledger` (subsidie
uitbetaling generates a `JournalEntry`).

## Motivation

Every Dutch grant recipient (gemeente subsidies, provincie
subsidies, EU-fund recipients, MKB-INNO grants, etc.) faces the
ASV-model lifecycle: aanvraag → verleend → vastgesteld →
uitbetaald → (eventueel) teruggevorderd → (eventueel)
afbetalingsregeling. Without subsidie support, a Shillinq-running
operator must run subsidie administration in a separate tool —
defeating the suite's value.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas (`Subsidie`,
  `RepaymentInstallment`) to `lib/Settings/shillinq_register.json`,
  adds 2 manifest navigation entries (`Subsidies > Aanvragen`,
  `> Terugvorderingen`) in `src/manifest.json`, ships
  `lib/Settings/seeds/asv-model-lifecycle.json`.
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions (lifecycle, audit, RBAC, approval-workflow,
  notifications).
- [ ] Project: docudesk — referenced by URI from
  `Subsidie.beschikkingUri` for verleningsbeschikkingen +
  vaststellingsbeschikkingen.

## Scope

### In Scope

- One new capability spec (`bookkeeping-subsidie-verantwoording`).
- `Subsidie` register with 8-state lifecycle per Awb 4.2 (aanvraag,
  verleend, ingetrokken, gewijzigd, vastgesteld, uitbetaald,
  teruggevorderd, in-afbetalingsregeling).
- `RepaymentInstallment` register linked by FK from `Subsidie` for
  the terugvordering settlement-plan instalments (NOT a parallel
  state machine per ADR-022).
- Approval-workflow gates on `verleen` and `terugvorder` transitions
  consumed from OR per ADR-022.
- `vastgesteld → uitbetaald` transition generates a `JournalEntry`
  in `state: pending` (approval-gated by accountant).
- Manifest navigation under `Subsidies` (visible for all admin
  types).
- ASV-model lifecycle states with Awb article citations seeded.

### Out of Scope

- **EU-fund-specific reporting templates** — out of scope (roadmap).
- **Implementation code** — spec-only change.
- **Bespoke Vue components** beyond manifest-driven generic pages.

## Approach

One delta with ADDED Requirements under `REQ-SUB-*`.

## New Dependencies

None. Consumes T1 GL + existing OR abstractions + docudesk.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas with
  lifecycle.
- `lib/Settings/seeds/asv-model-lifecycle.json` — new file (6+
  canonical lifecycle states with their Awb article references).
- `src/manifest.json` — adds 2 navigation entries under
  `Subsidies`.
- Repair step extension to import the ASV-model seed.
- No new PHP services.

## Cross-Project Dependencies

- **OpenRegister** — relies on lifecycle + approval-workflow +
  cross-schema FK. Standard shape.
- **docudesk** — symbolic URI reference; no coupling.

## Risks

### Risk 1: Terugvordering settlement-plan shape

**Severity**: Low → mitigated
**Mitigation**: Sub-state on `Subsidie` (`in-afbetalingsregeling`)
with FK to `RepaymentInstallment` records per ADR-022. Reviewed
with subsidie-administrateur persona.

### Risk 2: ASV-model revision

**Severity**: Low
**Mitigation**: Versioned seed (`asv-model-lifecycle.json` could
ship as `asv-model-2022.json` if VNG bumps the template); operator-
editable per administration.

### Risk 3: Auto-posting of uitbetaling journal

**Severity**: Medium → mitigated
**Mitigation**: `REQ-SUB-005` mandates the uitbetaling journal
entry ships in `state: pending` (NEVER `posted`); accountant
approval gates posting.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **Afbetalingsregeling shape** — sub-state + FK per `REQ-SUB-008`.
   Confirm with subsidie-administrateur persona.
2. **Multi-year subsidie tranches** — large subsidies may be paid
   in annual tranches; current spec models each tranche as a
   separate `Subsidie` record per `REQ-SUB-009`. Confirm during
   spec review.
