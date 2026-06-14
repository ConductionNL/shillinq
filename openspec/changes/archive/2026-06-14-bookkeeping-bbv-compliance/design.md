# Design — BBV Compliance

## Context

BBV (Besluit Begroting en Verantwoording) is the statutory governance framework for Dutch decentralised government (gemeente, provincie, waterschap) bookkeeping. Every posting, budget, reserve, and invoice must be classified against a *taakveld* (function code) drawn from BBV bijlage IV. The classification must roll up to a *programma* (policy objective), then to a *paragraaf* (mandatory reporting section per art. 9). Additionally, the RGS-decentraal mapping from G/L account → taakveld/economische_categorie → Iv3-bucket is the **linchpin connecting administrative bookkeeping to CBS-mandated Iv3 reporting**.

This is one of the ten T3 capability splits per ADR-032 spec-sizing, focusing on the data model, validations, and reporting infrastructure for BBV-conforme administration. Sibling specs handle Iv3 export (rendering), IV3-rightmatigheid (assurance), and Grondbeleid (real-estate tracking).

## Goals

- Declare the **BBV data model as OpenRegister schemas**, not as enums on `Account` or procedural service classes (per ADR-022, ADR-031).
- Make the spec **gemeente-controller-readable** — a BBV-trained controller reads the model and confirms it matches Commissie BBV guidance (Notitie Reserves, Notitie MVA, Notitie Grondbeleid, Regeling Beleidsindicatoren).
- **Enforce statutory constraints declaratively** — meerjarenraming sluitend-checks, reserve/voorziening mutatie routes, MVA afschrijving start, paragraaf completeness — all via lifecycle rules, not service methods.
- **Provide operator-editable defaults** for RGS-decentraal mapping, taakveld assignment, reserve policies — seed data imported; per-administration override audited.
- **Connect to downstream reporting** (Iv3, SiSa, Rightmatigheid) via declarative aggregations, not ETL or re-extraction.

## Non-Goals

- No app-local BBV state-machine service (ADR-031 preference).
- No IV3 XML/XBRL rendering (sibling `bookkeeping-iv3-reporting` spec).
- No M&O (misbruik & oneigenlijk gebruik) root-cause analysis (sibling `bookkeeping-procurement-rechtmatigheid` feeds findings).
- No sectoral BBV variants — housing corp, healthcare, education remain roadmap (ADR-032 constraint).
- No Grondbeleid tax-position logic — sibling `bookkeeping-grondexploitatie` owns that.

## Decisions

### D1 — BBV data model as hierarchical OpenRegister schemas

The BBV framework is inherently **hierarchical + multi-tenant**:
- **Organisatie** (gemeente/provincie/waterschap) → **Programma** (7-15 policy objectives) → **Taakveld** (0-53 function codes per overheidslaag) → **EconomischeCategorie** (1-8 cost-type maingroups) → **Account** (RGS-decentraal linked) → **JournalEntry** (line-item postings).
- Each **Programma** carries doelstellingen (goals with KPIs) and beleidsindicatoren (39 verplichte set per Regeling 2024).
- Each **Taakveld** carries verplichte_economische_categorieen (allowed cost types for that function).
- Each **Account** must map to at least one Taakveld via **RgsDecentraalRekening** (the D-code) per administration.

Per ADR-022, this is **five separate registers**, not enums, because:
1. Taakveld catalogue evolves every 1-2 years (Iv3 taxonomy changes).
2. RGS-decentraal mapping is per-administration (gemeente-a maps account 4250 to taakveld 5.3, gemeente-b to 5.6 — both valid).
3. Programma structures are per-tenant (some grote gemeenten have 12 programma's, klein gemeenten 5).
4. Beleidsindicatoren are a fixed 39-set per law, but tracked per programma per administration.
5. Audit trail on every override is mandatory (Selectielijst rules); declarative Register provides this automatically.

### D2 — RGS-decentraal mapping scoped to administration + account

The T1 `Account` schema is extended with:
- `rgs_decentraal_rekening` (ref[RgsDecentraalRekening], required for BBV tenants) — the D-code establishing legal position.
- `taakveld` (ref[Taakveld], required for exploitatie accounts) — default assignment; can be overridden per journal-entry.
- `economische_categorie` (ref[EconomischeCategorie], required) — default cost type; can be overridden.
- `bbv_classificatie` (enum, required) — exploitatie | investering | reserve | voorziening | balans-overig (determines route and constraints).

Uniqueness on `(administrationId, accountNumber)` ensures one mapping per account per tenant (but different tenants can map the same account differently).

### D3 — Posting validation via lifecycle precondition, not service class

Per REQ-BBV-002, when a `JournalEntry.post` lifecycle transition fires for a BBV-tenant, the system **declaratively verifies** (via `x-openregister-lifecycle.requires`) that every line item carries a valid `(taakveld, economische_categorie)` pair matching the account's BBV-classificatie and allowed-categories. Non-municipal administrations bypass the check.

**Alternative considered**: Author `BbvPostingValidationService::validate()` in PHP. **Rejected** per ADR-031 (declarative ≫ imperative for repeatable, auditable rules).

### D4 — Meerjarenraming as a separate register with balance constraints

A `MeerjarenBudget` register carries the T+0, T+1, T+2, T+3 forecasts per `(programma, taakveld, economische_categorie)` triplet. Each year has separate `bedrag_baten` and `bedrag_lasten` fields. At publication of a begroting, an `x-openregister-lifecycle.constraint` on `Programma.publish` verifies that for **all four years** (T through T+3), the sum across all taakvelden of `(baten − lasten + mutatie_reserves_saldo) ≥ 0` (structureel en reëel sluitend). If not, publication is blocked; override requires raadsbesluit-reference.

### D5 — Reserves vs. Voorzieningen have distinct mutatie routes

Per BBV art. 44 and Notitie Reserves en Voorzieningen (Commissie BBV 2023):
- **Reserves** (algemeen + bestemming) mutatie via **resultaatbestemming** (taakveld 0.10 "Mutaties Reserves"). Dotatie/onttrekking always flows to taakveld 0.10 regardless of originating programma.
- **Voorzieningen** (4 categories per art. 44a–44d) mutatie via **exploitatie** (the gekoppelde taakveld for the voorziening). Dotatie is a last, vrijval a negatieve last.

The posting validation precondition (D3) enforces this: if account 2310 is tagged `bbv_classificatie=reserve`, reject any posting on this account unless `taakveld=0.10`. If account 2420 is tagged `bbv_classificatie=voorziening` with `taakveld=2.1`, allow posting only on taakveld 2.1.

### D6 — MVA-administratie with componentenmethode and afschrijving start post-ingebruikname

Per Notitie MVA (Commissie BBV juli 2023), the `MaterieleVasteActiva` schema carries:
- `mva_categorie` (economisch-nut | economisch-nut-heffing | maatschappelijk-nut) — determines activation vs. P&L treatment.
- `aanschafwaarde` — gross cost, minus subsidies van derden (direct reduction, not as bate).
- `ingebruikname_datum` — depreciation starts **the month following** ingebruikname.
- `afschrijvingsmethode` (lineair | annuitair) and `afschrijvingstermijn_jaar`.
- `componenten_methode` (boolean) — allows splitting a samengesteld actief (e.g. schoolgebouw: dak 40yr, installaties 20yr, casco 60yr) into components with separate depreciation schedules.

A lifecycle rule on `MaterieleVasteActiva.post` verifies: if `mva_categorie = maatschappelijk-nut` and `aanschafwaarde > activeringsgrens`, MUST NOT allow direct P&L booking; only activation is allowed.

### D7 — Subsidie-administratie linked to SiSa-bijlage schema

The `Subsidie` register carries:
- `subsidie_soort` (verstrekt-incidenteel | verstrekt-structureel | ontvangen-rijk | ontvangen-provincie | ontvangen-eu).
- `sisa_indicator` (e.g. "H8") — optional; if filled, links to the SiSa-bijlage 2024 (ca. 50 schemes). If a regeling is SiSa-plichtig but `sisa_indicator` is empty, a warning fires at posting time.
- FK to `(economische_categorie, taakveld)` to place the subsidy in the BBV hierarchy.

When SiSa-export runs (separate spec), it aggregates `Subsidie` records by `sisa_indicator`, groups by `bedrag_vastgesteld`, extracts KPIs (FTE counts, unit costs per Regeling), and validates row completeness against the BZK SiSa-bijlage template.

### D8 — Paragrafen as template-driven editable documents

The 7 mandatory paragrafen (art. 9 BBV) are declared as a **separate schema** (`Paragraaf`) with:
- `type` (enum: Lokale_heffingen | Weerstandsvermogen | Onderhoud | Financiering | Bedrijfsvoering | Verbonden_partijen | Grondbeleid).
- Template-driven editor providing verplichte velden per BBV guidelines (e.g. Weerstandsvermogen requires: incidenteel + structureel weerstandscapaciteit, risicobedrag, ratio berekend, NAR-klasse).
- Auto-populated fields from administratie (e.g. weerstandsratio = beschikbaar / benodigd, computed from `Reserve` + tax capacity).
- Free-text toelichtingen (explanatory notes) for non-quantitative sections (e.g. Grondbeleid narration).

At jaarrekening publication, a lifecycle constraint verifies all 7 paragrafen are non-empty and signed by bestuurder/burgemeester.

### D9 — Vergelijkende periode recovery via stelselwijziging tracking

The `Programma` and `MeerjarenBudget` registers both carry a `stelselwijziging` (boolean) flag. When a taakveld catalogue change applies (e.g. taakveld 6.72 splits into 6.72a + 6.72b starting 2026), prior-year figures (2024, 2025) are **recomputed** by the admin and marked with `stelselwijziging=true`. The jaarrekening report then shows these recomputed figures with a visual indicator (italicized, footnoted) per BBV-art. 8 guidance.

### D10 — Rightmatigheidsverantwoording per 2023 with tolerance-based reporting

Per Kadernota Rechtmatigheid 2024 and BBV-herziening 2023, the governance reports its own **begrotings-** (budget overrun), **voorwaarden-** (regulatory compliance), and **M&O-** (misuse & inappropriate use) rightmatigheid aspects. Each posting is tagged with a `rightmatigheid_status` (compliant | afwijking_within_tolerance | afwijking_outside_tolerance). At jaarrekening close:
- Afwijkingen are aggregated per taakveld.
- Raad-configured `rapportagegrens` (usually 1% of total lasten) and `goedkeuringstolerantie` (usually 3%) determine whether afwijkingen are reported in the jaarrekening or covered under management discretion.
- `rightmatigheidsverantwoording.concept` is auto-drafted; bestuurder edits and signs.

Sibling `bookkeeping-procurement-rechtmatigheid` feeds M&O findings; this spec consumes them.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Hierarchical taakveld catalogue | BBV bijlage IV (official Regeling) + Iv3 informatievoorschrift | Seed file bbv-taakvelden-overheidslaag.json imported; `_meta.iv3Version` tag; version-pinned per registration |
| RGS-decentraal mapping | RGS-decentraal publication (SBR/Logius, annual) | Seed rgs-decentraal-YYYY.json imported annually via openconnector; version stored in Administration config |
| Economische categorieën | Iv3 informatievoorschrift (150 codes across 8 maingroups) | Seed economische-categorien-YYYY.json; FK relation to Taakveld (verplichte set per function) |
| Beleidsindicatoren | Regeling Beleidsindicatoren (39 fixed indicators, BZK-published) | Seed beleidsindicatoren-YYYY.json; linked to Programma (each programma carries subset of 39) |
| Audit trail on every mapping override | OR audit-trail-immutable (ADR-022) | Consumed automatically; every RgsDecentraalRekening update → audit entry |
| Posting precondition gate | `x-openregister-lifecycle.requires` on T1 JournalEntry.post (ADR-031) | Extends T1 lifecycle; scope filtered to BBV-tenants (administration type ∈ {gemeente, provincie, waterschap}) |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | Adds ~8 menu entries under `Financieel > BBV-compliance` (programma-planning, meerjarenraming, paragrafen, reserves/voorzieningen, MVA-register, rightmatigheid-concept, Iv3-export, SiSa-bijlage) |
| RBAC (bbv-controller, accountant-bbv) | OR authorization (ADR-022) | Per-schema role declaration; gemeente-controller can create/edit Programma/Taakveld/Reserve; accountant-bbv read-only on rightmatigheid sections |
| Aggregations (baten per taakveld, lasten per programma, etc.) | OR declarative aggregations (ADR-031) | `MeerjarenBudget` and reporting views use OR's aggregation rules; no custom SQL |

**Net new code in implementation**: 9 schema declarations + 1 Account extension + 1 JournalEntry lifecycle extension + ~8 manifest entries + 4 seed JSON files + 1 repair step. No new PHP service class.

## Declarative-vs-Imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| BBV-mapping enforcement on post | Declarative (lifecycle.requires precondition) | Cross-schema FK presence + account type discriminator — standard shape; testable without test harness |
| Taakveld catalogue lookup | Declarative (register) | Seed + operator-editable; evolves with Iv3 spec; audit-trailed |
| Per-admin mapping override | Declarative (register row per admin) | Standard OR pattern; uniqueness constraint; audit automatic |
| Meerjarenraming sluitend-check | Declarative (lifecycle.constraint on Programma.publish) | Arithmetic rule, no branching logic; computed once at publication |
| Reserve/voorziening mutatie route | Declarative (account bbv_classificatie + taakveld preset) | Enforced at posting-validation gate; routing determined by account tag, not decision logic |
| MVA afschrijving calculation | Declarative (depreciation-schedule as register rows) | Linear/annuitair formulas are deterministic; monthly-afsluiting picks computed value from schedule |
| Paragraaf completeness | Declarative (lifecycle.constraint on jaarrekening.publish) | Checks all 7 paragrafen present + non-empty; field-list per BBV-art. 9 |
| Rightmatigheid aggregation & tolerance check | Declarative (lifecycle.constraint + tolerance config on Administration) | Tolerance thresholds are per-raad configuration; aggregation is arithmetic |

**Service classes authored**: ZERO. All rules are registered as schema metadata.

## Seed Data

| File | Purpose | Approximate rows | Citation | Update frequency |
|---|---|---|---|---|
| `bbv-taakvelden-gemeente-2025.json` | Complete BBV taakveld catalogue for gemeenten (53 functions) | 53 | Regeling Vaststelling Taakvelden (BZK/Iv3 informatievoorschrift 2025) | Yearly (Jan) |
| `bbv-taakvelden-provincie-2025.json` | Taakveld catalogue voor provincies (14 functions, different set) | 14 | Provinciewet art. 190 + Iv3-informatievoorschrift provincies | Yearly (Jan) |
| `bbv-taakvelden-waterschap-2025.json` | Taakveld catalogue voor waterschappen (10-12 functions) | 12 | Waterschapswet art. 99 + Iv3-informatievoorschrift waterschappen | Yearly (Jan) |
| `rgs-decentraal-2025.json` | RGS-decentraal D-codes (account → taakveld default mapping), 150-200 main-group mappings | 200 | SBR/Logius RGS-decentraal publicatie 2025 versie 1.0 | Yearly |
| `economische-categorien-2025.json` | Iv3 economische categorieën (cost types 1-8 maingroups, ~150 total codes) | 150 | Iv3-informatievoorschrift 2025 bijlage A | Yearly (Jan) |
| `beleidsindicatoren-bbv-2025.json` | 39 fixed beleidsindicatoren (Regeling 2024 version) per BBV art. 25 lid 2 | 39 | Regeling Vaststelling Beleidsindicatoren BZK 2024 | Yearly (if any change) |

All seed files carry:
- SPDX header: `# SPDX-License-Identifier: CC0-1.0` (public data).
- `_meta.source`: "Commissie BBV" or "SBR/Logius" or "BZK".
- `_meta.iv3Version`: "2025-1.0" (pinned to Iv3 release).
- `_meta.effectiveDate`: "2025-01-01" (when this catalogue version activates).

Loaded via the repair step for new BBV-tenant registrations (gemeente/provincie/waterschap) only; non-municipal admins skip.

## Risks / Trade-offs

| Risk | Severity | Mitigation |
|---|---|---|
| Pre-existing GL postings without BBV mapping | Low | Precondition forward-only by `postingDate ≥ install date`. Back-fill is separate operator workflow (audit-supported). |
| BBV revision mid-implementation (e.g. taakveld split 2025 → 2026) | Medium | Versioned seed files; `_meta.iv3Version` per row; coexistence trivial. Gemeente switches seed at fiscal-year boundary. |
| Operator override drift across municipalities (mapping divergence) | Low | Per-admin override is the design; audit-trail records every change; cross-municipality comparison is separate reporting concern (future capability). |
| Rightmatigheid tolerance configuration drift | Low | Per-raad configuration stored on Administration; audit-trailed; default 1%/3% per Kadernota. Annual review at budget-cycle start. |
| Paragraaf completeness checks blocking jaarrekening publication | Medium | Paragraaf templates provide checklist UI; filling-in is operator responsibility. Bestuurder review gate before publication. |
| Iv3-taxonomy mid-year changes by CBS | Low | XBRL instance generated at export-time; schema version stored in metadata. CBS taxonomy versioning per schedule (normally Jan only). |
| SiSa-bijlage scheme changes mid-year (rare) | Low | SiSa-bijlage versioning per BZK publication; imports via openconnector catch schema version. Gemeente switches at scheduled SiSa-export window. |

## Migration Plan

Spec-only change. When implementation lands:

1. **Data model** (`lib/Settings/shillinq_register.json`) — adds 9 schemas (additive; existing records unaffected).
2. **T1 Account extension** — adds 4 fields (nullable for non-BBV tenants; required for BBV-tenants via constraint).
3. **T1 JournalEntry.post lifecycle** — extends precondition (additive; non-BBV tenants bypass).
4. **Manifest navigation** — adds ~8 entries under `Financieel > BBV-compliance` (additive; visibility filtered).
5. **Repair step** — imports 4 seed files (bbv-taakvelden, rgs-decentraal, economische-categorien, beleidsindicatoren) for new BBV-tenants only.
6. **Existing BBV-tenants** (upgrades from prior version) — operator must run a backfill workflow to map existing Accounts to RGS-decentraal codes (supported by UI with confidence-scoring on referentienummer match).

**Down-direction** (rollback after implementation): Revert the PR; run repair step in down-direction. Existing `Programma`, `MeerjarenBudget`, `Reserve`, `Voorziening`, `MaterieleVasteActiva` records remain queryable (no cascading deletes).

## Open Questions

1. **Programma/Paragraaf cardinality** — Can a Taakveld belong to multiple Programma's? (Probably yes — e.g., taakveld 6.1 "Samenkracht" spans both Sociaal Domein + Bestuur programma's in large gemeente). Confirm mapping cardinality.

2. **Meerjarenraming forward-fill logic** — Should T+1/T+2/T+3 be auto-propagated from T+0 with inflation adjustment (CPI indexation), or manually entered per gemeente? Confirm preference with strateeg persona.

3. **Stelselwijziging recovery scope** — When taakveld catalogue changes mid-administration (e.g. 6.72 splits into 6.72a + 6.72b), how many prior periods should be recomputed? (2 years? 3? All?). Confirm scope with controller + accountant peer review.

4. **Paragraaf Grondbeleid coupling** — Requires `bookkeeping-grondexploitatie` spec to be defined and implemented first. Gate this dependency in planning.

5. **Rightmatigheid M&O definition** — M&O (misbruik & oneigenlijk gebruik) is vaguely defined in law. Does this spec scope to procurement misuse only, or broader organisational misuse? Confirm boundary with juridisch adviseur + accountant.

6. **CBS Iv3-aanlevering window** — Kwartaal-Iv3 due 1 month post-quarter-end; year-Iv3 due 15 July. Does the system auto-submit via Kredo, or does the operator manually trigger export + send? Confirm workflow with BZK integration team.
