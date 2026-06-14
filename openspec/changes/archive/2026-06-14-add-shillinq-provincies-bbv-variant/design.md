# Design — Provincies BBV Variant

**status: pr-created**

## Context

T3's `bookkeeping-bbv-compliance` declares the BBV programma-
indeling shape for gemeenten. The sibling
`add-shillinq-waterschappen-bbv-variant` introduces the
`bbvVariant` enum overlay + `programmaStructure` discriminator on
which this change builds. Provincies use kerntaken (the seven
canonical taken: ruimte, mobiliteit, water, milieu, cultuur,
economie, bestuur) and have sector-specific posting types
(provinciefonds, algemene uitkering, decentralisatie-uitkering,
integratie-uitkering) plus opcenten op de motorrijtuigenbelasting.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Extend the existing `bbvVariant` enum rather than introducing a parallel variant register

The waterschap sibling declares the `bbvVariant` enum with values
`gemeente | waterschap | provincie`. This change simply requires
`provincie` to be honoured throughout the BBV aggregations. No
parallel `ProvincieAccount` register and no PHP variant-resolver
service per ADR-031.

### D2 — `programmaStructure: 'kerntaak'` discriminator value

The `BBVProgramma.programmaStructure` discriminator (declared by
the waterschap sibling) gains a third value, `'kerntaak'`.
Aggregations rolled up to the programma level honour the
discriminator — provincies see kerntaken-rollups, gemeenten see
taakveld-rollups, waterschappen see kostentoedeling-rollups.

### D3 — `ProvincialeFondsPosting` is a thin posting register, NOT a parallel ledger

Each `ProvincialeFondsPosting` materialises a balanced
`GLTransaction` per T1 REQ-GL-001 with a `sourceReference` back to
the posting. The register carries `fondsType`, `uitkeringJaar`,
`uitkeringBedrag`, `uitkeringBeschikking`, and `journalEntryId`
fields; no own ledger lines. This preserves the T1 balance
invariant and keeps the ledger as the single source of truth
per ADR-022.

### D4 — `opcentenTarief` lives on `GLLine`, not on a separate register

Provincies heffen opcenten on the MRB; the per-provincie tarief is
a procentopslag. Modelled as an optional `opcentenTarief: number ≥
0` field on `GLLine` (used only on lines posted to the
MRB-opcenten inkomstenrekening), aggregations can roll up
opcenten-inkomsten per provincie per period. Alternative
(separate `OpcentenPosting` register) was rejected — opcenten are
ordinary revenue postings with a per-line tariefopslag, not a
distinct posting type.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| BBV programma-indeling | T3 `bookkeeping-bbv-compliance` | Variant declared by sibling waterschap spec; this change adds `provincie` value. |
| Variant enum + discriminator | Sibling `add-shillinq-waterschappen-bbv-variant` | This change extends both enums (`bbvVariant: provincie`, `programmaStructure: kerntaak`). |
| GL transaction materialisation | T1 `bookkeeping-general-ledger` REQ-GL-001 | `ProvincialeFondsPosting` materialises balanced `GLTransaction`. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically. |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | Posting-state lifecycle on `ProvincialeFondsPosting`. |
| Seed data import | `ConfigurationService::importFromApp()` | Repair-step seeds the kerntaken. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-provincie`. |

**Net new code in implementation cycle**: 1 schema declaration
(`ProvincialeFondsPosting`) + 2 enum extensions (`bbvVariant`,
`programmaStructure`) + 1 line field (`opcentenTarief`) + 1
manifest entry + 1 seed JSON file. No new PHP service.

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json` | Provinciale BBV kerntaken-indeling 2026 (ruimte / mobiliteit / water / milieu / cultuur / economie / bestuur) with RGS-aligned account sub-trees | ~15 |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) inside docblock
per `feedback_spdx-in-docblock.md`; `_meta` block (`source:
'Provinciale handleiding BBV'`, `year: 2026`); loaded via
`ConfigurationService::importFromApp()` in the repair step.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Provinciale BBV handleiding evolves yearly | Seed file version-pinned; spec references regulation, not values. |
| Opcenten tariefopslag varies per provincie per period | Per-line field rather than seeded enum. |
| Order of envelope merges (waterschap sibling must land first to declare the enum) | Hydra `depends_on` enforces topological order on `bookkeeping-waterschappen-bbv-variant`. |
