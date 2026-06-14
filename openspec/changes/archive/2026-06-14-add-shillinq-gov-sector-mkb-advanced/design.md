# Design — Gov Sector Variants + MKB / Innovation (T4-specialized)

## Context

T1 (foundation), T2 (sub-ledgers + audit + close + statements),
T3 (operations: RGS, IV3, VAT-BTW, Archiefwet, KOR, BCF, ZZP-tax,
schatkistbankieren, subsidies, consultancy projects), and T4-base
(multi-currency, reconciliation, bank connectors, year-end, fixed
assets, SBR-XBRL, cost-centers + dimensions) have given Shillinq a
generic, compliant bookkeeping engine. **T4-specialized** is the
layer where shillinq becomes a real product for two well-defined
buyer groups: **Dutch government adopters** (waterschappen,
provincies, GR, with audit + statistics + market-vs-government +
SiSa + EMU surfaces) and **MKB + innovation buyers** (Vpb +
innovatiebox + investeringsaftrek + WBSO + R&D subsidies + payroll
bridge).

The change is **spec-only**. Implementation lands later per spec
through `opsx-apply` and the standard Hydra pipeline; this doc
explains *why* the shape is what it is. With 14 specs in flight, the
design rationale is grouped by **theme**, not per-spec, to keep this
doc readable.

## Goals

- Express every T4-specialized capability as **declarative metadata** —
  schema additions, `x-openregister-*` extension blocks, manifest
  entries, seed data — per ADR-031. No new PHP service classes.
- **Consume** every regulatory surface from the abstraction that
  already provides it: audit-trail-immutable (ADR-022) for
  rekenkamer / SiSa, integration registry (ADR-019) for openconnector
  feeds (ADP / Loket / Visma / Nmbrs / CBS / BZK), docudesk for every
  regulatory document output.
- Make each spec **independently buildable** under ADR-032's
  `kind: config` budget — 5–8 REQs each, no PHP code authoring per
  spec (any declarative-engine gap is documented as an OR issue).
- Keep the per-sector adopter footprint **flag-controlled** — a
  gemeente that doesn't run a GR doesn't pay UX tax for the GR
  navigation, etc.

## Non-Goals

- No T5 cross-cutting (intercompany eliminations, group
  consolidation, full Peppol BIS3, international VAT one-stop-shop).
- No bespoke Vue components beyond what
  `@conduction/nextcloud-vue`'s Tier-4 manifest renderer already
  provides. Every spec ships a manifest entry; if a sector-specific
  reporting page genuinely needs a custom view, that decision is
  deferred to the implementing cycle.
- No PHP code authored in this change (spec-only). Specs are
  shape-neutral on whether a declarative-engine gap forces a thin
  PHP guard.
- No retrofit of existing T1–T4-base schemas beyond additive flag
  fields and overlay schemas.

## Decisions (grouped by theme)

### Theme A: NL Gov Sector Variants

#### D1 — BBV-Waterschappen and BBV-Provincies are *variants* on the existing BBV-compliance spec, not separate compliance frameworks

T3's `bookkeeping-bbv-compliance` already declares the BBV programma-
indeling shape for gemeenten. Waterschappen and provincies use the
**same regulatory framework** with sector-specific:

- A different programma / kostentoedeling structure (waterschappen
  cluster postings by `kostentoedeling`; provincies by `kerntaak`).
- A different EMU-saldo computation rule (waterschappen exclude
  certain heffingen from the EMU-saldo per the EMU-bijlage
  waterschappen handleiding).
- A different set of sector-specific belastingen / fondsen
  (watersysteemheffing, zuiveringsheffing, verontreinigingsheffing
  for waterschappen; opcenten MRB, provinciefonds, decentralisatie-
  uitkering for provincies).

Each variant is declared as a **CoA / programma overlay** on top of
the BBV-base register — a seed-data + flag pattern, not a new
register. `Account` gains an optional `bbvVariant: 'gemeente' |
'waterschap' | 'provincie'` field; `BBVProgramma` gains a
`variantStructure` discriminator. Net new schemas: 0 for variants;
1 each for the sector-specific heffing/fonds postings
(`WaterschapHeffingPosting`, `ProvincialeFondsPosting`) that don't
exist on the gemeente template.

**Alternative considered**: Fork three BBV specs (gemeente,
waterschap, provincie) with full schema duplication. Rejected — 80%
overlap, drift risk, three-times the review surface for one
regulatory framework. The variant flag fits cleanly under ADR-031's
"declare the variation as schema metadata" rule.

#### D2 — GR consolidation is per-deelnemer attribution + an eliminations layer

A gemeenschappelijke regeling (GR) is a separate juridical entity with
its own jaarrekening, funded by deelnemers (member gemeenten /
provincies / waterschappen) via a `verdeelsleutel`. The GR's
bookkeeping has two surfaces:

1. The **GR's own jaarrekening** — a complete BBV jaarrekening for
   the GR.
2. **Per-deelnemer doorbelasting** — each deelnemer's share of GR
   costs, computed from a `GRVerdeelsleutel` record, posted into the
   deelnemer's own administration as a doorbelasting.

T4-specialized adds:

- `GRDeelnemer` register — one record per deelnemer (administrationId,
  share percentage, quotum).
- `GRVerdeelsleutel` register — the per-cost-cluster apportionment
  rule (e.g. "60% inwoners-aantal, 40% gewogen oppervlak").
- An `eliminationFlag` field on `GLLine` so inter-GR transactions
  (the GR paying a deelnemer, or vice versa) can be excluded from
  consolidated views.

The eliminations are **declarative** — an `x-openregister-aggregations`
view on `GLLine` with `WHERE eliminationFlag = false` produces the
consolidated trial balance. No PHP elimination service.

**Alternative considered**: A separate `ConsolidatedTrialBalance`
register populated by a service. Rejected — the elimination view is
trivially expressible as an aggregation filter; materialising a
consolidated table introduces a sync problem.

#### D3 — Rekenkamer audit-pack is a **presentation manifest**, not a separate audit system

T2's `bookkeeping-audit-trail` already provides the immutable
audit-trail-immutable surface per ADR-022. Rekenkamer +
accountantscontrole need three things on top:

1. An **export in NIVRA-bestand format** (the standardised audit
   file the Dutch accountancy profession expects).
2. **Steekproef** support — random sampling of postings within a
   period for substantive testing.
3. **Ledenraadpleging-export** — a redacted slice for raadsleden
   review.

All three are **transformations** on the existing audit-trail data.
The audit-pack is a `manifest.json` entry pointing at three
`x-openregister-aggregations` declarations + three docudesk
templates. **No new audit register** — that would duplicate the
audit-trail-immutable surface and violate ADR-022.

#### D4 — Extended CBS-bestanden and EMU reporting are transformation specs over GL aggregations

IV3 (T3) already extracts the BBV programma totals. The extended
CBS-bestanden (Iv3-detail, Kerngegevens jaarstaten, Iv3-OZB, EMU-
bestand, periodieke statistiekleveringen) are **additional
aggregations + transformations** of the same GL data, each producing
a CSV / XML / SBR payload uploaded to CBS via openconnector.

Each spec adds:

- An `x-openregister-aggregations` declaration (the rollup query).
- A `docudesk` template (the output format).
- An `openconnector` source row (the CBS endpoint).

No bespoke transformation service. **EMU reporting** layers on top
by adding an **ESA-2010 classifier** as a CoA flag (`esaClassifier:
'S.1311' | 'S.1313' | …`) — each account is tagged with its ESA
sector, and EMU-saldo is the aggregation summing `(debit - credit)`
grouped by sector and filtered by the EMU inclusion/exclusion rules
declared as calculation metadata.

#### D5 — SiSa reporting is a per-regeling indicator register + an annual rollup

SiSa (Single Information Single Audit) is the BZK reporting framework
for **specifieke uitkeringen** — government grants tied to specific
performance indicators. Each `Subsidie` (T3 register) of subtype
`specifieke uitkering` has zero or more `SisaRegelingIndicator` records
declaring the indicators (e.g. "aantal gerealiseerde woningen",
"aantal deelnemers re-integratietraject"). At jaarrekening, the
SiSa-bijlage is a docudesk-rendered table aggregating indicators per
controleprotocol.

The indicators are **schema-declared per regeling**, seeded from the
annual SiSa-controleprotocol. The bijlage rendering is declarative.
**No SiSa service.**

#### D6 — Markt & Overheid separation = ondernemingsactiviteit flag + integrale-kostprijs aggregation

Wet Markt en Overheid requires gemeenten / provincies / waterschappen
running market activities to:

1. Identify the **ondernemingsactiviteit** as a distinct cluster.
2. Compute the **integrale kostprijs** including a fair share of
   overhead.
3. Maintain a **transparantieadministratie** showing the
   ondernemingsactiviteit cannot be cross-subsidised by public funds.

T4-specialized adds:

- An `ondernemingsActiviteit: boolean` flag on `CostCenter` (from
  T4-base `cost-centers-dimensions`).
- A declarative integrale-kostprijs `x-openregister-calculations`
  block per ondernemingsactiviteit cost-center, rolling up overhead
  via a configurable verdeelsleutel.
- A transparantieadministratie view: a manifest navigation entry
  pointing at an aggregation that proves the ondernemingsactiviteit
  is self-funding.

**No new register**; the existing `CostCenter` carries the flag.

### Theme B: Vpb + Innovation

#### D7 — Vpb tagging is account-level + a filtered "Vpb-balans" aggregation

Per Wet modernisering Vpb-plicht (2016), muni
ondernemingsactiviteiten are Vpb-pligtig. The Vpb-balans is the same
GL data filtered to Vpb-pligtig accounts only. T4-specialized adds:

- A `vpbPligtig: boolean` flag on `Account` (default `false`).
- A `VpbBalansLink` overlay schema linking ondernemingsactiviteit
  cost-centers to their Vpb-pligtig accounts (per-ondernemingsactiviteit
  Vpb-balans).
- An `x-openregister-aggregations` declaration producing the Vpb-
  balans per ondernemingsactiviteit.

The Vpb-aangifte voorbereiding output is a docudesk template; the
actual aangifte indiening rides openconnector to the Belastingdienst
SBR endpoint (which T3's `bookkeeping-sbr-xbrl-reporting` already
wires).

#### D8 — Innovatiebox: IP-asset register + winsttoerekening per pre-defined sleutel

Wet Vpb art. 12b's innovatiebox lets companies tax the
IP-attributable winst at 5%. T4-specialized adds:

- `IPAssetValuation` register — one record per IP-asset (S&O-
  certificaat, octrooi, kwekersrecht), with `valuation`,
  `valuationMethod` (forfaitair / afpelmethode), `applicableTariff:
  5%`.
- `WinstToerekening` register — per-period mapping from omzet /
  winst to one or more IP-assets via a configurable verdeelsleutel.
- A docudesk template producing the Vpb-aangifte innovatiebox-
  sectie.

The 5%-tariefadministratie is the aggregation summing winst-
toerekening per IP-asset per period. Materialisation is the OR
calculation engine.

#### D9 — Investeringsaftrek: per-investering classifier on fixed assets + RvO aanvraagdossier

KIA (kleinschaligheid), EIA (energie), MIA (milieu), Vamil (vrije
afschrijving) are computed from the existing `FixedAsset` register
(T4-base `fixed-assets-depreciation`). T4-specialized adds:

- An `InvesteringClassifier` overlay — `aftrekType: 'kia' | 'eia' |
  'mia' | 'vamil'`, `bedrijfsmiddelCode`, `aanvraagDate`.
- A seed file with **annual schalen** (`investeringsaftrek-
  tarieven-2026.json`) — versioned in filename for yearly updates.
- A docudesk template producing the RvO aanvraagdossier.

Computation (e.g. KIA's drempel / oploop / maximum) is a declarative
`x-openregister-calculations` block on `FixedAsset` reading the
seeded tarieven.

#### D10 — WBSO/S&O administratie: project-medewerker uren register + declarative afdracht calc

S&O (speur & ontwikkelingswerk) requires per-project per-medewerker
uren administratie + a quarterly mededeling to RvO and an annual
jaarrapport. Afdrachtvermindering loonheffing is computed from the
mededeling. T4-specialized adds:

- `SoProject` register — one record per S&O-project (project-naam,
  RvO-projectnummer, S&O-certificaat, looptijd).
- `SoUrenStaat` register — per-medewerker per-week per-project hour
  staff, lifecycle `draft → goedgekeurd → afgesloten`.
- A docudesk template per output (mededeling, kwartaalrapportage,
  jaarrapport).

The afdracht computation is an `x-openregister-calculations` block
reading the uren + the medewerker's S&O-uurloon (from the
detachering / payroll bridge or static seed).

### Theme C: R&D Subsidies + Payroll Bridge

#### D11 — R&D subsidies (MIT / SBIR / EU Horizon / EFRO) are variants on the existing subsidie register

T3's `bookkeeping-subsidie-verantwoording` already provides the
generic subsidie register with budget bewaking, voortgangsrapportage,
kostendossiers. Each regeling (MIT, SBIR, EU Horizon, EFRO/REACT-EU)
has its own **kostencategorieën** (e.g. EU Horizon's
"personnel costs", "subcontracting", "other direct costs", "indirect
costs") and audit-trail eisen. T4-specialized declares each as a
**variant overlay** on the subsidie register:

- A `subsidieRegeling: 'mit' | 'sbir' | 'eu-horizon' | 'efro' |
  'react-eu'` field.
- A per-regeling kostencategorieën enum constrained by the
  regeling.
- A per-regeling audit-pack template in docudesk (each regeling's
  auditor expects a specific layout).

No new register; the existing subsidie register absorbs the
extension.

#### D12 — Salarisbureau import via openconnector; IB47 + Wet DBA via docudesk

Salarisbureau imports (ADP, Loket, Visma, Nmbrs) ride existing
openconnector OAuth2 + REST patterns. T4-specialized declares:

- `openconnector` source rows for each salarisbureau (the API
  endpoint, the OAuth config).
- A mapping into the `AccountsPayable` and `JournalEntry` registers
  (the salaris-feed materialises a journal entry of subtype
  `loonkosten` per medewerker per period).

For **ZZP-detachering + Wet DBA**:

- An `OpdrachtgeversVerklaring` register — one record per
  opdracht / ZZP-er, with `verklaringStatus` (per Wet DBA / DBA-
  uitleg), `looptijd`, `werkzaamheden`.
- An `IB47Record` register — annual freelance-aangifte payload per
  opdracht (T3 already provides the underlying betalingen).
- Docudesk templates for IB47-formulier (Belastingdienst format)
  and de standaard opdrachtgeversverklaring.

No new payroll service. **No app-local API client** — openconnector
owns every external HTTP call per ADR-019.

## Reuse Analysis

| Capability needed | What already exists | T4-specialized reuse strategy |
|---|---|---|
| BBV programma-indeling | T3 `bbv-compliance` | Variants declared as overlay seed data + `bbvVariant` flag on `Account` / `BBVProgramma`. No fork. |
| EMU computation | None (this change ships it) | `EsaClassifier` overlay on `Account` + aggregation declaration. EMU-saldo = sum grouped by sector with inclusion rules in calculation metadata. |
| Audit-trail surface | T2 `bookkeeping-audit-trail` (ADR-022) | Rekenkamer audit-pack is a presentation manifest + 3 transformation aggregations + 3 docudesk templates. No new audit register. |
| IV3 base aggregations | T3 `iv3-reporting` | Extended CBS-bestanden add additional aggregation declarations + docudesk templates on the same data. |
| Subsidie register | T3 `subsidie-verantwoording` | SiSa + R&D MKB are variant overlays via `subsidieRegeling` enum. Per-regeling indicator register attaches by FK. |
| Cost-center / dimensie | T4-base `cost-centers-dimensions` | Markt & Overheid adds an `ondernemingsActiviteit` flag + integrale-kostprijs calculation block. Innovatiebox adds `WinstToerekening` linking cost-centers to IP-assets. |
| Fixed-asset register | T4-base `fixed-assets-depreciation` | Investeringsaftrek adds an `InvesteringClassifier` overlay + annual tarieven seed + RvO aanvraagdossier template. |
| Approval workflow | OR approval-workflow (ADR-022) | SiSa-bijlage + Vpb-aangifte + WBSO mededeling consume the approval-workflow gate. |
| Scheduled background jobs | OR scheduled-workflow + n8n adapter (per ADR-031 §"Background jobs") | WBSO kwartaalrapportage + CBS periodieke leveringen + EMU quarterly run materialise on schedule. |
| External feeds (salarisbureau, CBS, BZK) | openconnector (ADR-019) | Declared as source rows in seed data; no app-local HTTP client. |
| Document generation (NIVRA, SiSa, IB47, RvO outputs) | docudesk | Templates referenced by URI; rendering, signing, archival handled docudesk-side. |
| RBAC on personnel data | OR authorization | Per-schema role declarations restricting read on `SoUrenStaat`, `IB47Record`, `OpdrachtgeversVerklaring` to `bookkeeper` / `payroll-officer` / `auditor`. |
| Retention class for personnel data | T3 `archiefwet-retention` | Personnel records inherit the existing retention class (7 jaar belastingadministratie); no new class needed. |
| SBR-XBRL transmission | T4-base `sbr-xbrl-reporting` | Vpb-aangifte SBR submission rides the existing SBR transmission path. |

**Net new code in T4-specialized implementation** (across all 14
specs): ~10 schema additions, ~15 manifest navigation entries (flag-
controlled), ~5 seed files, ~7 docudesk template references, ~6
openconnector source rows. **Zero PHP services**. Possibly 1–2 thin
PHP guards if `x-openregister-aggregations` cannot express a multi-
sector EMU filter (ADR-031 exception path, single method each,
documented in implementing cycle).

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, every T4-specialized behaviour was classified:

| Behaviour | Decision | Why |
|---|---|---|
| BBV variant overlay (waterschappen / provincies) | Declarative — seed data + flag field | Variation expressible as schema metadata |
| GR consolidation + eliminations | Declarative — aggregation with `WHERE eliminationFlag = false` | Pure filter |
| Rekenkamer audit-pack | Declarative — aggregations + docudesk templates | Read-only views over existing audit-trail |
| Extended CBS-bestanden | Declarative — aggregation + docudesk + openconnector source | No transformation logic outside the aggregation engine |
| EMU computation | Declarative if engine supports multi-sector filter with classifier; otherwise thin PHP `EmuCalculator` per ADR-031 exception | Resolution lives in opsx-ff discovery |
| SiSa-bijlage | Declarative — per-regeling indicator register + rollup | Pure aggregation per controleprotocol |
| Markt & Overheid integrale kostprijs | Declarative — `x-openregister-calculations` per cost-center | Pure calculation |
| Vpb tagging + Vpb-balans | Declarative — flag + filtered aggregation | Pure filter |
| Innovatiebox 5%-tariefadministratie | Declarative — winsttoerekening register + calculation | Pure calculation |
| Investeringsaftrek (KIA/EIA/MIA/Vamil) | Declarative — classifier overlay + calculation reading seeded tarieven | Pure calculation |
| WBSO afdrachtvermindering | Declarative — uren register + calculation | Pure calculation |
| R&D subsidies | Declarative — variant on existing subsidie register + per-regeling kostencategorieën | Schema extension |
| Salarisbureau import | Declarative — openconnector source + mapping into JournalEntry | Existing ADR-019 path |
| IB47 + opdrachtgeversverklaring | Declarative — registers + docudesk templates | No service logic |

No service class authored in this envelope. If EMU multi-sector
filter requires a PHP calculator, it is single-method (~20 LOC) and
explicitly cited as an ADR-031 exception in the implementing cycle's
design doc.

## Seed Data

T4-specialized ships five seed files under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `bbv-waterschappen-programmas-2026.json` | BBVW programma-indeling per kostentoedeling (2026 release) | ~30 |
| `bbv-provincies-kerntaken-2026.json` | Provinciale BBV kerntaken-indeling (2026 release — mobiliteit / economie / ruimte / cultuur / etc.) | ~15 |
| `esa-2010-classifier.json` | ESA-2010 sector classifier (S.1311, S.1312, S.1313, S.1314 + sub-codes) | ~25 |
| `sisa-controleprotocol-2026.json` | SiSa-controleprotocol indicatoren per regeling (2026 BZK release) | ~200 |
| `investeringsaftrek-tarieven-2026.json` | KIA drempels + tarieven, EIA / MIA percentages, Vamil bedrijfsmiddel-codes (2026 RvO release) | ~100 |

Each file:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md` — inside the docblock, not as line
  comments.
- `_meta` block (`{ "_meta": { "source": "<reg-naam>", "year": 2026,
  "imported": "<iso-ts>" } }`) so a 2027 swap is filename-only.
- Loaded via `ConfigurationService::importFromApp()` in the repair
  step.

No seed data for the registers that accumulate operationally
(`GRDeelnemer`, `IPAssetValuation`, `SoProject`, `IB47Record`, etc.).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| 14 specs in one envelope is administratively heavy | Per-spec opsx-apply cycles after envelope merge; Hydra `depends_on` enforces topological order; each spec is independently small (5–8 REQs) per ADR-032 budgeting. |
| Annual regulatory churn (BBV, IV3, SiSa, EMU, WBSO, investeringsaftrek) | Seed files version-pinned in filename (`*-2026.json` → `*-2027.json`); specs reference regulations, not year-specific values. |
| Cross-schema aggregation gaps (EMU multi-sector filter, SiSa per-controleprotocol rollup, Vpb-balans filter, integrale-kostprijs verdeelsleutel) | Each gap surfaces in opsx-ff per spec; ADR-031 exception path documented as fallback. Specs are shape-neutral. |
| Per-adopter UX overload (a small gemeente seeing 14 navigation entries) | All 14 manifest entries are `featureFlags`-controlled; default enables only the BBV-base + IV3 set. Each sector is opt-in. |
| Privacy footprint on personnel + S&O data | RBAC + audit-trail-immutable + Archiefwet retention all reused; no app-local privacy logic. |
| Sector overlap (waterschap that also runs a GR) | Variants are flags, not exclusive — a single administration can carry `bbvVariant: waterschap` and participate in a GR via `GRDeelnemer`. No exclusive constraint. |
| Future tier (T5 cross-cutting) needs fields T4-specialized didn't anticipate | All schema changes are additive per OR's schema versioning. Risk accepted. |

## Migration Plan

Spec-only — no runtime migration in this change. Per-spec
implementation cycle pattern (repeats 14 times):

1. `lib/Settings/shillinq_register.json` is patched additively with
   the spec's schema fields / overlay registers.
2. `lib/Settings/seeds/` gains the spec's seed file (if any).
3. `src/manifest.json` gains the spec's `featureFlags`-controlled
   navigation entry.
4. `docudesk` template references and `openconnector` source rows
   are added as config.
5. ADR-000 may gain a one-paragraph annotation for newly named
   registers; reconciliation note added in the implementing cycle.

Down-direction: additive registers are non-destructive; reverting
the implementing PR leaves stranded but queryable records.

## Open Questions

1. **EMU computation scope** — quarterly + annual only, or
   also rolling intra-period view? `REQ-EMU-003` proposes
   quarterly + annual only. Confirm with BBV reviewer persona.
2. **GR consolidation cardinality** — one shillinq administration
   per GR (with deelnemers as FK records) vs one administration per
   deelnemer with cross-admin rollup? `REQ-GRC-002` proposes the
   first; revisit in opsx-ff.
3. **WBSO afdrachtvermindering computation locus** — shillinq
   computes projected afdracht; RvO mededeling is authoritative for
   loonaangifte. Confirm with WBSO consultant persona.
4. **IB47 reporting cadence** — annual batch with monthly dry-run
   (`REQ-DPA-005`)? Finalize in implementation cycle.
5. **Innovatiebox forfaitaire vs afpelmethode** — both supported
   per `REQ-IBA-002`; default ondersteuning of forfaitair (cleaner
   audit trail) confirmed with the bookkeeper persona before
   implementing cycle.
