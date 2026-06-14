# Design — VAT/BTW Filing

**Status:** pr-created

## Context

Tier 1 gave Shillinq a balanced double-entry GL. Tier 2 gives it
periods, period close, and AP/AR. **BTW filing is the first
operator-facing compliance surface that turns the bookkeeping into a
working Dutch administration** — every operator hits this within
their first month.

This change is one of ten T3 capability splits per ADR-032 spec-
sizing (the original `add-shillinq-bookkeeping-operations` change
exceeded the 20-task cap and was split into siblings).

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire BTW-filing surface as **declarative metadata**
  per ADR-031 — schemas + `x-openregister-lifecycle` rules + manifest
  entries + `ScheduledWorkflow` declarations. No new PHP service.
- Consume every existing OR abstraction (lifecycle, audit, RBAC,
  approval-workflow, aggregations, scheduled workflow) per ADR-022.
- Consume OpenConnector for SBR/Digipoort HTTP — never embed an
  HTTP client in shillinq (per ADR-019).
- Make the spec **bookkeeper- and tax-officer-readable** — a Dutch
  tax officer should be able to confirm the rubrieken mapping and
  the aangifte lifecycle without code-diving.

## Non-Goals

- No bespoke PHP `VatFilingService` / `BtwAangifteService`.
- No XBRL / Nederlandse Taxonomie generation — T4's job. T3 ships
  the submission *trigger* + per-aangifte payload shape only.
- No app-local SBR HTTP client. OpenConnector owns the surface.
- No bespoke Vue beyond manifest-driven `CnIndexPage`/`CnDetailPage`.

## Decisions

### D1 — Declarative-first, per ADR-031

| Behaviour | Declarative form |
|---|---|
| `VatReturn` lifecycle (`draft → submitted → accepted → corrected`) | `x-openregister-lifecycle` on `VatReturn` |
| `VatCorrection` (suppletie) lifecycle | `x-openregister-lifecycle` on `VatCorrection` |
| Rubrieken aggregation (sum-by-rate from GL) | `x-openregister-aggregations` over `GLLine` with rate projection |
| Approval gate on `draft → submitted` | `x-openregister-lifecycle.requires.approval-workflow` |
| SBR/Digipoort submission | OR `ScheduledWorkflow` + n8n adapter consuming `digipoort-sbr` |
| Aangifte-PDF storage | docudesk attachment URI on `VatReturn.attachmentUri` |
| Tariff catalogue | seed (`btw-tariffs-2026.json`) — operator can add, not override canonical rates |

**Alternative considered**: Author `lib/Service/VatFilingService.php`
with ~300 LOC of state-machine and aggregation orchestration.
Rejected per ADR-031 — exactly the decidesk MotionService anti-pattern.

### D2 — `VatTariff` as a register, not a hard enum

BTW tariffs evolve (the 9% reduced rate was 6% before 2019; vrije
sectoren shift over time). A register lets the operator add new
rates (sector-specific zero rates, verleggingsregeling variants)
without forking shillinq; the seeded canonical rates remain
authoritative for the Belastingdienst-mandated set.

### D3 — SBR submission lives in OpenConnector, not shillinq

Per ADR-019 (integration registry) + ADR-022 (consume existing
abstractions), the SBR/Digipoort surface is an OpenConnector
source. shillinq declares an `x-openregister-lifecycle` action on
`VatReturn.submit` that invokes an OR `ScheduledWorkflow`
referencing `digipoort-sbr`. shillinq never authors a PHP HTTP
client; the PKI certificate is operator-managed inside the
OpenConnector source config.

**Alternative considered**: Author a per-app `DigipoortClient.php`
with SOAP and PKI handling. Rejected — exactly the pattern ADR-019
was built to retire.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| `VatReturn` lifecycle | `x-openregister-lifecycle` (ADR-031) | Declared on the schema; no app-local state machine |
| Approval gate on submit | OR approval-workflow (ADR-022) | Consumed via `requires` precondition |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically — every submission/correction is audited |
| Rubrieken aggregation | `x-openregister-aggregations` (ADR-031) | Sum-by-rate projection over T1 `GLLine` |
| RBAC (vat-administrator) | OR authorization (ADR-022) | Per-schema role declaration |
| Scheduled SBR submission | OR `ScheduledWorkflow` + n8n adapter (ADR-031) | Cron-driven workflow invokes OpenConnector source |
| HTTP to Digipoort | OpenConnector `digipoort-sbr` source (ADR-019) | Referenced symbolically; source registration is a separate change |
| Aangifte-PDF storage | docudesk attachment URI (ADR-022) | `VatReturn.attachmentUri` references the docudesk object |
| Tariff catalogue | Seed file imported via `ConfigurationService::importFromApp()` | `btw-tariffs-2026.json` with `_meta.source: "Wet OB 1968"` |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | Adds 3 menu entries + index/detail pages, all generic |

**Net new code in implementation**: 4 schema declarations + 3
manifest entries + 1 seed JSON + 1 `ScheduledWorkflow` declaration.
No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Aangifte state machine | Declarative (`x-openregister-lifecycle`) | Textbook fit |
| Rubrieken sum-by-rate | Declarative (`x-openregister-aggregations`) | Standard projection-filter aggregation |
| SBR submission | OR `ScheduledWorkflow` + OpenConnector source | ADR-031 §"Background jobs that orchestrate external systems" |
| Approval gate on submit | Declarative (`requires.approval-workflow`) | Standard precondition |
| Aangifte-PDF generation | Mapping (template) → docudesk attachment | Declarative — PDF is artefact of the mapping engine |

No service class authored in this envelope.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/btw-tariffs-2026.json` | Canonical BTW rates (21%, 9%, 0%, vrij, verlegd) with RGS account hints | ~10 | Wet OB 1968 + Belastingdienst tariefoverzicht |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
`feedback_spdx-in-docblock.md`. `_meta` block with `source` and
`version` fields so future revisions (rate changes) are tracked.

Loaded via `ConfigurationService::importFromApp()` in the repair
step. Operators may add additional rates (sector-specific) but
cannot override the canonical Belastingdienst rates without
escalating to a fork.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| PKI certificate handling | Operator-managed via OpenConnector source config; no PKI material in shillinq's `secrets/` (security-review confirms). |
| BTW rate change mid-year | Versioned seed (`btw-tariffs-2026.json` → `btw-tariffs-2027.json`); coexistence trivial. |
| Suppletie threshold edge case | `VatCorrection` lifecycle decides whether to file standalone or fold into next regular aangifte; operator-configurable per `REQ-VBTW-009`. |
| SBR ack/nack handling | OpenConnector source handles ack/nack and writes back to `VatReturn.state` via OR webhook; no shillinq glue. |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the 4 new
   schemas (additive).
2. `src/manifest.json` is patched with 3 new navigation entries +
   their index/detail pages (additive).
3. `btw-tariffs-2026.json` is shipped and imported via the repair
   step (idempotent — re-runs do not overwrite operator additions).
4. The SBR `ScheduledWorkflow` is registered.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Registers are non-destructive — existing aangiften
remain queryable. Seeded tariffs remain queryable.

## Open Questions

1. **Periodtype seed default** — `REQ-VBTW-002` declares quarterly
   default; large operators (>€7M omzet/jaar) must use monthly per
   Belastingdienst rules. Confirm with bookkeeper persona.
2. **Suppletie threshold** — €1.000 per Belastingdienst; below that
   the operator can correct in next regular aangifte. Confirm during
   spec review.
