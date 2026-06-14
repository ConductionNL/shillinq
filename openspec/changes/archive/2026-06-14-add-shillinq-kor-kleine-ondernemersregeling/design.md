# Design — KOR (Kleine Ondernemersregeling)

## Context

KOR is the Dutch VAT exemption regime for small operators under
€20.000 jaaromzet. Operators opt in voluntarily for a fiscal
year; they MUST opt out (and remain out for at least 3 years)
once the threshold is crossed mid-year.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

**Status:** pr-created

## Goals

- Declare the KOR regime as a register with a 5-state lifecycle
  per ADR-031.
- Express the YTD revenue tracker as a derived field via
  `x-openregister-calculations` (with ADR-031 exception path for
  cross-period aggregation if engine cannot express).
- Express the auto-regime switch as a calculation-crossing
  trigger — NOT a daily cron job per ADR-031.
- Surface the threshold-warning + threshold-exceeded events as
  notifications + dashboard widget — no bespoke Vue.
- Mandate the opt-out journal entry ships in `pending` state for
  operator + accountant approval (safety constraint).

## Non-Goals

- No app-local KOR state-machine service.
- No daily cron job recomputing YTD (ADR-031 anti-pattern).
- No auto-posting of the opt-out journal entry (safety
  constraint).

## Decisions

### D1 — Lifecycle declarative with calculation-crossing triggers

`outside → opted-in → threshold-warning (80%) → threshold-exceeded
(100%) → opted-out` declared via `x-openregister-lifecycle`. The
`opted-in → threshold-warning` and `threshold-warning → threshold-
exceeded` transitions trigger automatically when
`KorRegime.ytdRevenue` crosses the seeded threshold percentages.
This is the chain `calculation → lifecycle.requires → state
transition → notification` — entirely declarative.

### D2 — YTD aggregation declarative, ADR-031 exception fallback

`KorRegime.ytdRevenue` is declared via
`x-openregister-calculations` projecting sum-of-revenue from T1
`GLLine` rows (or T2 `Invoice` totals) within the current fiscal
year for the administration.

**Exception path (per ADR-031)**: If OR's calculation engine
cannot express the cross-period (year-to-date) aggregation, a
single-method PHP guard
`KorThresholdGuard::currentYtdRevenue(string $adminId, int $year):
float` ships, ~30 LOC, no state, ADR-031 exception annotation.
Referenced from the lifecycle's `requires` precondition.

### D3 — Opt-out journal entry ships in `pending`, never `posted`

The opt-out transition (`threshold-exceeded → opted-out`) generates
a `JournalEntry` template for the regime change (input VAT
reclamation reversal, etc.). Per `REQ-KOR-006`, the journal entry
ships in `state: pending` — operator + accountant approval gates
posting. This is the safety constraint: silent auto-posting of a
regime change has material tax impact.

### D4 — Threshold-warning widget on `CnDashboardPage`

The widget surfaces YTD revenue, the threshold, and the regime
state as a percentage + status indicator. Declared via
`x-openregister-widgets`; rendered by `CnDashboardPage` from
`@conduction/nextcloud-vue`. No bespoke Vue.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `KorRegime` lifecycle with auto-transitions | `x-openregister-lifecycle` (ADR-031) | Declared on schema; transitions triggered by calculation-crossing |
| YTD revenue derivation | `x-openregister-calculations` (ADR-031) | Cross-period aggregation; ADR-031 exception path for thin guard |
| Threshold-warning notification | `x-openregister-notifications` (ADR-031) | Fires on state-transition events |
| Dashboard widget | `x-openregister-widgets` + `CnDashboardPage` | Schema-derived; no bespoke Vue |
| Approval gate on opt-out journal entry | T2 `bookkeeping-period-close` approval-workflow | Consumed via the journal entry's own lifecycle |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| RBAC (vat-administrator) | OR authorization (ADR-022) | Per-schema role; shared with VAT-filing spec |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 1 menu entry under `Belastingen` with visibility predicate for `mkb`/`zzp` admins |
| Threshold seed | `ConfigurationService::importFromApp()` | `kor-thresholds-2026.json` |

**Net new code in implementation**: 2 schema declarations + 1
manifest entry + 1 widget + 1 seed JSON. Possibly 1 thin PHP
guard (~30 LOC) if Risk 1 exception path triggers.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| KOR lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| YTD revenue tracker | Declarative (`x-openregister-calculations`) — OR thin guard (~30 LOC) per ADR-031 exception | Resolved in `opsx-ff` |
| Auto-regime switch | Declarative (calculation-crossing → lifecycle transition) | Standard chain |
| Threshold notification | Declarative (`x-openregister-notifications`) | Standard event-driven |
| Dashboard widget | Declarative (`x-openregister-widgets`) | Schema-derived |
| Opt-out journal entry | Declarative template, `pending` state, approval gate | Safety constraint enforced declaratively |

No service class authored beyond the conditional ~30 LOC guard.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/kor-thresholds-2026.json` | Omzetdrempel €20.000 + warning percentage 80% | 1 | Wet OB 1968 art. 25 lid 1 |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
`feedback_spdx-in-docblock.md`. `_meta` block with `source` and
`version` fields. Statutory — operator cannot override the
canonical threshold without escalating to a fork.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Cross-period aggregation | ADR-031 exception with thin guard; resolved in `opsx-ff` |
| Opt-out auto-posting | `REQ-KOR-006` mandates `pending` state; tested in implementing cycle |
| Threshold revision | Versioned seed; coexistence trivial |
| Mid-year opt-in with prior-year invoices | `fiscalYear` scope limits the YTD calc; prior-year invoices excluded |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 2 schemas (additive).
2. `src/manifest.json` adds 1 navigation entry + 1 dashboard widget.
3. The repair step imports the KOR threshold seed (idempotent).
4. If exception path triggers, the thin guard ships.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing regime records remain queryable.

## Open Questions

1. **Cross-period aggregation expressibility** — resolved in
   `opsx-ff` discovery.
2. **Gebroken boekjaar (non-calendar fiscal year)** — `fiscalYear`
   aligns with the administration's declared fiscal year per
   `REQ-KOR-002`; confirm with bookkeeper persona.
