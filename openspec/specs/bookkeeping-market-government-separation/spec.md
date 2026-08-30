---
status: done
---

# Spec: bookkeeping-market-government-separation

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-bbv-compliance (T3), bookkeeping-cost-centers-dimensions (T4), bookkeeping-general-ledger (T1)

## Purpose

This specification defines the requirements for bookkeeping market government separation in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: market/government separation pages not yet implemented


### REQ-WMO-001: The system SHALL maintain a Commercial Activity Register per bestuursorgaan

The system SHALL provide a `CommercialActivity` register with the following mandatory fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `code` | string | Yes | Operator-assigned unique code (e.g., `MO-SP-014` = Markt Ondernemen-Sporten) |
| `naam` | string | Yes | Human-readable name of the activity |
| `bestuursorgaan` | string | Yes | FK to administrationId or organization name |
| `organisatieonderdeel` | string | Yes | Department/section responsible (e.g., "Afdeling Cultuur & Erfgoed") |
| `beschrijving` | string | Yes | Description of goods/services offered on market |
| `marktsegment` | string | Yes | Market segment (e.g., "zakelijke evenementenlocaties Midden-Brabant") |
| `concurrenten` | array of string | Yes | Named private competitors (e.g., ["LocHal Tilburg BV", "Faxx Theater"]) |
| `afnemers` | array of string | No | Known customers/buyers |
| `startDatum` | date | Yes | Effective start date of commercial activity |
| `eindDatum` | date | No | Closure date (if ended) |
| `kostprijsMethode` | enum | Yes | One of `integrale-kostprijs-art-25i`, `kostprijs-monitor-zonder-winstopslag` (for ABB exemptions) |
| `kostenplaatsCode` | string | Yes | FK to CostCenter.code (from bookkeeping-cost-centers-dimensions) |
| `kostendragerCode` | string | Yes | FK to KostenDrager.code (from bookkeeping-cost-centers-dimensions) |
| `isExempted` | boolean | Yes | Whether exempted via AlgemeenBelangBesluit |
| `exemptionBesluitId` | string | No | FK to AlgemeenBelangBesluit.id (if isExempted=true) |
| `jaaromzet` | number | No | Last-known annual turnover in EUR (informational) |
| `acmMelding.ingediend` | boolean | Yes | Whether reported to ACM |
| `acmMelding.datum` | date | No | ACM submission date |
| `acmMelding.kenmerk` | string | No | ACM reference number (e.g., `ACM/UIT/498321`) |
| `lastReviewedAt` | datetime | No | Timestamp of last annual compliance review |
| `administrationId` | string | Yes | FK to the administration owning this activity |

The register SHALL enforce: (a) non-empty verplichte velden on save; (b) auditeerbare createdBy/updatedAt/lastReviewedAt timestamps; (c) an automatic annual review-task trigger if `lastReviewedAt` is > 365 days old.

#### Scenario: Register creation with mandatory fields

- **GIVEN** a Gemeente Apeldoorn opens a commercial dansschool in their sports facility
- **WHEN** a concerncontroller creates a CommercialActivity record with code `MO-SP-014`, naam "Dansschool de Maten", beschrijving "Verhuur zaalruimte voor commerciële danslessen", marktsegment "privédan­sscholen Apeldoorn", concurrenten ["Dansacademie Sint-Hubert"], kostenplaatsCode `K-SP-014`, kostendragerCode `D-MO-SP-014`, kostprijsMethode `integrale-kostprijs-art-25i`, startDatum "2024-01-15", and isExempted false
- **THEN** the record MUST be saved; ACM meldingstatus is automatically queued for submission within 4 weeks; the createdAt timestamp is recorded; the record appears in the "WMO Commercial Activities" UI list

#### Scenario: Review task generation for stale activity

- **GIVEN** a CommercialActivity with lastReviewedAt = "2024-12-31" (>365 days ago as of 2026-01-15)
- **WHEN** the system runs the daily review-task generator
- **THEN** a task SHALL be created and assigned to the concerncontroller: "Annual review due: <activity code> <name>"

### REQ-WMO-002: The system SHALL calculate Integral Cost Price per commercial activity per period with time-versioned records

The system SHALL compute the **integrale kostprijs (IKP)** per commercial activity on a configurable monthly cadence (default: monthly, with definitief lock on 31 March of following year). Each period's IKP is stored as an immutable `IntegralCostPrice` record with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `commercialActivityId` | string | Yes | FK to CommercialActivity.id |
| `periode` | string | Yes | Period identifier (e.g., `2026-Q1` or `2026-03`) |
| `berekendOp` | datetime | Yes | Timestamp calculation was run |
| `status` | enum | Yes | `voorlopig` (monthly) or `definitief` (year-end, 31-Mar lock) |
| `componenten` | object | Yes | See breakdown below |
| `totaleKosten` | number (EUR) | Yes | Sum of all components |
| `verkochteEenheden` | number | No | Units sold/delivered in period (for per-unit calculation) |
| `eenheidLabel` | string | No | Unit name (e.g., "dagdeel-zaalhuur") |
| `kostprijsPerEenheid` | number (EUR) | No | IKP ÷ verkochteEenheden (if eenheden tracked) |
| `gehanteerdTarief` | number (EUR) | No | Actual price charged to customers (for compliance check) |
| `marge` | number (EUR) | No | gehanteerdTarief - (kostprijsPerEenheid or totaleKosten); must be ≥ 0 for compliant |
| `margePercentage` | number | No | marge ÷ costprijsPerEenheid or totaleKosten × 100 |
| `compliant` | boolean | Yes | True if gehanteerdTarief ≥ costprijsPerEenheid for all units (or totaleKosten if no units tracked) |
| `toelichting` | string | No | Cost-center notes, allocation method, assumptions |

**Componenten breakdown:**

```json
{
  "directeLoonkosten": <number>,
  "directeMaterialen": <number>,
  "directeAfschrijvingen": <number>,
  "indirecteOverhead": {
    "huisvesting": <number>,
    "ict": <number>,
    "directieEnStaf": <number>,
    "facilitair": <number>,
    "<custom>": <number>
  },
  "vermogenskosten": <number>,
  "winstopslag": <number>
}
```

IKP calculation draws direct costs from GL accounts (tagged with the activity's kostenplaatsCode), overhead from the applicable `OverheadDistributionRule` (via `bookkeeping-bbv-compliance` taakveld 0.4), vermogenskosten via a weighted-average-cost-of-capital (WACC) rate (default 4.5%, configurable per administration), and winstopslag (default 2–5%, configurable per activity and per period).

#### Scenario: Monthly voorlopig calculation with BBV-sleutel overhead

- **GIVEN** Gemeente Tilburg's zaalverhuur activity in Q1 2026 with GL lines totalling: directe loonkosten €41.25k, directeMaterialen €8.73k, directeAfschrijvingen €6.9k
- **WHEN** the system calculates IKP-2026-Q1 on 2026-04-05 and applies the BBV overhead-sleutel (personele-fte basis, 7.2% of corporate €365.8k = €26.14k), vermogenskosten €1.82k (4.5% on €40.4k equipment), winstopslag 3% of total costs (€2.55k)
- **THEN** the `IntegralCostPrice` record is saved with status=`voorlopig`, totaleKosten=€87.39k, and if 312 dagdelen sold, kostprijsPerEenheid=€280/unit; if gehanteerdTarief=€295, then compliant=true, marge=€15, margePercentage=5.4%

#### Scenario: Year-end definitief lock with audit signature

- **GIVEN** 12 monthly `voorlopig` IKP records for FY2025 (Jan–Dec), now 31 Mar 2026
- **WHEN** the system runs the year-end-close job and the accountant/concerncontroller signs off on the FY2025 IKP
- **THEN** a new `IntegralCostPrice` record IKP-2025-YTD is created with status=`definitief`, berekendOp=<accountant-sign-timestamp>, and the prior 12 `voorlopig` records remain immutable for audit trail

### REQ-WMO-003: The system SHALL automatically split transactions touching commercial activities per OverheadDistributionRule

Every `JournalEntry` posted with a line (debit or credit) on a GL account that is linked to a commercial activity (via the activity's kostenplaatsCode or kostendragerCode) SHALL trigger automatic creation of an `ActivityCostAllocation` record that splits the line amount across publieke and commerciële sub-administraties according to the geldende `OverheadDistributionRule` for that period.

The split MUST:

1. Use the `OverheadDistributionRule` valid on the posting date (geldigVan ≤ posting date ≤ geldigTot).
2. Create a reversible, immutable `ActivityCostAllocation` record describing the split.
3. Optionally materialize additional GL lines (balanced by nature) to post the split to sub-dimensies, if the operator configures "post splits to ledger" mode; otherwise, the split is *derived* for reporting purposes.
4. Allow handmatige override with verplichte motivering and 4-ogen-akkoord (two user IDs required).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `journalEntryId` | string | Yes | FK to the journal entry being split |
| `originalAmount` | number (EUR) | Yes | Total transaction amount |
| `splits[]` | array | Yes | See below |
| `verdeelsleutel` | string | Yes | FK to OverheadDistributionRule.id used |
| `automatischToegepast` | boolean | Yes | True if automatic split; false if manually overridden |
| `handmatigeOverride` | object | No | If overridden: { approvedBy: [user1, user2], reason: "...", timestamp: ... } |

**Splits array item:**

```json
{
  "kostendrager": "D-PUBL-NM-100 or D-MO-NM-001",
  "ratio": 0.910 or 0.072,
  "amount": <calculated>,
  "grootboek": "443100 Energie publiek or 443900 Energie marktactiviteit",
  "dimensie": "PUBL or MO"
}
```

#### Scenario: Automatic energy-cost split

- **GIVEN** Waterschap Vechtstromen's sludge-processing activity with OverheadDistributionRule ratio 64% publiek / 36% commercieel
- **WHEN** an Eneco energy invoice for €18,400 is posted with GL account `443100 Energie` and linked to kostenplaats `K-SBV-001`
- **THEN** an `ActivityCostAllocation` record is created with splits: [{ kostendrager: D-PUBL-AWZI-01, ratio: 0.64, amount: €11,776 }, { kostendrager: D-MO-SVS-04, ratio: 0.36, amount: €6,624 }], and reporting queries show the energy cost split across the two sub-administraties

#### Scenario: Handmatige override with 2-eyes sign-off

- **GIVEN** the automatic split described above, but the operator discovers the rule was wrong (should be 70/30, not 64/36)
- **WHEN** the operator marks the allocation `handmatigeOverride: true`, logs reason "Sleutel onjuist; werkelijke verdeling is 70% publiek", and obtains 2-eyes sign-off from controller and BBV-specialist
- **THEN** a new `ActivityCostAllocation` record is created with automatischToegepast=false, the original split is marked `status: overridden`, and the new split with 70/30 ratio is used in subsequent reporting

### REQ-WMO-004: The system SHALL export a jaarrekening-bijlage WMO per commercial activity per fiscal year

The system SHALL generate, as part of the jaarrekening-bijlagen, a **kostendekkingsoverzicht** per commercial activity with:

1. Activity identifiers (code, naam, bestuursorgaan, marktsegment).
2. Annual omzet (revenue from customer billings).
3. Integrale kostprijs (broken down: directe loonkosten + directe materialen + directe afschrijvingen + indirecte overhead + vermogenskosten + winstopslag).
4. Kostendekkingsratio (omzet ÷ integrale kostprijs; must be ≥ 100% for Art. 25i compliance).
5. Vergelijking met voorgaand jaar (prior-year omzet, prior-year IKP, ratio delta).
6. (If isExempted=true) Reference to the geldende ABB-besluit (code, vaststellingsdatum, publiek-belang-categoriëen).
7. (If handmatige splits exist) Count and reason of manual overrides during the year.

The export MUST conform to the **VNG-formaat WMO-bijlage jaarrekening (versie 2024)** and MUST be machine-leesbaar (SBR/XBRL compatible).

#### Scenario: Jaarrekening-bijlage generation and compliance signoff

- **GIVEN** Gemeente Tilburg with 7 commercial activities in FY2025, all with definitief IKP and omzet recorded
- **WHEN** the jaarrekening-export process generates the WMO-bijlage
- **THEN** a PDF + XML (SBR/XBRL) file is produced; each activity shows omzet, IKP breakdown, kostendekkingsratio, and compliance status (groen=compliant, rood=non-compliant); the concerncontroller can review and approve for publication

### REQ-WMO-005: The system SHALL manage the AlgemeenBelangBesluit (ABB) lifecycle with automatic evaluation scheduling

The system SHALL support `AlgemeenBelangBesluit` records with workflow progression:

**concept → raadsvoorstel → raadsbesluit → publicatie gemeenteblad → kennisgeving ACM → bezwaartermijn → geldig → evaluatie → herziening/intrekking**

| Field | Type | Required | Purpose |
|---|---|---|---|
| `kenmerk` | string | Yes | Raadsbesluit reference (e.g., "Raadsbesluit 2025-184") |
| `bestuursorgaan` | string | Yes | Gemeente/provincie/waterschap etc. |
| `vaststellingsdatum` | date | Yes | Raad-vote date |
| `publicatieGemeenteblad` | string | No | Gemeenteblad reference (e.g., "gmb-2021-401892") |
| `publicatieDatum` | date | No | Publication date in gemeenteblad |
| `kennisgevingAcm.ingediend` | boolean | Yes | ACM notification submitted |
| `kennisgevingAcm.datum` | date | No | ACM submission date |
| `kennisgevingAcm.kenmerk` | string | No | ACM reference (e.g., "ACM/IN/621004") |
| `betreftActiviteiten[]` | array of string | Yes | FK array to CommercialActivity.id records exempted by this ABB |
| `publiekBelangCategorieen[]` | array of enum | Yes | One or more of: arbeidsparticipatie, duurzaamheid, armoedebestrijding, gezondheid, cultuur, onderwijs, onderzoek, innovatie, sociaaleconomische cohesie, <<custom>> |
| `motivering` | string | Yes | Reasoned justification for public interest per VNG-format |
| `evaluatieRitme` | enum | No | Frequency: jaarlijks, tweejaarlijks (default), driejaarlijks |
| `volgendeEvaluatie` | date | Yes | Next scheduled evaluation date (auto-calculated: vaststellingsdatum + evaluatieRitme) |
| `status` | enum | Yes | concept / raadsvoorstel / raadsbesluit / publicatie / acm-notified / bezwaar / **geldig** / evaluatie-due / herziening / intrekking |
| `bezwaarTermijnVerstreken` | boolean | No | True if 6-week post-publication bezwaarschrift period has expired |
| `bestuursrechtelijkeProcedures[]` | array | No | References to any bezwaarschrift / administratief beroep records |

Precondition on status transition to `geldig`: publicatieDatum must be recorded AND ACM notification must be `ingediend`.

Automatic workflow:

- On save with status=raadsbesluit: generate task "Publish in gemeenteblad by [date+14 days]"
- On save with status=publicatie + publicatieDatum: generate task "Notify ACM by [date+7 days]"
- On save with status=acm-notified: start 6-week bezwaarschrift period; generate task "Review bezwaarschriften by [date+42 days]"
- On `volgendeEvaluatie` date reached: generate task "Evaluate ABB: is it still justified?" and move status to `evaluatie-due`

#### Scenario: ABB workflow progression with automatic DROP-API publication verification

- **GIVEN** Provincie Gelderland creates an ABB voor Airborne Museum exploitatie, Raadsbesluit PS2026-44, vaststellingsdatum "2026-01-20"
- **WHEN** the ABB status is set to raadsbesluit, then on "2026-02-10" an employee publishes it in provinciaal blad with reference "pb-2026-78" and sets publicatieDatum="2026-02-10"
- **THEN** the system auto-triggers a DROP-API query to verify "pb-2026-78" is retrievable; if successful, ACM-notification task is generated; if DROP verification fails, alert is logged
- **WHEN** ACM notification is submitted with kenmerk "ACM/IN/710022" on "2026-02-17" and status transitions to acm-notified
- **THEN** the bezwaarschrift period counter starts; 42 days later (2026-03-31), an evaluation task is auto-generated for the 2-year review cadence (volgendeEvaluatie="2028-01-20")

#### Scenario: ABB stale detection (not evaluated for >2 years)

- **GIVEN** an ABB with evaluatieRitme=tweejaarlijks and volgendeEvaluatie="2024-01-15" (now past as of 2026-01-15)
- **WHEN** the system runs the monthly ABB-review scanner
- **THEN** a high-priority alert is logged: "ABB [kenmerk] due for evaluation [date]" and assigned to juridisch-beleidsmedewerker

### REQ-WMO-006: The system SHALL generate ACM-rapportages in the ACM-standaardformulier 2024 format

The system SHALL produce quarterly and annual rapportages in the official **ACM Markt en Overheid rapportageformulier 2024** with:

1. All commercial activities and their omzet, integrale kostprijs (by component), kostendekkingsratio.
2. List of all ABB-besluiten effective during the period, with motivering excerpts.
3. All handmatige ActivityCostAllocation overrides during the period (count + reason summary).
4. Compliance-exception summary (activities with kostendekkingsratio < 100%, reasons, remediation planned).

The rapportage MUST:

- Be generated as machine-leesbare JSON/XML (SBR/XBRL compatible for future ACM API submission).
- Require formal ondertekening by concerncontroller (digital signature + UTC timestamp).
- Support publication to gemeenteblad (wenn gewenst).
- Upon submission to ACM, become immutable write-once (status=verzonden), marked in audit trail, archived with 7-year retention (Mededingingswet bewaartermijn).

#### Scenario: ACM quarterly report generation and signature

- **GIVEN** Gemeente Utrecht with 5 commercial activities, 12 transaction splits overridden, 3 ABB-besluiten active in Q1 2026
- **WHEN** the ACMReport record is generated for periode="2026-Q1" and the concerncontroller clicks "Sign & Submit"
- **THEN** the report is serialized to JSON/XML; a digital signature is attached (timestamp, user-id, cert hash); the record status becomes verzonden; a copy is auto-archived to a write-once store; the UI shows "Submitted to ACM on [date] — kenmerk pending from ACM"

### REQ-WMO-007: The system SHALL detect and alert on cross-subsidy risks monthly

The system SHALL run a monthly `CrossSubsidyDetector` scheduled workflow (per ADR-031 ScheduledWorkflow, default 1st of month at 02:00 UTC) that evaluates all commercial activities per administration against these risk scenarios:

1. **Loss financing (2+ consecutive periods)**: if kostprijsPerEenheid > gehanteerdTarief for 2 or more consecutive months → alert severity HIGH.
2. **Omzet spike without IKP update**: if omzetgroei YoY > 25% but IKP calculation not updated within 30 days → alert severity MEDIUM.
3. **Overhead under-allocation**: if indirecteOverhead is < 1% of totaleKosten while directe loonkosten > 10% of totale → alert severity MEDIUM (signals possible overhead under-estimate).
4. **ABB stale**: if ABB vaststellingsdatum > volgendeEvaluatie + 90 days (i.e., evaluation overdue by >3 months) → alert severity HIGH.
5. **Manual override accumulation**: if handmatigeOverride count in a quarter > 5% of total transaction count → alert severity LOW (warns of systematic rule misconfiguration).
6. **Potentiële overhead-onderschatting**: if a new high-value supplier invoice (> €50k) on publieke kostenplaats is routed to commerciële kostendrager in this period, but >30% of prior-year invoices from this supplier were routed publiek → alert severity MEDIUM.

All alerts MUST be logged to an `AlertLog` register with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `alertType` | enum | Yes | One of the 6 scenarios above |
| `commercialActivityId` | string | Yes | FK to affected CommercialActivity |
| `severity` | enum | Yes | LOW / MEDIUM / HIGH |
| `generatedAt` | datetime | Yes | Timestamp alert was raised |
| `assignedTo` | string | Yes | concerncontroller user-id |
| `status` | enum | Yes | open / reviewed-no-action / remediated / escalated |
| `escalatedAt` | datetime | No | Timestamp escalation to gemeentesecretaris (if > 4 weeks open) |
| `resolutionNotes` | string | No | Operator's response |

#### Scenario: Loss-financing alert triggers on second consecutive loss month

- **GIVEN** zaalverhuur activity with gehanteerdTarief=€280/unit, IKP-2026-01=€295/unit (loss), IKP-2026-02=€298/unit (loss)
- **WHEN** the CrossSubsidyDetector runs on 2026-03-01
- **THEN** an AlertLog record is created with alertType=loss-financing, severity=HIGH, assigned to concerncontroller, status=open; 4 weeks later (2026-03-29), if still open, status transitions to escalation-due

### REQ-WMO-008: The system SHALL support activity transitions (public ↔ commercial) with openingsbalans and marktwaarde transfer

This requirement is **deferred to Phase 3** but specified here for architecture. When a gemeente decides to transition a publieke activiteit to commercieel status (or vice versa), the system SHALL:

1. Accept an effectieve-transition-datum.
2. Close all openstaande-verplichtingen on the old dimension.
3. Generate an **openingsbalans van de commerciële sub-administratie** with activa-transfer at **marktwaarde** (not boekwaarde — to prevent Art. 25j bevoordeling).
4. Record the transfer as an internal sale (interne verkoop, ten gunste van publieke dimensie).
5. Flag the first IKP-cycle as `voorlopig-transitie` for manual review.
6. Trigger ACM-melding within 4 weeks.

#### Scenario: Almere sportkantine transition 2026-01-01 (Phase 3)

- **GIVEN** Gemeente Almere's sportkantine-exploitatie (currently publiek, inventory boekwaarde €87k, marktwaarde getaxeerd €142k)
- **WHEN** a transitie-record is created with effectieveDatum="2026-01-01", marking the activity status as "publiek→commercieel"
- **THEN** the system creates a transition journal entry: Dr "Inventaris Commercieel" €142k Cr "Interne verkoop PUBL→MO" €142k; the commerciële kostendrager D-MO-SP-020 now owns €142k assets; IKP-2026-Q1 is marked `voorlopig-transitie` for accounting review; ACM-melding is queued

### REQ-WMO-009: The system SHALL integrate with raadsvoorstel-besluit workflow for governance coupling (Phase 3)

This requirement is **deferred to Phase 3** but specified here for architecture. When available, the system SHALL link ABB-besluiten and annual OverheadDistributionRule vaststelling to a `bookkeeping-governance` raadsvoorstel-besluit chain:

1. ABB SHALL NOT transition to status=raadsbesluit without a linked raadsvoorstel-id.
2. OverheadDistributionRule.vaststellingsbesluit SHALL reference a raad-decision with signature + griffier-handtekening.
3. Signature + timestamp on the governance-side record are inherited into WMO audit trail.

This ensures IKP-tarieven and ABB-exemptions carry formal bestuurlijke legitimatie and are auditable to the raad.

#### Scenario: ABB blocked from raadsbesluit without linked raadsvoorstel

- **GIVEN** the Phase 3 governance coupling is available and an ABB has no linked raadsvoorstel-id
- **WHEN** an operator attempts to transition the ABB to status=raadsbesluit
- **THEN** the system SHALL refuse the transition and require a linked raadsvoorstel-id, with the raad-decision's signature, timestamp and griffier-handtekening inherited into the WMO audit trail

### REQ-WMO-010: The system SHALL provide an immutable audit trail meeting ACM-onderzoek standards

All WMO-mutations (CommercialActivity create/update, IntegralCostPrice calculation, ActivityCostAllocation override, AlgemeenBelangBesluit state-change, ACMReport generation, CrossSubsidyAlert creation/resolution) SHALL be logged to an immutable `WMOAuditLog` register per `bookkeeping-audit-trail` cross-cutting spec, with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `eventType` | enum | Yes | activity-created, activity-updated, ikp-calculated, split-overridden, abb-status-change, acm-report-generated, alert-created, alert-resolved |
| `entityId` | string | Yes | FK to affected entity (CommercialActivity.id, IntegralCostPrice.id, etc.) |
| `entityType` | enum | Yes | CommercialActivity, IntegralCostPrice, ActivityCostAllocation, AlgemeenBelangBesluit, ACMReport, CrossSubsidyAlert |
| `userId` | string | Yes | Nextcloud user-id of mutator (pseudononymized per GDPR) |
| `timestamp` | datetime | Yes | UTC timestamp with ms-precision |
| `beforeValues` | object | No | JSON snapshot of record before change |
| `afterValues` | object | Yes | JSON snapshot of record after change |
| `reason` | string | No | Motivation for change (verplicht for overrides, recommendations for all others) |
| `status` | enum | Yes | logged / archived (after 7-year retention) |

The log SHALL be exportable as:

1. **CSV** for spreadsheet review.
2. **ACM-handhavings-pakket** (zip archive) with: `manifest.json` (index of all log entries), `commercial-activities/<id>.json` (entity snapshots), `cost-prices/<period>/<id>.json`, `allocations/<period>/<journal-id>.json`, `besluiten/<id>.pdf` (ABB decision PDFs), `audit-log/<period>.csv` (time-ordered audit entries).

The handhavings-pakket SHALL be generatable in one click: "Generate ACM Handhaviings-Pakket for FY2025" → zip download.

#### Scenario: Audit-trail export for ACM-onderzoek

- **GIVEN** Gemeente Eindhoven's commercial parkeerexploitatie under ACM investigation for insufficient cost-price pass-through
- **WHEN** ACM issues a vordering ex Art. 5:17 Awb requesting all cost-price-related documents and decisions for FY2024
- **THEN** the concerncontroller clicks "Generate ACM-Handhavings-Pakket" for FY2024; a 50MB zip is created containing: manifest.json (list of 47 documents), parkeerexploitatie.json (CommercialActivity record + all mutations), ikp-2024-*.json (12 monthly IKP records with component breakdowns), splits-*.json (1847 ActivityCostAllocation records), kostprijsvoorstel.pdf (raadsvoorstel PDFs), audit-log-2024.csv (time-ordered events). The zip is submitted to ACM; investigation proceeds with complete indexing and no need for 8600 loose PDF's.

### REQ-WMO-011: The system SHALL support multi-bestuursorgaan (shared services, GR, RUD) with per-deelnemer ABB and per-deelnemer jaarrekening

When a single commercial activity is operated by **multiple bestuursorganen together** (e.g., a gemeenschappelijke regeling, omgevingsdienst, GGD), the CommercialActivity SHALL support:

1. A `deelnemers[]` array with each deelnemer's: organisation name, aandeel-percentage, own kostenplaats/kostendrager codes, own IKP-allocatie basis.
2. **Per-deelnemer ABB requirement**: if *any* deelnemer wants exemption, that deelnemer SHALL have a separate AlgemeenBelangBesluit. A deelnemer without an ABB does NOT inherit the exemption.
3. **Per-deelnemer cost allocation**: activity costs are split across deelnemers per aandeel-percentage.
4. **Per-deelnemer jaarrekening-bijlage**: each deelnemer receives a WMO-bijlage showing only their aandeel.
5. **Per-deelnemer ACM reporting**: each deelnemer reports their aandeel-aktiviteiten to ACM independently.

#### Scenario: Omgevingsdienst Regio Arnhem (ODRA) shared bodemadvies service

- **GIVEN** ODRA (11 deelnemende gemeenten, inwoneraantal-weighted verdeelsleutel) operates a commerciële bodemadvies-service for particuliere bouwers (concurrent with Antea Group, RoyalHaskoningDHV)
- **WHEN** the CommercialActivity is created with deelnemers=[{org: "Gemeente Arnhem", aandeel: 18%, kostenplaats: "K-OM-001", kostendrager: "D-MO-OM-001"}, {org: "Gemeente Velp", aandeel: 12%, kostenplaats: "K-OM-002", kostendrager: "D-MO-OM-002"}, ...], and costs (loonkosten, overhead, vermogenskosten) total €300k annually
- **THEN** annual IKP is calculated centrally (€300k total), split across deelnemers per aandeel (Arnhem €54k, Velp €36k, etc.); each gemeente receives ACM-report showing only her aandeel; Arnhem might have an ABB for public-interest arbeidsparticipatie, while Velp does not — ODRA's reporting shows Arnhem-aandeel exempted, Velp-aandeel non-exempted (same activity, asymmetric ABB status)

#### Scenario: Incomplete multi-deelnemer ABB detection

- **GIVEN** the ODRA scenario, but only Arnhem has filed an ABB, not the other 10 deelnemers
- **WHEN** the system generates the annual ACM-rapport
- **THEN** a HIGH alert is logged: "Multi-deelnemer activity [code] missing ABB from 10 other deelnemers" with recommendation to werk-organisaties contact other deelnemers to file matching ABB's

### REQ-WMO-012: The system SHALL maintain a market-benchmark register for tariff validation and bevoordeling-risk detection

The system SHALL support a `MarketBenchmark` register for comparative tariff validation:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `commercialActivityId` | string | Yes | FK to CommercialActivity |
| `peildatum` | date | Yes | Date benchmark was recorded |
| `bron` | enum | Yes | One of: offerte, prijslijst, brancheRapport, bdoBenchmark, coelo, <<custom>> |
| `bedrag` | number (EUR) | Yes | Benchmark price |
| `eenheid` | string | Yes | Unit (same as IKP eenheidLabel for comparability) |
| `concurrentNaam` | string | No | Name of competing provider (if offerte/prijslijst) |
| `toelichting` | string | No | Source notes, assumptions |

When IKP is calculated, the system SHALL:

1. Retrieve all MarketBenchmark records for the activity (within last 12 months).
2. Calculate median benchmark price.
3. If (gehanteerdTarief < median-benchmark × 0.85) AND (gehanteerdTarief ≥ kostprijsPerEenheid), flag a **bevoordeling-risk** alert: "Price is 15%+ below market despite IKP-compliant; possible Art. 25j bevoordeling of own overheidsbedrijf. Justify or raise tariff." (severity MEDIUM).

#### Scenario: Den Haag parkeerexploitatie bevoordeling-risk flagging

- **GIVEN** Gemeente Den Haag's Atrium vergaderzalen: IKP=€165/dagdeel (compliant), gehanteerdTarief=€180/dagdeel, MarketBenchmarks: LocHal Tilburg €245, Hotel Mercure €240, gemiddelde €242.50
- **WHEN** the system calculates IKP and compares tarief to benchmarks: €180 < €242.50 × 0.85 (€206.13) — spreads to €180
- **THEN** a bevoordeling-risk alert is logged: "Tariff €180 is 26% below market median €242.50. Rationale required (different service level, no catering, smaller capacity, etc.); if not justified, recommend tariff increase." The Den Haag controller can mark the alert "reviewed and justified: smaller capacity, no catering, lower service level" and log rationale to audit trail.
