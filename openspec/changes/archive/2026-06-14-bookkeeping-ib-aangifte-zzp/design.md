# Design — IB Aangifte (Income Tax Return) Assembly for ZZP

## Context

Dutch ZZP'ers (zelfstandigen zonder personeel) and eenmanszaken must file an annual inkomstenbelasting (IB) return — the P-formulier — combining business profit (winst uit onderneming, box 1), savings/investment income (box 3), and various relief entitlements. The process is:

1. **Close the fiscal year** → trial balance, GL aggregation
2. **Calculate fiscal profit** → apply art. 3.6–3.79a adjustments (representatiebeperking, goodwill, auto bijtelling, home-office rules)
3. **Claim deductions** → zelfstandigenaftrek (conditional on ≥1225 hours), startersaftrek (if in first 5 years), MKB-exemption, investeringsaftrek (KIA/EIA/MIA)
4. **Calculate reliefs** → heffingskortingen (algemene, arbeids-, IACK), lijfrente room utilization, box-3 vermogen
5. **Validate eligibility** → check urencriterium, MKB rules, lijfrente carryforward history
6. **Pre-fill P-formulier** → map calculated values to 200+ XBRL rubrics
7. **Generate XBRL instance** → per Dutch Taxonomy NT17, valid for Digipoort
8. **File & retain audit trail** → 7 years per AWR art. 52

Per ADR-031 (declarative business logic), this spec expresses the entire P-formulier assembly as entity schemas + aggregation queries + validation rules, not PHP code.

## Goals

- **One-click IB assembly** — ZZP'er clicks "Start IB 2025" and receives a fully pre-filled, concept IBAangifte with all GLdata aggregated, all eligible deductions calculated, and all relief entitlements proposed.
- **Fiscal compliance in metadata** — all Wet IB rules (art. 3.6–3.79a, heffingskortingen, lijfrente, box-3) embedded as declarative entities + lifecycles + validation rules. No custom PHP tax logic.
- **Audit-trail traceability** — every P-formulier rubric value links to underlying GL journaalposten, external API responses (urencriterium tracker, investeringsaftrek), or brontabellen. Full drill-down for accountant/fiscalist review.
- **Legal XBRL generation** — automated serialization to Dutch Taxonomy NT17 format, valid for Digipoort FRC/AGV submission.
- **Digitale belastingadviseur** — rule-based + LLM-augmented suggestions: "optimize lijfrente room usage," "consider FOR drawdown timing," "MKB-exemption impact: EUR 5,600 tax savings."
- **Becon-route ready** — fiscal intermediaries can approve & digitally sign on behalf of ZZP'ers; Digipoort SOAP integration for direct submission.
- **Correctieaangifte support** — amendments workflow for post-filing corrections with diff-tracking and teruggave/aanvullende betaling calculation.

## Non-Goals

- No inline tax-law calculation services (PHP actuarial code). Per ADR-031 exception, no `TaxCalculator::calculateZelfstandigenaftrek()` methods.
- No real-time Digipoort certificate validation (eHerkenning OCSP, PKIoverheid CA chain verification). T4 security audit.
- No multi-year carryforward aggregation (e.g., automatic rollup of unused lijfrente room from 2015–2024). Manual opening-balance entry in v1; auto-aggregate in v2 (T4).
- No payroll integration (loon-input from payroll engines). W-formulier (loondienst + ondernemen) uses manually entered income figures from payroll slip or XBRL import.
- No multi-currency box-3 (foreign stocks, international bonds). EUR-only in v1; FX in T4.
- No pension-fond (PEC) governance workflows or regulatory-filing automation (DNB rapportering). T4.

## Decisions

### D1 — Eight entities: IBAangifte (header) + 7 sub-entities (profit, deductions, reliefs, box3, audit)

**Decision**: Decompose the P-formulier into a primary `IBAangifte` entity (status, filing channel, fiscal year, ondernemer identifiers, final teruggave/betaling) + seven sub-entities for each logical domain:
- **IBWinstOpgave** — fiscal P&L with adjustments per art. 3.6–3.79a
- **IBOndernemersaftrek** — self-employed deductions (zelfstandigenaftrek, startersaftrek, MKB, KIA/EIA/MIA)
- **IBHeffingskortingenAlgemeen** — tax credits (AHK, arbeidskorting, IACK, ouderen, jonggehandicapt)
- **IBLijfrenteAOV** — pension savings room (jaarruimte + reserveringsruimte per art. 3.127)
- **IBBijtellingAuto** — private-use car bijtelling per art. 3.20 (per vehicle)
- **IBBox3Vermogen** — box-3 savings/investments per overbruggingswet
- **IBAuditTrail** — full drill-down links from rubric to GL journaalposten + external evidence

**Rationale**: Monolithic single-table aangifte would bloat the schema (200+ fields) and prevent drill-down audit trails. Each sub-entity is a first-class record with its own lifecycle (draft → gevalideerd → gefreezed on filing), allowing independent validation and reuse (e.g., an accountant reviewing only the IBWinstOpgave for P&L accuracy).

**Alternative considered**: Flat table with JSONB columns for each domain. Rejected — no discrete audit trail per domain; GL linking requires extra parsing logic.

### D2 — GL-to-rubriek mapping via RGS chart-of-accounts + aggregation queries

**Decision**: The system aggregates GL balances (Account.accountNumber per RGS Referentie Grootboek Schema, T1) using OpenRegister `x-openregister-aggregations` queries. Each query maps a GL range (e.g., accounts 8000–8099 = omzet; 1300–1399 = kostprijs) to a specific IBAangifte rubric.

**Rationale**: Avoids hard-coded GL-code lists in PHP. RGS is the Dutch accounting standard; the chart-of-accounts module (T1) declares the GL hierarchy; aggregation queries are data-driven.

**Example aggregation** (pseudocode):
```
IBWinstOpgave.omzetExclusiefBtw = 
  SUM(GLLine.creditAmount) 
  WHERE Account.accountNumber BETWEEN 8000 AND 8099 
  AND FiscalYear.year = 2025
```

### D3 — Fiscal adjustments logged as discrete records with grondslag

**Decision**: Instead of modifying GL entries, the system creates a `fiscaleAfwijking` array in IBWinstOpgave. Each adjustment record contains:
- `post` — the adjustment type (REPRESENTATIE_DREMPEL, GOODWILL_AFSCHRIJVING, AUTOKOSTEN_BIJTELLING, etc.)
- `bedrag` — the EUR adjustment
- `grondslag` — the law article (e.g., "art. 3.15 Wet IB 2001")
- `gebruiker` — who logged the adjustment (system auto-detect or accountant manual override)

**Rationale**: Maintains GL integrity (no alterations); provides full audit trail; allows accountant to challenge or override adjustments in the UI without code change.

### D4 — Zelfstandigenaftrek conditional on urencriterium ≥1225 hours

**Decision**: System calls the `zzp-urencriterium-tracker` API to fetch `urenRapport.uren` for the fiscal year. If uren < 1225, `IBOndernemersaftrek.zelfstandigenaftrek.toegestaan = false` and the `IBOndernemersaftrek.totaalAftrek` excludes it. The aangifte status becomes "GEVALIDEERD_MET_WAARSCHUWING" with a blocking message "Zelfstandigenaftrek niet toegestaan — urencriterium niet behaald (1180 uren gerapporteerd; 1225 uren vereist). Uren toevoegen in tracker."

**Rationale**: Zelfstandigenaftrek is the most-claimed self-employed relief (EUR 2.470 in 2026) and the most-audited by Belastingdienst. Failing to validate urencriterium risks Belastingdienst penalty + surcharge.

**Alternative considered**: Allow ZZP'er to claim zelfstandigenaftrek without urencriterium, with a "risk flag" for accountant review. Rejected — system must enforce legal gatekeeping, not delegate to user judgment.

### D5 — MKB-winsvrijstelling auto-calculated after ondernemersaftrek

**Decision**: The system calculates MKB-exemption per Belastingplan 2024:
```
mkbVrijstelling = winst_na_ondernemersaftrek × 0.127  (for 2026; subject to annual update)
belastbareWinst = winst_na_ondernemersaftrek − mkbVrijstelling
```

The rate per fiscal year is stored in a `IBTaxParameterYear` metadata entity (versionable per year). No hardcoding.

**Rationale**: MKB rate changes annually (2025: 12,03%; 2026: 12,7%; 2027: TBD). Storing rates in metadata allows deployment-free annual updates.

### D6 — Lijfrente room: jaarruimte (13,3% × (franchise-adjusted premiegrondslag)) + 10-year reserveringsruimte carryforward

**Decision**: Per art. 3.127 Wet IB, the system calculates:
- **jaarruimte**: 13.3% × (premiegrondslag − franchise 2026). Premiegrondslag = min(winst_na_MKB, EUR 107.000 in 2026, subject to AOW-age reduction formula). Franchise 2026 = EUR 17.546 (indexed annually).
- **reserveringsruimte**: cumulative unclaimed jaarruimte from prior 10 fiscal years, capped at EUR 9.200 (2026).

Both are editable in the UI (system calculates defaults; accountant can override with grondslag).

**Rationale**: Lijfrente is a high-value optimization (EUR 1–3K annual savings for mid-size ZZP'ers). Accurate room calculation attracts adoption.

**Alternative considered**: Assume ZZP'er provides manual room opening balance (simpler, no 10-year history required). Rejected — v1 spec should auto-calculate; manual override available if needed.

### D7 — Heffingskortingen auto-calculated per gezinssituatie flags + box-1-inkomen

**Decision**: The system calculates:
- **Algemene heffingskorting (AHK)**: max(EUR 3.362 − 6.337% × (box1_inkomen − EUR 28.406), 0) for 2026. Subject to annual parameter update.
- **Arbeidskorting (AK)**: Depends on box-1 inkomen; phase-out from EUR 43.071 (2026). Only for earned income (winst + loon).
- **IACK (inkomensafhankelijke combinatiekorting)**: EUR 0–2.986 (2026) for single parent with child <12 + working partner (gezinssituatie flags from HRMQ or profiel).
- **Ouderenkorting, jonggehandicaptenkorting**: Stored as metadata flags; included if set.

All rates/thresholds in `IBTaxParameterYear`.

**Rationale**: Heffingskortingen are confusing for ZZP'ers. Auto-calculation increases compliance confidence.

### D8 — Box 3 vermogen: three categories per overbruggingswet; werkelijk rendement OR forfait (taxpayer choice)

**Decision**: The system tracks box-3 position (banktegoeden + overige bezittingen − schulden) on balansdatum per three-category split per overbruggingswet. For each category, it calculates:
- **Heffingvrij vermogen** (EUR 57.684 in 2026, indexed annually): cap before tax
- **Belastbare grondslag**: (total vermogen − heffingvrij) × category
- **Rendement**: Taxpayer chooses `werkelijkRendement` (reported annual interest + dividend income) OR `forfaitRendement` (% per law); system suggests the lower.

**Rationale**: Box 3 is now simplified under overbruggingswet, but still requires accurate vermogen tracking (balance sheet detail). Many ZZP'ers have small savings (EUR 20–100K); automatic box-3 calculation from balans data improves accuracy.

### D9 — XBRL serialization per Dutch Taxonomy NT17; no embedded calculation logic

**Decision**: The spec defines a serializer that maps each IBx entity field to the corresponding XBRL rubriek (NT17 codification). The XBRL instance is generated by querying the entities + applying the taxonomy schema. The serializer validates that all mandatory rubrics are populated before exporting.

**Rationale**: XBRL is the legal Digipoort format. Embedding the taxonomy ensures valid instances without custom XBRL-building code.

### D10 — Audit trail: every IBx field links to GL journaalpost(en) + external evidence

**Decision**: The `IBAuditTrail` entity contains a `regels` array; each rule maps:
- `rubriek` — the P-formulier field (e.g., omzet_excl_btw)
- `waarde` — the EUR amount
- `bron` — the GL account range or external API (e.g., "GL_8000-8099" or "uren-tracker-2025-ond-001234")
- `journaalposten` — array of GL entry IDs that sum to the value
- `berekendOp` — timestamp of calculation
- `berekendDoor` — system or user identifier

**Rationale**: Per AWR art. 52 (7-year retention) + accountant audit requirements. ZZP'ers and fiscalists must be able to drill into every rubric value and see the GL source.

### D11 — Becon-route: PKIoverheid-services-certificaat signing; fiscal intermediaries act on behalf of ZZP'er

**Decision**: A fiscalist (status = ROLE_FISCALIST) with a valid PKIoverheid-services-certificaat can:
1. View a concept IBAangifte owned by a ZZP'er client
2. Edit specific fields (with audit log)
3. Approve the aangifte (status → GEVALIDEERD_DOOR_FISCALIST)
4. Sign the XBRL with their PKIoverheid cert (digitale handtekening)
5. Submit to Digipoort (via openconnector SOAP binding, future T4)
6. Record the Digipoort ontvangstbevestiging-ID

**Rationale**: Becon-route is the standard for tax professionals. Fiscalists manage 50–500 clients; bulk-approval + direct Digipoort submission is essential.

### D12 — Correctieaangifte: amendment aangifte with diff-tracking and delta teruggave/betaling

**Decision**: If a ZZP'er (or accountant) discovers an error post-filing, the system allows a "Correctieaangifte starten" action. A new `IBAangifte` record is created with `aangifteType = CORRECTIE_SUPPLETIE` and fields pre-populated from the prior-year version. The ZZP'er edits the error fields. The system:
1. Calculates deltas (e.g., −EUR 2.400 aftrek → +EUR 882 teruggave)
2. Generates a correctiondiff rapport
3. Signs + files via Digipoort as a suppletie (supplementary return)
4. Tracks the correction link back to the original aangifte (`vorige-aangifte-id`)

**Rationale**: Corrections are common (forgotten lijfrentepremies, late investeringsaftrek, uren-tracker dispute). Diff-tracking makes the process transparent.

## Seed Data

Example entities per fiscal year 2025:

### 1. IBAangifte (primary record)
```json
{
  "id": "ib-aangifte-2025-ond-nl-998765",
  "ondernemingId": "ond-nl-998765",
  "bsn": "654321098",
  "belastingjaar": 2025,
  "status": "GEVALIDEERD",
  "indieningKanaal": "DIGID_ZELFSERVICE",
  "aangifteType": "P_FORMULIER",
  "winstUitOnderneming": 47820.00,
  "ondernemersaftrek": 5616.00,
  "mkbWinstvrijstelling": 5618.05,
  "belastbareWinst": 36585.95,
  "totaalBox1Inkomen": 36585.95,
  "totaalBox3Inkomen": 1820.00,
  "verschuldigdeIB": 9420.18,
  "heffingskortingen": 3950.40,
  "teBetalenOfTeOntvangen": 5469.78,
  "ingediendOp": null,
  "xbrlInstanceId": "xbrl-2025-ond-998765-v1",
  "auditTrailId": "audit-ib-2025-ond-998765"
}
```

### 2. IBWinstOpgave (profit detail)
```json
{
  "id": "ib-winst-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "omzetExclusiefBtw": 82400.00,
  "kostprijsOmzet": 12300.00,
  "brutoWinst": 70100.00,
  "afschrijvingen": 4800.00,
  "huisvestingskosten": 6200.00,
  "autokosten": {
    "totaal": 8400.00,
    "bijtellingPrive": 3960.00,
    "aftrekbaar": 4440.00
  },
  "kantoorkosten": 1100.00,
  "algemeneKosten": 2820.00,
  "nietAftrekbaarBoetes": 180.00,
  "representatieCorrectie": 320.00,
  "winstVoorOndernemersaftrek": 47820.00,
  "fiscaleAfwijkingenLog": [
    {
      "post": "REPRESENTATIE_DREMPEL",
      "bedrag": -320.00,
      "grondslag": "art. 3.15 Wet IB 2001, max 5% winst"
    }
  ]
}
```

### 3. IBOndernemersaftrek (deductions)
```json
{
  "id": "ib-onda-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "urencriterium": {
    "behaald": true,
    "uren": 1462,
    "drempel": 1225,
    "evidenceRef": "uren-tracker-2025-ond-998765"
  },
  "zelfstandigenaftrek": {
    "toegestaan": true,
    "bedrag": 2470.00,
    "grondslag": "art. 3.76 Wet IB 2001, tarief 2025"
  },
  "startersaftrek": {
    "toegestaan": false,
    "bedrag": 0.00
  },
  "mkbWinstvrijstelling": 5618.05,
  "totaalAftrek": 8088.05
}
```

### 4. IBHeffingskortingenAlgemeen (tax credits)
```json
{
  "id": "ib-heff-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "algemeneHeffingskorting": 2890.00,
  "arbeidskorting": 1060.40,
  "iack": 0.00,
  "ouderenkorting": 0.00,
  "totaalHeffingskortingen": 3950.40
}
```

### 5. IBLijfrenteAOV (pension savings)
```json
{
  "id": "ib-lijfrente-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "jaarruimte2025": {
    "berekend": 6280.00,
    "benut": 4800.00,
    "resterend": 1480.00
  },
  "aovPremies": {
    "bedrag": 2400.00,
    "polisnummer": "AOV-MOVIR-789123"
  },
  "totaalAftrekbaar": 7200.00
}
```

### 6. IBBijtellingAuto (car taxation)
```json
{
  "id": "ib-bijtelling-auto-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "kenteken": "12-ABC-3",
  "bijtellingBedrag": 8360.00,
  "bijtellingsPct": 0.22
}
```

### 7. IBBox3Vermogen (savings/investments)
```json
{
  "id": "ib-box3-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "peildatum": "2025-01-01",
  "bankEnSpaartegoeden": 28000.00,
  "overigeBezittingen": 41000.00,
  "schulden": 0.00,
  "totaalRendementsgrondslag": 69000.00,
  "belastbareGrondslag": 12000.00,
  "berekendRendement": {
    "methode": "FORFAIT_OVERBRUGGINGSWET",
    "rendement": 1820.00
  }
}
```

### 8. IBAuditTrail (herleidbaarheid)
```json
{
  "id": "audit-ib-2025-ond-998765",
  "aangifteId": "ib-aangifte-2025-ond-nl-998765",
  "regels": [
    {
      "rubriek": "omzet_excl_btw",
      "waarde": 82400.00,
      "bron": "GL_ACCOUNT_8000-8099",
      "journaalposten": ["jp-2025-01-001", "jp-2025-01-002", "jp-2025-02-001"],
      "berekendOp": "2026-03-15T10:24:18Z"
    },
    {
      "rubriek": "zelfstandigenaftrek",
      "waarde": 2470.00,
      "bron": "uren-tracker-2025-ond-998765",
      "berekendOp": "2026-03-15T10:24:18Z"
    }
  ],
  "totalRegels": 247,
  "freezeMoment": "2026-03-15T10:24:18Z"
}
```
