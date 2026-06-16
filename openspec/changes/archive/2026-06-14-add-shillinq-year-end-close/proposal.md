# Proposal: add-shillinq-year-end-close

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries. No PHP service classes are
authored.

## Summary

Introduce the **fiscal-year close with re-opening guard** capability
for Shillinq as part of the Tier 4 advanced bookkeeping engine (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
`FiscalYear` register with `open → closing → closed → reopened`
`x-openregister-lifecycle` (per ADR-031); closing emits a
retained-earnings transfer `JournalEntry` and a next-year
opening-balance `JournalEntry` (T1 primitives); dimensional rollover
fires via OR CloudEvents; admin-only reopen consumes OR RBAC per
ADR-022 and requires a non-empty `reopenReason`, emitting two
reversing `JournalEntry` records that pair with the original closing +
opening journals for full audit traceability. No PHP
`YearEndCloseService`.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

The legal closure of a fiscal year — opening-balance transfer,
retained-earnings posting, dimensional rollover, controlled re-opening
— is the highest-stakes operation in any bookkeeping system. Without
a controlled year-end close, Shillinq cannot serve as a complete
bookkeeping system. The declarative approach (per ADR-031) makes the
implementation auditable: the entire close lives in schema metadata,
the audit chain is queryable post-hoc, and reopen is a designed
escape hatch rather than a bypass.

This proposal is one of seven sibling Tier 4 capability changes
extracted from the bundled `add-shillinq-bookkeeping-advanced` proposal
to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`FiscalYear`)
  to `lib/Settings/shillinq_register.json`, adds 1 manifest navigation
  entry in `src/manifest.json`.
- [ ] Project: openregister — no source changes; this change consumes
  `x-openregister-lifecycle`, audit-trail-immutable, RBAC,
  CloudEvents.

## Scope

### In Scope

- One new capability spec (`bookkeeping-year-end-close`) — see the
  `specs/` folder.
- `FiscalYear` register declaration with `yearNumber` unique per
  `administrationId`.
- `open → closing → closed → reopened` lifecycle with declarative
  transitions; closing emits two T1 `JournalEntry` records
  (retained-earnings transfer in year N, opening-balance journal in
  year N+1).
- Dimensional rollover via OR CloudEvents consumed by dimension
  registers (CostCenter/KostenDrager/Project archive flags carried
  forward).
- Admin-only reopen consuming OR RBAC `admin` role per ADR-022, with
  `reopenReason` required precondition, emitting two reversing
  `JournalEntry` records for audit pair traceability.
- Manifest navigation entry (Bookkeeping > Fiscal Years) using
  `type: index` / `type: detail` renderers; detail page surfaces
  close + reopen actions gated by role per REQ-YEC-006.

### Out of Scope

- **Implementation code** — this is a spec-only change.
- **Sector-specific close logic** (housing-corp jaarrekening posting,
  municipal IV3 reporting) — owned by separate compliance / gov
  changes.
- **Frontend Vue components** beyond `CnIndexPage` / `CnDetailPage`
  generic rendering.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-year-end-close`** — declares the `FiscalYear` register
with the four-state lifecycle, retained-earnings + opening-balance
journal emission as lifecycle actions, dimensional rollover via
CloudEvents, and the admin-only reverse-and-reopen escape hatch.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-YEC-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions
(including the T3 `bookkeeping-period-close` capability) and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`FiscalYear`)
  with `x-openregister-lifecycle` and uniqueness on (`yearNumber`,
  `administrationId`).
- `src/manifest.json` — adds 1 navigation entry (Fiscal Years) with
  role-gated close + reopen actions on the detail page.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` (including
  required-field preconditions for `reopenReason`), audit-trail-
  immutable, RBAC (`admin` role reference per ADR-022), CloudEvents.
- **T1 `bookkeeping-general-ledger`** — closing emits T1
  `JournalEntry` records (manual + reversing sub-types).
- **T3 `bookkeeping-period-close`** — year-end close depends on all
  T3 fiscal periods being closed; per REQ-YEC-007 the lifecycle
  precondition gates `open → closing` on all period states.

## Risks

### Risk 1: Year-end close is the single highest-stakes operation; a wrong implementation could corrupt every downstream period

**Severity**: High
**Mitigation**: The close is declarative (no PHP
`YearEndCloseService`, per REQ-YEC-001) so the implementing cycle's
review surface is the schema metadata, which is tight. The
reversibility of `closed → reopened` (REQ-YEC-006) is preserved as a
designed admin-only escape hatch with reverse-and-reopen audit trail.
Implementation cycle MUST include integration tests: close a year with
mixed P&L, verify retained earnings posting, verify opening-balance
journal, verify re-open emits both reversing entries, verify the audit
chain is queryable.

### Risk 2: Single-true `isClosingAccount` enforcement gap

**Severity**: Low–Medium
**Mitigation**: T1 `REQ-CoA-009` declared the uniqueness; T4's
year-end close depends on it. The implementing cycle confirms during
`opsx-ff` whether OR has a native single-true validator or whether
T1's thin lifecycle precondition is the canonical answer. The spec
stays shape-neutral (REQ-YEC-003 names "the closing account" without
re-specifying the cardinality rule).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR. The `FiscalYear`
register is additive — existing T1/T2/T3 records are unaffected. If
an in-progress close needs to be aborted, the `closed → reopened`
escape hatch is the canonical path.

## Open Questions

1. **Single-true `isClosingAccount` enforcement** — see Risk 2.
2. **Dimensional rollover scope** — does rollover carry budget records
   forward (per T4 `bookkeeping-reconciliation-reports`)? Defaults to
   No (budgets are per-period and authored fresh), confirm with
   bookkeeper persona during implementing cycle review.
