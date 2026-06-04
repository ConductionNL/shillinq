# Design — Titel 9 Jaarrekening Generation

## Decisions

### D1 — Groottecategorie is a computed classification, not operator-selectable

Per art. 2:395a–398 BW, groottecategorie (micro, klein, middelgroot, groot) is determined by a two-of-three criterion over two consecutive fiscal years (balanstotaal, netto-omzet, gemiddeld aantal werknemers). The system MUST compute this automatically from bron-administratie and display the classification with underpinning numerics. An operator may override the computed category with documented justification (e.g., voluntary disclosure by small entity), but the default is automated per law.

**Alternative considered**: Operator manually assigns groottecategorie. Rejected — high compliance risk; law mandates algorithmic determination; system must enforce.

### D2 — Jaarrekening is a versioned snapshot, not live-updating after signature

Before opmaak (bestuur signature), the jaarrekening concept is real-time: any change to source GL lines re-calculates BalanceSheet/IncomeStatement summaries. Once bestuur marks "Opgemaakt" (signed + immutably snapshot), the jaarrekening becomes read-only; post-signature corrections are recorded as foutherstel (error correction) entries per art. 2:389 BW.

**Alternative considered**: Post-signature corrections are editable if marked as amendments. Rejected — Dutch law treats post-vaststelling corrections strictly; audit trail must be clear; immutability after signature is enforced.

### D3 — Wettelijke rubrieken are first-class data, not derived from chart-of-accounts order

The exact rubriek codes (B.I.1 Immateriële vaste activa, B.II.1 Materiële vaste activa, C.I Voorraden, etc.) from art. 2:373 BW and RJ 240 are stored as schema fields on BalanceSheet/IncomeStatement. Mapping from operator's rekeningschema (GL account number → wettelijke rubriek) is configuration stored in seeds, not hardcoded in PHP.

**Alternative considered**: Derive rubrieken from GL account order / numbering. Rejected — operators use various chart-of-accounts structures; hard-coded mapping is brittle; configuration-as-data is flexible and auditable.

### D4 — Toelichting-templates are keyed to groottecategorie + RJ-guideline, not free-form

Each toelichting-paragraaf (note) is declared as a template record: RJ guideline name (e.g., "RJ 240: Eigen Vermogen"), groottecategorie applicability (required for middelgroot+, optional for klein), content-schema (structured fields for MVA-verloop, EV-mutation, debt schedule). Operators fill in data; templates auto-generate narrative and tables.

**Alternative considered**: Free-form toelichting authored by operator. Rejected — mandatory toelichting has wettelijke structure; templates ensure compliance; auto-generation from GL data reduces operator effort.

### D5 — Kasstroomoverzicht uses indirect method + aggregations from GL, not separate ledger

The cash flow statement is generated using the indirect method (nettoresultaat + afschrijvingen + mutaties werkkapitaal) from the GL, not a separate cash ledger. Mutaties (changes in payables, receivables, inventory) are computed from BalanceSheet comparisons (current year vs. prior year).

**Alternative considered**: Maintain a separate cash-posting ledger for direct method. Rejected — complex to maintain; indirect method is standard for SMEs; GL aggregations suffice.

### D6 — ReviewWorkflow is a strict linear state machine, not a comment-only review

The jaarrekening flows through ReviewWorkflow states: `concept` → `in-review` (accountant) → `vastgesteld` (AV assembly approval) → `gedeponeerd` (KVK filed). During `in-review`, the bestuur cannot edit without cancelling review. Review comments are immutable; changes are logged per comment.

**Alternative considered**: Accountant comments are advisory; bestuur edits anytime. Rejected — audit trail requirements (art. 2:389 BW); accountant must be able to trace final state to review sign-off.

### D7 — Bestuursverslag sections include auto-generated content placeholders but are operator-authored

The bestuursverslag (director's report, art. 2:391 BW) template includes sections: algemeen, financiële gang van zaken, risico's, toekomstparagraaf, personeel, milieu, R&D (optional), ESG (optional for groot). The system provides auto-filled summary (e.g., revenue YoY delta, headcount average) and empty sections for operator narrative entry. No auto-generation of risk analysis or future outlook.

**Alternative considered**: Full auto-generation of bestuursverslag. Rejected — risk/outlook/strategy are management judgment; operator must author; system provides data context.

### D8 — DirectorReport is a separate entity, not embedded in AnnualReport

The bestuursverslag (director's report) is a separate `DirectorReport` entity linked to `AnnualReport`, not an embedded JSON block. This allows independent versioning, section-level editing, and signature workflows.

**Alternative considered**: Bestuursverslag as JSONB field on AnnualReport. Rejected — separate document per law; separate signatures required; schema separation is cleaner.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Groottecategorie classification logic | None (new logic) | Declarative configuration seed with two-of-three criteria evaluation |
| Balans/V&W structure per art. 2:373 & 2:377 | None (new) | Schema structure follows wettelijke rubriek hierarchy; mapping config from GL to rubrieken |
| Toelichting-templates keyed to RJ guidelines | None (new) | Template registry seeded per RJ guideline + groottecategorie applicability |
| Kasstroomoverzicht (RJ 350) | None (new) | Aggregation rules from GL; indirect method template |
| Bestuursverslag sections (art. 2:391) | None (new) | Manifest form for section entry; template provides placeholders |
| Accountant review workflow | None (new) | `x-openregister-lifecycle` state transitions (concept → in-review → vastgesteld → gedeponeerd); audit-trail-immutable on each state change |
| Jaarrekening versioning & snapshots | OR immutable snapshots | Snapshot creation at opmaak; post-signature corrections via foutherstel entries (separate audit trail) |
| RBAC for bestuur vs. accountant access | OR RBAC system | Bestuur role: edit jaarrekening, sign opmaak; Accountant role: read-only on GL, edit review items, attach verklaring |
| SBR-XBRL conversion | bookkeeping-sbr-xbrl-reporting (T3 module) | This module provides final AnnualReport snapshot; XBRL module consumes it |
| GL aggregation to balans/V&W rubrieken | bookkeeping-financial-statements (T1 output) | T1 provides per-rubriek aggregated balans/V&W; this module structures into statutory form |

**Net new schemas in implementation cycle**: 6 register declarations (`AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`, `ReviewWorkflow`) + groottecategorie classification config + rubriek-mapping seeds + RJ toelichting-template registry. No new PHP service classes (generation logic is templated, not procedural).

## Seed Data

Seeds live under `lib/Settings/seeds/` and include:

### 1. Groottecategorie Classification Configuration

**File**: `lib/Settings/seeds/groottecategorie-classification.json`

Defines the two-of-three criterion thresholds per art. 2:395a–398 BW:

```json
{
  "_meta": {
    "source": "shillinq-default",
    "variant": "titel-9-groottecategorie",
    "imported": "2026-05-22T00:00:00Z"
  },
  "categories": [
    {
      "name": "micro",
      "thresholds": {
        "balansTotaal": 450000,
        "nettoOmzet": 900000,
        "gemiddeldAantalWerknemers": 10
      }
    },
    {
      "name": "klein",
      "thresholds": {
        "balansTotaal": 12000000,
        "nettoOmzet": 24000000,
        "gemiddeldAantalWerknemers": 50
      }
    },
    {
      "name": "middelgroot",
      "thresholds": {
        "balansTotaal": 25000000,
        "nettoOmzet": 50000000,
        "gemiddeldAantalWerknemers": 250
      }
    }
  ]
}
```

### 2. Balans Rubriek Mapping (Example for a Small Manufacturing BV)

**File**: `lib/Settings/seeds/balans-rubriek-mapping.json`

Maps GL account numbers to wettelijke rubriek codes:

```json
{
  "_meta": {
    "source": "shillinq-default",
    "variant": "small-manufacturing",
    "imported": "2026-05-22T00:00:00Z"
  },
  "mappings": [
    {
      "glAccountRange": "1000-1099",
      "label": "Immateriële vaste activa",
      "rubrieckCode": "B.I",
      "rubrieckLabel": "Immateriële vaste activa"
    },
    {
      "glAccountRange": "1100-1299",
      "label": "Materiële vaste activa",
      "rubrieckCode": "B.II",
      "rubrieckLabel": "Materiële vaste activa"
    },
    {
      "glAccountRange": "1300-1399",
      "label": "Financiële vaste activa",
      "rubrieckCode": "B.III",
      "rubrieckLabel": "Financiële vaste activa"
    },
    {
      "glAccountRange": "1400-1499",
      "label": "Voorraden",
      "rubrieckCode": "C.I",
      "rubrieckLabel": "Voorraden"
    },
    {
      "glAccountRange": "1500-1599",
      "label": "Vorderingen",
      "rubrieckCode": "C.II",
      "rubrieckLabel": "Vorderingen"
    },
    {
      "glAccountRange": "1600-1699",
      "label": "Liquide middelen",
      "rubrieckCode": "C.IV",
      "rubrieckLabel": "Liquide middelen"
    }
  ]
}
```

### 3. Toelichting Template Registry (RJ Guidelines)

**File**: `lib/Settings/seeds/toelichting-templates.json`

Defines mandatory and optional toelichting-sections per groottecategorie:

```json
{
  "_meta": {
    "source": "shillinq-default",
    "variant": "rj-guidelines-2024",
    "imported": "2026-05-22T00:00:00Z"
  },
  "templates": [
    {
      "rjGuideline": "RJ 240",
      "sectionName": "Grondslagen voor waardering en resultaatbepaling",
      "applicableFor": ["micro", "klein", "middelgroot", "groot"],
      "required": true,
      "contentType": "rich-text",
      "fields": ["valuationBasis", "resultDetermination", "exceptions"]
    },
    {
      "rjGuideline": "RJ 212",
      "sectionName": "Materiële vaste activa",
      "applicableFor": ["klein", "middelgroot", "groot"],
      "required": false,
      "contentType": "table",
      "fields": ["acquisitionValue", "depreciationMethods", "depreciationRates", "mutations"]
    },
    {
      "rjGuideline": "RJ 240",
      "sectionName": "Mutaties eigen vermogen",
      "applicableFor": ["klein", "middelgroot", "groot"],
      "required": true,
      "contentType": "matrix",
      "fields": ["equityComponents", "openingBalance", "resultAppropriations", "closingBalance"]
    },
    {
      "rjGuideline": "RJ 250",
      "sectionName": "Schulden",
      "applicableFor": ["middelgroot", "groot"],
      "required": true,
      "contentType": "table",
      "fields": ["debtType", "amount", "interestRate", "maturityDate", "securityType"]
    },
    {
      "rjGuideline": "Art. 2:391",
      "sectionName": "Nietvermelde verplichtingen",
      "applicableFor": ["klein", "middelgroot", "groot"],
      "required": true,
      "contentType": "narrative",
      "fields": ["operationalLeases", "contingencies", "parentGuarantees"]
    }
  ]
}
```

### 4. Example Annual Report Snapshots (for Testing)

**Specimen 1: Small BV (Micro category)**

```json
{
  "administrationId": "adm-001",
  "boekjaarStart": "2025-01-01",
  "boekjaarEind": "2025-12-31",
  "groottecategorie": "micro",
  "groottecategorieOnderbouwing": {
    "balansTotaal": 380000,
    "nettoOmzet": 750000,
    "gemiddeldAantalWerknemers": 6,
    "criteriaMatched": 3,
    "years": ["2024", "2025"]
  },
  "rapportageGrondslag": "RJ-commercieel",
  "valuta": "EUR",
  "status": "concept",
  "opmaakDatum": null,
  "vaststellingDatum": null,
  "depositeringDatum": null
}
```

**Specimen 2: Medium BV (Klein category)**

```json
{
  "administrationId": "adm-002",
  "boekjaarStart": "2025-01-01",
  "boekjaarEind": "2025-12-31",
  "groottecategorie": "klein",
  "groottecategorieOnderbouwing": {
    "balansTotaal": 10000000,
    "nettoOmzet": 18000000,
    "gemiddeldAantalWerknemers": 35,
    "criteriaMatched": 2,
    "years": ["2024", "2025"]
  },
  "rapportageGrondslag": "RJ-commercieel",
  "valuta": "EUR",
  "status": "concept",
  "opmaakDatum": null,
  "vaststellingDatum": null,
  "depositeringDatum": null,
  "accountantsverklaringVereist": false
}
```

**Specimen 3: Large BV (Middelgroot category)**

```json
{
  "administrationId": "adm-003",
  "boekjaarStart": "2025-01-01",
  "boekjaarEind": "2025-12-31",
  "groottecategorie": "middelgroot",
  "groottecategorieOnderbouwing": {
    "balansTotaal": 20000000,
    "nettoOmzet": 45000000,
    "gemiddeldAantalWerknemers": 150,
    "criteriaMatched": 2,
    "years": ["2024", "2025"]
  },
  "rapportageGrondslag": "RJ-commercieel",
  "valuta": "EUR",
  "status": "concept",
  "opmaakDatum": null,
  "vaststellingDatum": null,
  "depositeringDatum": null,
  "accountantsverklaringVereist": true,
  "kasstroomoverzichtVereist": true,
  "bestuursledenCount": 2,
  "aandeelhoudersJSONB": {}
}
```

### 5. Balans Example (Micro BV)

```json
{
  "reportId": "annual-report-adm-001-2025",
  "balansDate": "2025-12-31",
  "totalActiva": 845000,
  "totalPassiva": 845000,
  "currency": "EUR",
  "status": "draft",
  "rubrieken": [
    {
      "rubrieckCode": "B.I",
      "rubrieckLabel": "Immateriële vaste activa",
      "huidigJaar": 120000,
      "vorigJaar": 130000,
      "notes": ["Note 1.1"]
    },
    {
      "rubrieckCode": "B.II",
      "rubrieckLabel": "Materiële vaste activa",
      "huidigJaar": 450000,
      "vorigJaar": 500000,
      "notes": ["Note 1.2"]
    },
    {
      "rubrieckCode": "C.I",
      "rubrieckLabel": "Voorraden",
      "huidigJaar": 0,
      "vorigJaar": 0,
      "notes": []
    },
    {
      "rubrieckCode": "C.II",
      "rubrieckLabel": "Vorderingen",
      "huidigJaar": 180000,
      "vorigJaar": 160000,
      "notes": ["Note 2.1"]
    },
    {
      "rubrieckCode": "C.IV",
      "rubrieckLabel": "Liquide middelen",
      "huidigJaar": 95000,
      "vorigJaar": 85000,
      "notes": []
    },
    {
      "rubrieckCode": "A",
      "rubrieckLabel": "Eigen vermogen",
      "huidigJaar": 380000,
      "vorigJaar": 370000,
      "notes": ["Note 3.1"]
    },
    {
      "rubrieckCode": "B",
      "rubrieckLabel": "Voorzieningen",
      "huidigJaar": 45000,
      "vorigJaar": 50000,
      "notes": ["Note 3.2"]
    },
    {
      "rubrieckCode": "C",
      "rubrieckLabel": "Langlopende schulden",
      "huidigJaar": 280000,
      "vorigJaar": 300000,
      "notes": ["Note 3.3"]
    },
    {
      "rubrieckCode": "D",
      "rubrieckLabel": "Kortlopende schulden",
      "huidigJaar": 140000,
      "vorigJaar": 130000,
      "notes": ["Note 3.4"]
    }
  ]
}
```

### 6. Winst-en-Verliesrekening Example (Micro BV, Model A)

```json
{
  "reportId": "annual-report-adm-001-2025",
  "vwDate": "2025-12-31",
  "model": "A-categorisch",
  "nettoresultaat": 266000,
  "currency": "EUR",
  "status": "draft",
  "rubrieken": [
    {
      "position": 1,
      "rubrieckCode": "1",
      "label": "Netto-omzet",
      "huidigJaar": 1500000,
      "vorigJaar": 1400000,
      "notes": ["Note 4.1"]
    },
    {
      "position": 2,
      "rubrieckCode": "2",
      "label": "Wijziging voorraden",
      "huidigJaar": 50000,
      "vorigJaar": 40000,
      "notes": []
    },
    {
      "position": 3,
      "rubrieckCode": "3",
      "label": "Geactiveerde productie",
      "huidigJaar": 0,
      "vorigJaar": 0,
      "notes": []
    },
    {
      "position": 4,
      "rubrieckCode": "4",
      "label": "Overige bedrijfsopbrengsten",
      "huidigJaar": 25000,
      "vorigJaar": 20000,
      "notes": []
    },
    {
      "position": "Subtotaal",
      "label": "Bedrijfsopbrengsten",
      "huidigJaar": 1575000,
      "vorigJaar": 1460000
    },
    {
      "position": 5,
      "rubrieckCode": "5",
      "label": "Kosten grond- en hulpstoffen",
      "huidigJaar": 420000,
      "vorigJaar": 390000,
      "notes": []
    },
    {
      "position": 6,
      "rubrieckCode": "6",
      "label": "Lonen en salarissen",
      "huidigJaar": 380000,
      "vorigJaar": 360000,
      "notes": []
    },
    {
      "position": 7,
      "rubrieckCode": "7",
      "label": "Sociale lasten en pensioenen",
      "huidigJaar": 95000,
      "vorigJaar": 90000,
      "notes": []
    },
    {
      "position": 8,
      "rubrieckCode": "8",
      "label": "Afschrijvingen",
      "huidigJaar": 80000,
      "vorigJaar": 80000,
      "notes": ["Note 4.2"]
    },
    {
      "position": 9,
      "rubrieckCode": "9",
      "label": "Overige bedrijfskosten",
      "huidigJaar": 250000,
      "vorigJaar": 230000,
      "notes": []
    },
    {
      "position": "Subtotaal",
      "label": "Bedrijfslasten",
      "huidigJaar": 1225000,
      "vorigJaar": 1150000
    },
    {
      "position": "Bedrijfsresultaat",
      "huidigJaar": 350000,
      "vorigJaar": 310000
    },
    {
      "position": 10,
      "rubrieckCode": "10",
      "label": "Rentebaten",
      "huidigJaar": 4000,
      "vorigJaar": 5000,
      "notes": []
    },
    {
      "position": 11,
      "rubrieckCode": "11",
      "label": "Rentelasten",
      "huidigJaar": -18000,
      "vorigJaar": -20000,
      "notes": []
    },
    {
      "position": "Resultaat voor belasting",
      "huidigJaar": 336000,
      "vorigJaar": 295000
    },
    {
      "position": 12,
      "rubrieckCode": "12",
      "label": "Belastingen",
      "huidigJaar": -70000,
      "vorigJaar": -62000,
      "notes": ["Note 4.3"]
    },
    {
      "position": "Netto resultaat",
      "huidigJaar": 266000,
      "vorigJaar": 233000
    }
  ]
}
```

All seed data files include:
- **SPDX header** (`EUPL-1.2 + Copyright Shillinq BV`) per company ADR-005/feedback-spdx
- **`_meta` block** with source, variant, and imported timestamp
- **Commented example values** for clarity
