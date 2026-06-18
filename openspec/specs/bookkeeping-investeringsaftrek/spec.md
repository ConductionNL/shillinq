---
status: done
---

# Spec: bookkeeping-investeringsaftrek

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (specialized MKB tax compliance)
**Depends on:** `../bookkeeping-fixed-assets-depreciation/spec.md` (FixedAsset base),
`../bookkeeping-vpb-corporate-tax/spec.md` (Vpb-aangifte integration)

## Purpose

This specification defines the requirements for bookkeeping investeringsaftrek in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: investeringsaftrek — not browser-testable


### REQ-INV-001: Asset categorisation at capitalisation

The system SHALL classify every newly capitalised asset against the four
schemes at the moment the asset is created (or modified) in
`InvestmentAsset`. Classification logic:

- **KIA**: eligible if `aanschafwaarde` is between EUR 450 (per-asset
  minimum) and EUR 392.230 (per-asset/per-year combined plafond), the
  asset is NOT excluded under art. 3.45 Wet IB 2001 (excluded:
  woonhuizen, grond, personenauto's behalve elektrisch/zakelijk, effecten,
  dieren, vaartuigen voor representatieve doeleinden, bedrijfsmiddelen
  bestemd voor verhuur aan derden).
- **EIA**: eligible if an EnergielijstCode 2026 matches, AND
  aanschafwaarde per asset ≥ EUR 2.500, AND total EIA-claim across the
  boekjaar does not exceed EUR 151.000.000.
- **MIA**: eligible if a MilieulijstCode 2026 matches, AND aanschafwaarde
  per asset ≥ EUR 2.500.
- **Vamil**: eligible if MilieulijstCode carries `vamilToegestaan: true`,
  AND aanschafwaarde per asset ≥ EUR 2.500. Yearly Vamil-budget plafond
  EUR 25.000.000.

The classification SHALL be presented as a checklist with rationale
("EIA: code 251701 matches; KIA: ja, valt in tier 2 bij huidge
jaartotaal van EUR 65.000") so the boekhouder can override each
classification with a mandatory rationale field.

#### Scenario: EIA eligibility classification succeeds

- **GIVEN** an asset with `aanschafwaarde: 50000`, `energielijstCode: "251701"`
  (Zonnepaneel > 15 kWp), `opdrachtverleningDatum: "2026-03-15"`
- **WHEN** the asset is classified
- **THEN** the system SHALL display "EIA: eligible (code 251701 matches, amount ≥ EUR 2.5k)"

#### Scenario: KIA tier classification shows marginal effect

- **GIVEN** assets with total `kiaJaartotaal: EUR 65.000` and a new asset
  with `aanschafwaarde: EUR 50.000`
- **WHEN** the new asset is classified
- **THEN** the system SHALL display "KIA: tier 2 (28% × EUR 65k = EUR 18.2k total;
  this asset adds EUR 14k × 28% = EUR 3.92k)"

### REQ-INV-002: Energielijst/Milieulijst lookup with version pinning

The system SHALL maintain immutable yearly snapshots of the Energielijst
and Milieulijst (typically loaded from RvO's published JSON / PDF in
januari). When a claim is filed, the code MUST be resolved against the
lijst of the `opdrachtverleningDatum`'s year — NOT today's lijst. This
matters because codes are added, removed, and renumbered annually. The
system SHALL provide a search UI keyed on omschrijving + category, and
surface the most recent 3 years of lijsten for late filings.

#### Scenario: Code resolved against opdrachtverlening year, not filing year

- **GIVEN** a code "251701" on Energielijst 2024 but REMOVED from
  Energielijst 2026
- **WHEN** a user files a claim with `opdrachtverleningDatum: "2024-05-15"`
- **THEN** the system SHALL resolve "251701" against Energielijst 2024 and
  accept it; NOT reject based on Energielijst 2026

### REQ-INV-003: Threshold and minimum checks

Per-asset minimum of EUR 2.500 for EIA/MIA/Vamil SHALL be enforced.
Per-asset minimum of EUR 450 for KIA SHALL be enforced. The yearly KIA
plafond of EUR 392.230 of total investments SHALL trigger a warning at
80% utilisation. EIA and MIA each have absolute aftrek-maxima per boekjaar
(EIA: EUR 151M aftrek, capped on a per-bedrijf basis under EU staatssteun-regels
at EUR 25M; MIA: EUR 50M aftrek). For MKB these are theoretical, but the
system SHALL still validate.

#### Scenario: EUR 2.5k minimum enforced for MIA

- **GIVEN** an asset with `aanschafwaarde: EUR 2.400`, `milieulijstCode: "G3110"`
- **WHEN** the system classifies
- **THEN** MIA eligibility MUST be false; warning MUST display "Amount below EUR 2.5k minimum"

### REQ-INV-004: Cumulation logic and combination matrix

The system SHALL apply the legal cumulation rules. A single asset can never
claim both EIA and MIA (art. 3.42 lid 7 Wet IB 2001 — `samenloop verboden`).
KIA, however, stacks freely with EIA *and* with MIA on a per-asset basis.
Vamil is a depreciation method, not an aftrek, so it combines with MIA on
the same asset (very common) and is the **only** scheme that touches the
depreciation schedule rather than the taxable result directly.

When EIA and MIA both technically apply (rare asset on both lijsten), the
system SHALL compute both and recommend the higher net-present-value option
(taking Vamil into account on the MIA side).

| Scheme combination on **same asset** | Allowed? | Notes |
|---|---|---|
| KIA + EIA | Yes | Both applied; common |
| KIA + MIA | Yes | Both applied; common |
| KIA + Vamil | Yes | Vamil affects timing only |
| EIA + MIA | **No** | Art. 3.42 lid 7 — system MUST refuse |
| EIA + Vamil | **No** | Vamil only on Milieulijst |
| MIA + Vamil | Yes | Default combination |
| KIA + EIA + Vamil | **No** | Vamil never with EIA |
| KIA + MIA + Vamil | Yes | Triple stack allowed |

#### Scenario: EIA + MIA cumulation refused

- **GIVEN** an asset matching both Energielijst (code 251701) and
  Milieulijst (code L4220)
- **WHEN** the boekhouder tries to claim both EIA and MIA
- **THEN** the system SHALL refuse; warning MUST display "Art. 3.42 lid 7:
  EIA and MIA cannot be combined on the same asset. Choose one."

### REQ-INV-005: KIA tier calculation at boekjaar level

KIA is **not** per-asset but per-boekjaar aggregated. The system SHALL
maintain a running `kiaJaartotaal` across all KIA-eligible assets in the
boekjaar and recompute the KIA-aftrek using the 2026 tiered table every
time an asset is added, removed, or revalued. The tier-4 formula is
non-trivial: `aftrek = 19.769 − 7,56% × (jaartotaal − 130.744)`. The
system SHALL show the marginal effect ("Deze investering verhoogt uw KIA
met EUR 1.250") in the asset detail view.

#### Scenario: Marginal KIA effect calculation

- **GIVEN** current `kiaJaartotaal: EUR 65.000` and a new EUR 50k asset
- **WHEN** the system recalculates KIA
- **THEN** old KIA = 28% × EUR 65k = EUR 18.2k; new KIA = 28% × EUR 115k
  = EUR 32.2k; marginal effect = EUR 14k, NOT EUR 50k × 28%

### REQ-INV-006: KIA 2026 tier table

The system SHALL apply the 2026 KIA tiers (art. 3.41 Wet IB 2001,
geïndexeerd):

- Investering ≤ EUR 2.800: geen KIA
- EUR 2.800 < investering ≤ EUR 70.602: **28% × investering**
- EUR 70.602 < investering ≤ EUR 130.744: vast bedrag **EUR 19.769**
- EUR 130.744 < investering ≤ EUR 392.230: **EUR 19.769 − 7,56% × (investering − EUR 130.744)**
- Investering > EUR 392.230: geen KIA

The 28% / 17% / 8% common-knowledge percentages refer to **effective
marginal rates** at the top of each tier; the legal table uses the
absolute formulas above. The system SHALL display both for boekhouder
transparency.

#### Scenario: KIA 2026 tier formula applied for high investment

- **GIVEN** a total `investering` of EUR 150.000 in boekjaar 2026
- **WHEN** the KIA is computed against the 2026 tier table
- **THEN** the system SHALL apply `EUR 19.769 − 7,56% × (150.000 − 130.744)` and display both the absolute formula result and the effective marginal rate

### REQ-INV-007: RvO digital aanvraag generation

For every EIA/MIA/Vamil claim the system SHALL generate the RvO eLoket
aanvraag payload (JSON conforming to RvO's investeringsregelingen API
contract) and provide a one-click submission. The statutory deadline is
**3 maanden na opdrachtverlening** (the date of the binding order, NOT the
invoice or delivery date). The system SHALL:

- Capture `opdrachtverleningDatum` as a mandatory field on every potential
  EIA/MIA/Vamil asset.
- Compute `rvoMeldingDeadline = opdrachtverleningDatum + 3 months` and
  surface this in a deadline-monitoring widget.
- Send a reminder email at deadline minus 14 days and minus 3 days if the
  melding is still `ingediend` (not yet submitted to RvO).
- Block the melding from being marked `definitief` (award received) if the
  deadline has passed (the aftrek is then irrevocably forfeited; the system
  MUST NOT silently proceed).

#### Scenario: RvO deadline tracking

- **GIVEN** an asset with `opdrachtverleningDatum: "2026-03-15"`
- **WHEN** the asset is created
- **THEN** system SHALL compute `rvoMeldingDeadline: "2026-06-15"` and
  display it in the dashboard; send reminders on 2026-06-01 and 2026-06-12

### REQ-INV-008: Jaaraangifte Vpb/IB preparation

At boekjaarafsluiting the system SHALL produce a single "Bijlage
Investeringsaftrek" report grouping all claims by scheme, showing:

- Total KIA-aftrek (one number, after tier calculation).
- Total EIA-aftrek (sum of all EIA-claims × 40%).
- Total MIA-aftrek (sum of all MIA-claims × their respective 27/36/45%).
- Total Vamil-effect on afschrijving (informatief; flows via
  bookkeeping-fixed-assets-depreciation).
- Open RvO-beschikkingen still awaiting toekenning (with reservation of the
  aftrek pending RvO).

This report SHALL be exportable as PDF and as XBRL-fragments suitable for
inclusion in the SBR Vpb-aangifte rendered by `bookkeeping-vpb-corporate-tax`.

#### Scenario: Bijlage Investeringsaftrek produced at boekjaarafsluiting

- **GIVEN** a boekjaar containing KIA, EIA, MIA and Vamil claims plus open RvO-beschikkingen
- **WHEN** the boekjaar is closed
- **THEN** the system SHALL produce a single "Bijlage Investeringsaftrek" report grouped by scheme, exportable as PDF and as XBRL-fragments for the SBR Vpb-aangifte

### REQ-INV-009: Vrijwillige verlaging tracking

The system SHALL satisfy this requirement: Vrijwillige verlaging tracking.

An entrepreneur may **vrijwillig de aftrek verlagen** (art. 3.42a lid 4 /
3.42b lid 4) — i.e. claim less than the full statutory amount in the
current year, typically to avoid loss-relief expiry or to preserve
verliesverrekening across years. The system SHALL:

- Allow per-claim manual reduction with a mandatory rationale field.
- Refuse reduction below zero.
- Track the reduced amount separately from the legal entitlement so the
  contrast is auditable.
- Make clear that EIA/MIA reductions are **not carry-forwardable** — the
  foregone amount is lost.

#### Scenario: Vrijwillige verlaging recorded with rationale

- **GIVEN** an EIA-claim with a legal entitlement of EUR 20.000
- **WHEN** the entrepreneur voluntarily reduces the claimed amount to EUR 12.000 with a rationale
- **THEN** the system SHALL store the reduced amount separately from the legal entitlement, refuse reduction below zero, and flag the foregone EUR 8.000 as not carry-forwardable

### REQ-INV-010: Desinvesteringsbijtelling on early disposal

The system SHALL satisfy this requirement: Desinvesteringsbijtelling on early disposal.

If an asset on which KIA/EIA/MIA was claimed is **disposed of within
5 jaar na aanvang kalenderjaar van investering** (art. 3.47 Wet IB 2001),
a desinvesteringsbijtelling MUST be added to the taxable result of the year
of disposal. The bijtelling equals the original aftrek-percentage × the
lower of (a) opbrengst bij vervreemding or (b) aanschafwaarde. The system
SHALL:

- Maintain a 5-year disposal watch on every asset with an active claim.
- On disposal event from `bookkeeping-fixed-assets-depreciation`,
  automatically compute the bijtelling and post a draft journal entry
  against grootboekrekening `8120 Desinvesteringsbijtelling`.
- Notify the boekhouder with a clear before/after impact on the lopende
  Vpb-positie.
- For Vamil-assets, additionally trigger a **terugneming van de versnelde
  afschrijving** if disposal occurs before the gespreid deel is exhausted.

The bijtelling is capped on a per-scheme basis: er kan nooit meer worden
teruggepakt dan oorspronkelijk is afgetrokken.

#### Scenario: Desinvesteringsbijtelling on early disposal

- **GIVEN** an asset with EIA-aftrek 40% × EUR 50.000 = EUR 20.000,
  claimed in 2026
- **WHEN** the asset is disposed of for EUR 35.000 in 2028
- **THEN** bijtelling = 40% × min(EUR 35.000, EUR 50.000) = EUR 14.000;
  draft journal entry posts to account 8120 in 2028

### REQ-INV-011: Ex-ante calculator voor aanschafbeslissingen

Het systeem SHALL een "wat-als"-modus bieden waarin de ondernemer een
voorgenomen aanschaf kan invoeren (omschrijving, geschatte aanschafwaarde,
vermoedelijke categorie) zonder dat er een asset wordt aangemaakt. De
calculator zoekt automatisch de waarschijnlijke Energielijst-/Milieulijstcode(s)
op via tekstmatch en toont een drietal scenario's: (a) "alleen reguliere
afschrijving", (b) "met EIA óf MIA-claim", (c) "met MIA+Vamil triple stack
indien van toepassing". Het resultaat moet de netto contante waarde van
het belastingvoordeel over 5 jaar tonen, gegeven het IB- of Vpb-tarief van
de huidge onderneming. Dit ondersteunt go/no-go beslissingen voordat de
opdracht wordt verleend.

#### Scenario: Ex-ante calculator shows NPV of tax benefit

- **GIVEN** a EUR 50k solar-panel investment, estimated useful life 20 years
- **WHEN** boekhouder opens the ex-ante calculator and enters the details
- **THEN** system SHALL show:
  - Scenario A (no aftrek): EUR 0 tax benefit
  - Scenario B (EIA 40%): EUR 20k aftrek × 49.5% IB rate = EUR 9.9k over 5 years
  - Scenario C (if on Milieulijst with Vamil): EUR X aftrek + Vamil timing effect

### REQ-INV-012: Audit trail en RvO-correspondentie-archief

Elke claim, melding, beschikking, bezwaar en correctie SHALL onveranderlijk
worden gelogd met tijdstempel, gebruiker, en — voor RvO-interactie — het
volledige request/response payload. De RvO-beschikking (PDF download via
eLoket) SHALL als bijlage aan de claim worden gehangen. Bij een bezwaar of
beroepsprocedure moet het systeem op één scherm kunnen tonen: oorspronkelijke
melding, RvO-beschikking, eventueel afwijzingsgrond, ingediend bezwaar,
finale uitspraak, en de doorwerking in de aangifte. Deze audit trail is een
controle-eis voor accountants onder NV COS 4410 (samenstellingsopdracht) en
NV COS 4400N (overeengekomen specifieke werkzaamheden).

#### Scenario: RvO correspondence archived and immutably logged

- **GIVEN** a claim with a filed melding, an RvO-beschikking PDF, and a subsequent bezwaar
- **WHEN** the auditor opens the claim's audit trail
- **THEN** the system SHALL show the original melding, RvO-beschikking, afwijzingsgrond, bezwaar and finale uitspraak on one screen, each immutably logged with timestamp, user and full request/response payload

## Data Model Additions

Six new entities SHALL be added to `openspec/architecture/adr-000-data-model.md`:

1. **InvestmentAsset** — capitalised asset eligible for one or more
   investeringsaftrek schemes. 1-to-1 with FixedAsset.
2. **EnergielijstCode** — reference data, RvO Energielijst 2026 (~170 codes).
3. **MilieulijstCode** — reference data, RvO Milieulijst 2026 (~250 codes).
4. **InvesteringsaftrekClaim** — one row per (asset, scheme, boekjaar)
   combination.
5. **VamilDepreciation** — willekeurige afschrijving schedule (75% direct
   + 25% via regular depreciation).
6. **KIATier** — reference data, 2026 KIA tiered table.

(Detailed schemas listed in `openspec/architecture/adr-000-data-model.md`
update.)

## Cross-app integration

- **bookkeeping-fixed-assets-depreciation** — bron of InvestmentAsset
  (1-to-1 koppeling via fixedAssetId). Vamil-claim returns an aangepast
  afschrijfschema. Disposal-events published, consumed for
  desinvesteringsbijtelling-bewaking.
- **bookkeeping-vpb-corporate-tax** — consumes Bijlage Investeringsaftrek
  (REQ-INV-008) as XBRL-fragments for SBR Vpb-aangifte assembly.
- **bookkeeping-general-ledger** — posts desinvesteringsbijtelling GL
  entries to account 8120.
