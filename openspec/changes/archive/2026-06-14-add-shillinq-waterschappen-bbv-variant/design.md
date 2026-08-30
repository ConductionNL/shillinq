# Design — Waterschappen BBV Variant

## Context

T3's `bookkeeping-bbv-compliance` already declares the BBV
programma-indeling shape for gemeenten. Waterschappen use the
**same regulatory framework** with sector-specific differences:
programma structure (by `kostentoedeling` rather than `taakveld`),
three sector-specific belastingen (watersysteem-, zuiverings-,
verontreinigingsheffing), and a sector-specific EMU-saldo exclusion
ruleset (per the EMU-bijlage waterschappen handleiding).

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Variant as a flag overlay, not a forked register (per ADR-031)

`Account` and `BBVProgramma` gain a `bbvVariant: gemeente |
waterschap | provincie` enum field (default `gemeente`). When set to
`waterschap`, records are interpreted under BBVW. The variant flag
is schema metadata — **no parallel `WaterschapAccount` register and
no PHP variant-resolver service**. The alternative (forking three
BBV specs) was rejected per the parent envelope's design D1: 80%
overlap, drift risk, three-times the review surface.

### D2 — `programmaStructure` discriminator on `BBVProgramma`

Waterschappen group postings by `kostentoedeling` (watersysteem-,
zuiverings-, wegenbeheer, muskusratbestrijding, etc.) rather than
the gemeente `taakveld` shape. `BBVProgramma` gains a
`programmaStructure: taakveld | kostentoedeling` discriminator;
aggregations honour the discriminator at rollup time.

### D3 — `WaterschapHeffingPosting` is a thin posting register, NOT a parallel ledger

The three waterschap-specific heffingen (watersysteem,
zuiverings, verontreiniging) are NOT given their own ledger lines —
each `WaterschapHeffingPosting` materialises a balanced
`GLTransaction` per T1 REQ-GL-001 with a `sourceReference` back to
the posting. This preserves the T1 balance invariant and keeps the
ledger as the single source of truth (per ADR-022).

### D4 — `emuExclusionRule` field, not a hard-coded BBVW rule table

Per the EMU-bijlage waterschappen handleiding, certain heffingen
(notably verontreinigingsheffing aanslag-vorming) are excluded from
the waterschap EMU-saldo. The exclusion is expressed as schema
metadata (`emuExclusionRule: included | excluded | partial`) on
the posting, with defaults matching the 2026 BBVW handleiding. The
EMU-saldo aggregation (declared in sibling
`add-shillinq-emu-reporting`) honours the field at rollup time.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| BBV programma-indeling | T3 `bookkeeping-bbv-compliance` | Variant declared as `bbvVariant` overlay + `programmaStructure` discriminator. No fork. |
| GL transaction materialisation | T1 `bookkeeping-general-ledger` REQ-GL-001 | `WaterschapHeffingPosting` materialises a balanced `GLTransaction`; the heffing posting carries no own ledger lines. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically — every state transition writes an audit event. |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | `WaterschapHeffingPosting` declares a posting-state lifecycle. |
| EMU exclusion rules | `bookkeeping-emu-reporting` (sibling) | This spec declares the per-posting `emuExclusionRule` field; the EMU aggregation reads it. |
| Seed data import | `ConfigurationService::importFromApp()` | Repair-step pattern already in use; extended to seed BBVW programmas. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-waterschap`; `type: index` + `type: detail` library renderers. |

**Net new code in implementation cycle**: 1 schema declaration
(`WaterschapHeffingPosting`) + 2 schema overlays (`bbvVariant`,
`programmaStructure`) + 1 manifest entry + 1 seed JSON file. No
new PHP service.

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json` | BBVW programma-indeling per kostentoedeling (2026 release — watersysteembeheer, zuiveringsbeheer, wegenbeheer, muskusratbestrijding, etc.) | ~30 |

The file carries an SPDX header (EUPL-1.2 + Copyright Conduction
B.V.) inside the docblock per `feedback_spdx-in-docblock.md`, and
an `_meta` block (`source: 'BBVW handleiding'`, `year: 2026`) so a
2027 swap is filename-only. Loaded via
`ConfigurationService::importFromApp()` in the repair step.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| BBVW handleiding evolves annually | Seed file version-pinned in filename (`*-2026.json` → `*-2027.json`); spec references regulation, not year-values. |
| EMU exclusion defaults misaligned with BBVW handleiding | Defaults match 2026 BBVW handleiding; operator override permitted per posting; re-validated yearly. |
| Waterschap also runs a GR | `bbvVariant` is non-exclusive with `GRDeelnemer` participation (sibling `add-shillinq-gr-consolidation`); a single administration may carry both. |
