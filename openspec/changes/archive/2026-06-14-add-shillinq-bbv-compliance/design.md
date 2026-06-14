# Design — BBV Compliance

## Context

BBV (Besluit Begroting en Verantwoording) is the statutory
posting-rule framework for Dutch decentralised government
bookkeeping. Every gemeente / provincie / waterschap MUST classify
each GL posting against a *taakveld* drawn from BBV bijlage IV. The
mapping from RGS account → taakveld is **per-administration** —
forcing one municipality's choice on another would force a fork.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Declare the BBV-mapping surface as a **register**, not an enum on
  `Account` (per ADR-022).
- Extend T1 `GLTransaction.post` declaratively with a BBV-mapping
  precondition scoped to municipal administration types (per
  ADR-031).
- Ship operator-editable seed defaults — operators override per
  administration via standard OR object operations.
- Make the spec **municipal-controller-readable** — a BBV-trained
  controller reads the model and confirms it matches Commissie BBV
  guidance.

## Non-Goals

- No app-local BBV state-machine service.
- No IV3 XML generation (sibling spec).
- No BCF claim arithmetic (sibling spec).
- No industry-specific BBV variants (roadmap).

## Decisions

### D1 — BBV mapping is a register, not an enum

The mapping from RGS account → taakveld is conceptually *a
relationship*, not a property of the account. Embedding `taakveld`
as a field on `Account` (D-rejected) would (1) force one mapping
per account across all administrations, (2) collide with non-
municipal administrations that don't carry a BBV taakveld, and (3)
prevent per-administration override.

The `BbvAccountMapping` register carries
`(administrationId, accountNumber)` as a unique key, with
`taakveld`, `programmaCode`, `paragraafCode?`, `bcfCompensable`,
`iv3Bucket`, and `autorisatieniveau` as enum-typed values.

### D2 — `BbvTaakveld` register seeded from BBV bijlage IV

The canonical taakveld catalogue is a register (not a hard enum)
because (1) the catalogue evolves at every BBV revision, (2) the
operator-readable Dutch labels need translation handling, and (3)
some operators add internal sub-codes alongside the canonical
catalogue.

Loaded via `ConfigurationService::importFromApp()` from
`bbv-taakvelden-2024.json` with `_meta.bbvVersion` tag.

### D3 — T1 `GLTransaction.post` precondition for municipal admins

Per `REQ-BBV-003`, posting a GL transaction for a `gemeente`/
`provincie`/`waterschap` administration MUST fail if any line's
`accountNumber` lacks a `BbvAccountMapping` row for that
administration. Non-municipal administrations bypass the check.

This is declared via `x-openregister-lifecycle.requires` referencing
the cross-schema FK presence — standard OR shape. Forward-only by
`postingDate ≥ install date` to avoid rejecting historic
unmapped postings.

**Alternative considered**: Author a `BbvPostingService::validate()`.
Rejected per ADR-031.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Per-administration mapping store | `x-openregister-mappings` + a `BbvAccountMapping` register (ADR-022) | Declared as a register; per-administration uniqueness via OR's declarative constraint |
| Taakveld catalogue | Seed file imported via `ConfigurationService::importFromApp()` | `bbv-taakvelden-2024.json` with `_meta.bbvVersion` |
| Audit trail on every override | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| Post-precondition gate | `x-openregister-lifecycle.requires` on T1 `GLTransaction.post` (ADR-031) | Extends T1 lifecycle, scope filtered to municipal admin types |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | Adds 1 menu entry under `Overheid`; visibility predicate on administration type |
| RBAC (bbv-controller) | OR authorization (ADR-022) | Per-schema role declaration |

**Net new code in implementation**: 2 schema declarations + 1
manifest entry + 2 seed JSONs + 1 lifecycle extension. No new PHP
service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| BBV-mapping enforcement on post | Declarative (`requires` precondition) | Cross-schema FK presence — standard shape |
| Taakveld catalogue lookup | Declarative (register) | Seed + operator-editable |
| Per-administration override | Declarative (register row per admin) | Standard OR pattern |
| Audit on override | OR audit-trail-immutable | ADR-022 |

No service class authored.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/bbv-taakvelden-2024.json` | Complete BBV bijlage IV taakveld catalogue | ~50 | Besluit BBV bijlage IV |
| `lib/Settings/seeds/rgs-to-bbv-mapping.json` | Default RGS 3.5 account → BBV taakveld mapping with bcfCompensable flag and iv3Bucket | ~150 | Commissie BBV handreiking + IV3 specificaties |

Both files carry SPDX header + `_meta.bbvVersion: "2024"` +
`_meta.source: "Commissie BBV handreiking"`. Loaded via the repair
step for new `gemeente`/`provincie`/`waterschap` administrations
only; non-municipal admins skip.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Pre-existing GL postings without mapping | Precondition forward-only by `postingDate`; back-fill is a separate operator workflow |
| BBV revision mid-implementation | Versioned seed; coexistence trivial; `_meta.bbvVersion` per row |
| Operator override drift across municipalities | Per-admin override is the design; audit-trail records every change; cross-municipality comparison is a separate reporting concern |

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/shillinq_register.json` adds 2 schemas (additive).
2. T1 `GLTransaction.post` gains the BBV precondition (extensions,
   not breaking).
3. `src/manifest.json` adds 1 navigation entry (additive).
4. The repair step imports the 2 seed files for new municipal
   administrations.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing mappings remain queryable.

## Open Questions

1. **Programma/Paragraaf required?** — `REQ-BBV-002` makes
   `paragraafCode` optional; confirm with municipal-controller
   persona.
2. **Mapping audit retention** — falls under Selectielijst rule
   for financial mapping records; documented in
   `bookkeeping-archiefwet-retention`.
