# Design — ZZP Tax Regime

**Status:** pr-created

## Context

The Dutch ZZP-er (self-employed without staff) faces an annually-
recurring IB-aangifte cycle: log hours toward the 1225-uren-per-
jaar criterium, derive zelfstandigenaftrek + (eventually)
startersaftrek + MKB-winstvrijstelling, and export the pre-filled
IB-aangifteformulier.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare the three ZZP registers as schemas with lifecycle +
  calculations per ADR-031.
- Express the urencriterium running-total as
  `x-openregister-calculations` (with ADR-031 exception path for
  cross-period).
- Express the deduction derivations as `x-openregister-calculations`
  reading from the seeded deduction-amounts table + GL revenue.
- Surface the urencriterium tracker as a `CnDashboardPage` widget
  per ADR-024 — no bespoke Vue.

## Non-Goals

- No app-local ZZP state-machine service.
- No app-local BSN store (consumed via OR PII abstraction).
- No daily cron job for urencriterium (ADR-031 anti-pattern).

## Decisions

### D1 — `UrenRegistratie` carries `excludedReason` enum

Per Wet IB 2001 art. 3.6, excluded hour categories (sick,
parental-leave, vacation, non-billable-admin) do NOT count toward
the 1225 criterium. `UrenRegistratie.excludedReason` is a non-null
enum on excluded entries; the aggregation filters by `excludedReason
IS NULL` for the qualifying total.

### D2 — Urencriterium running-total declarative, ADR-031 exception fallback

`ZzpDeduction.ytdQualifyingHours` declared via
`x-openregister-calculations` projecting sum of `UrenRegistratie.hours`
for the administration owner within the current fiscal year, filtered
by `excludedReason IS NULL`.

**Exception path (per ADR-031)**: If OR's engine cannot express the
cross-period aggregation, a single-method PHP guard
`UrencriteriumGuard::currentYtdHours(string $personId, int $year):
float` ships, ~30 LOC, no state, ADR-031 exception annotation.

### D3 — Deduction amounts as seed, calculations on top

Zelfstandigenaftrek and startersaftrek amounts are
annually-published statutory values (€3.750 / €2.123 in 2026 —
moving with each Belastingplan). The seed
`zzp-deduction-amounts-2026.json` carries the current year's
values; `ZzpDeduction.zelfstandigenaftrek` is a derived field
keyed off the seed.

MKB-winstvrijstelling is a percentage (13.31% in 2026) applied
to taxable profit; declared as `x-openregister-calculations`
multiplying T1 GL-derived profit by the seeded percentage.

### D4 — `IbAangifteExport` as state machine + mapping

`IbAangifteExport` follows `draft → generated → exported`
declared via `x-openregister-lifecycle`. The generation transition
runs an OR Mapping transformation producing the pre-filled
aangifte payload (XML or PDF, operator-configurable). No bespoke
PHP renderer in the canonical path; ADR-031 exception path
documented if the Belastingdienst payload shape cannot fit.

### D5 — Urencriterium widget on `CnDashboardPage`

The widget surfaces YTD qualifying hours, the 1225 target, the
percentage met, and the eligibility status. Declared via
`x-openregister-widgets`; rendered by `CnDashboardPage`. No
bespoke Vue.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `UrenRegistratie` storage | OR generic CRUD | Standard register |
| Cross-period qualifying-hours sum | `x-openregister-calculations` (ADR-031) | Declarative-first; ADR-031 exception for thin guard |
| Deduction derivations | `x-openregister-calculations` (ADR-031) | Derived field; reads seed + GL |
| Excluded-reason audit | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| IB aangifte mapping | OR Mapping engine | Declarative; exception path for PHP renderer |
| Dashboard widget | `x-openregister-widgets` + `CnDashboardPage` | Schema-derived; no bespoke Vue |
| RBAC (zzp-administrator) | OR authorization (ADR-022) | Per-schema role |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 3 menu entries under `Belastingen` with visibility predicate |
| Seed import | `ConfigurationService::importFromApp()` | 2 seed files |

**Net new code in implementation**: 3 schema declarations + 4
manifest entries + 2 seed JSONs. Possibly 1 thin PHP guard
(~30 LOC) and 1 thin XML/PDF renderer (~30 LOC) under ADR-031
exceptions.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Urencriterium running-total | Declarative (`x-openregister-calculations`) — OR thin guard per ADR-031 exception | Resolved in `opsx-ff` |
| Zelfstandigenaftrek derivation | Declarative (`x-openregister-calculations`) | Reads seed + eligibility flag |
| MKB-winstvrijstelling derivation | Declarative (`x-openregister-calculations`) | Profit × percentage |
| `IbAangifteExport` lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| IB aangifte payload generation | OR Mapping (preferred) or thin renderer (ADR-031 exception) | Resolved in `opsx-ff` |
| Dashboard widget | Declarative (`x-openregister-widgets`) | Schema-derived |
| Notification on criterium-met | Declarative (`x-openregister-notifications`) | Standard event-driven |

No service class authored beyond conditional ~30 LOC guards.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/urencriterium-thresholds.json` | 1225 (full) + 800 (starters opvolgers) | 2 | Wet IB 2001 art. 3.6 |
| `lib/Settings/seeds/zzp-deduction-amounts-2026.json` | Zelfstandigenaftrek + startersaftrek + MKB-winstvrijstelling % for 2026 | ~5 | Wet IB 2001 + Belastingplan 2026 |

SPDX header + `_meta` block with statutory citation + version.
Loaded via the repair step. Statutory — operator cannot override
without escalating to a fork.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Cross-period aggregation | ADR-031 exception; thin guard |
| Excluded-hours misclassification | Enum constraint per Wet IB 2001; audit-trail records changes |
| Deduction amount revision | Versioned seed; coexistence trivial |
| IB aangifte payload shape | OR Mapping preferred; ADR-031 exception fallback |
| Starters double-claim (>3 years) | `ZzpDeduction.startersClaimsThisRegime` tracks count; lifecycle gates startersaftrek to ≤3 claims per `REQ-ZZP-006` |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 3 schemas (additive).
2. `src/manifest.json` adds 3 navigation entries + 1 dashboard
   widget.
3. The repair step imports the 2 seed files (idempotent).
4. If exception paths trigger, the thin guards ship.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing hour entries and deduction calculations
remain queryable.

## Open Questions

1. **Cross-period aggregation expressibility** — resolved in
   `opsx-ff` discovery.
2. **Starters claim cardinality enforcement** — handled via
   `ZzpDeduction.startersClaimsThisRegime` running count; confirm
   with ZZP-administrateur persona.
