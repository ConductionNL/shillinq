# Design — Subsidie Verantwoording

## Context

Awb 4.2 + the VNG ASV-model define the canonical Dutch grant
lifecycle: aanvraag → verleend → vastgesteld → uitbetaald →
(eventueel) teruggevorderd → (eventueel) afbetalingsregeling.
Every gemeente subsidies department, provincie subsidieverstrekker,
and grant recipient runs against this pattern.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare `Subsidie` as a register with the full 8-state lifecycle
  per ADR-031.
- Avoid the parallel-state-machine anti-pattern (ADR-022) for
  terugvordering settlement plans — sub-state + FK relation to
  `RepaymentInstallment`.
- Surface the lifecycle states with Awb article citations from a
  seeded template, operator-editable per administration.
- Consume approval-workflow from OR for `verleen` and `terugvorder`
  transitions per ADR-022.

## Non-Goals

- No app-local `SubsidieService` (ADR-031 anti-pattern).
- No parallel state machine for terugvordering (ADR-022 anti-
  pattern).
- No auto-posting of the uitbetaling journal entry — safety
  constraint.

## Decisions

### D1 — `Subsidie` 8-state lifecycle declarative

`aanvraag → verleend → ingetrokken / gewijzigd → vastgesteld →
uitbetaald → teruggevorderd → in-afbetalingsregeling` declared via
`x-openregister-lifecycle`. The graph permits:

- `aanvraag → verleend` (approval-gated)
- `verleend → ingetrokken` (operator-initiated, audit-logged)
- `verleend → gewijzigd → verleend` (loop for revisions)
- `verleend → vastgesteld` (operator-initiated post final
  declaration)
- `vastgesteld → uitbetaald` (generates `JournalEntry pending`)
- `uitbetaald → teruggevorderd` (approval-gated)
- `teruggevorderd → in-afbetalingsregeling` (optional sub-state)
- `in-afbetalingsregeling → uitbetaald` (after settlement complete)

### D2 — Terugvordering settlement plan as FK register, not parallel state

The settlement plan (afbetalingsregeling) needs its own payment
schedule. Per ADR-022, this is NOT a parallel state machine
inside `Subsidie`; it's a `RepaymentInstallment` register linked by
FK. Each instalment carries its own `state: scheduled / paid /
overdue / written-off`.

**Alternative considered**: Embed payment schedule as a JSON
array on `Subsidie`. Rejected — opaque to OR aggregations + audit
+ RBAC.

### D3 — Uitbetaling journal entry `pending` not `posted`

Per `REQ-SUB-005`, the `vastgesteld → uitbetaald` transition
generates a `JournalEntry` template in `state: pending`. Accountant
approval gates posting. Safety constraint enforced declaratively.

### D4 — ASV-model seed for lifecycle citations

`asv-model-lifecycle.json` ships the 6+ canonical lifecycle states
with their Awb article references (e.g. `verleend → Awb 4:25`,
`vastgesteld → Awb 4:46`, etc.). Operators see the state names
translated to plain Dutch in the UI; the Awb citation is available
on hover.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `Subsidie` 8-state lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on schema |
| Approval gate on `verleen` and `terugvorder` | OR approval-workflow (ADR-022) | Consumed via `requires` |
| Settlement plan instalments | `RepaymentInstallment` register linked by FK | No parallel state machine |
| Beschikking storage | docudesk attachment URI (ADR-022) | `Subsidie.beschikkingUri` |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| RBAC (subsidie-coordinator) | OR authorization (ADR-022) | Per-schema role |
| Notifications (vervaldatum, overdue instalment) | `x-openregister-notifications` (ADR-031) | Standard event-driven |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 2 menu entries under `Subsidies` |
| Lifecycle citations seed | `ConfigurationService::importFromApp()` | `asv-model-lifecycle.json` |

**Net new code in implementation**: 2 schema declarations + 2
manifest entries + 1 seed JSON. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| 8-state subsidie lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| Terugvordering settlement | Declarative (sub-state + FK to `RepaymentInstallment`) | ADR-022 — no parallel state machine |
| Approval gates | Declarative (`requires.approval-workflow`) | Standard precondition |
| Uitbetaling journal entry | Declarative template, `pending` state, approval gate | Safety constraint enforced declaratively |
| Notifications on vervaldatum | Declarative (`x-openregister-notifications`) | Standard event-driven |
| Audit trail | OR audit-trail-immutable | ADR-022 |

No service class authored.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/asv-model-lifecycle.json` | 6+ canonical lifecycle states with Awb article references | 6-8 | Awb 4.2 + VNG ASV-model 2022 |

SPDX header + `_meta.source: "Awb 4.2 + VNG ASV-model 2022"` +
`_meta.version` field. Loaded via the repair step. Per-
administration override allowed for state labels (translation) but
not Awb citations.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Terugvordering shape | Sub-state + FK; reviewed with persona |
| ASV-model revision | Versioned seed (`asv-model-2022` → future); per-admin override |
| Auto-posting safety | `REQ-SUB-005` mandates `pending` state |
| Multi-year subsidie tranches | Each tranche a separate `Subsidie` record; FK to parent for grouping (per `REQ-SUB-009`) |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 2 schemas (additive).
2. `src/manifest.json` adds 2 navigation entries.
3. The repair step imports the ASV-model seed (idempotent).

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing subsidies remain queryable.

## Open Questions

1. **Afbetalingsregeling shape** — sub-state + FK confirmed; final
   review with subsidie-administrateur persona during spec review.
2. **Tranche linkage** — `REQ-SUB-009` proposes FK-to-parent for
   multi-year subsidies; confirm during spec review.
